<?php

namespace App\Services;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Ejercicio;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Support\Formato;
use App\Support\Parametros;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Motor contable (partida doble, PGC-PYMES).
 * - Cada asiento cuadra (debe = haber) y se numera correlativamente por ejercicio.
 * - Las facturas emitidas y recibidas se contabilizan con plantillas fijas.
 * - El cierre genera regularización (grupos 6 y 7 → 129) y cierre, y abre el
 *   ejercicio siguiente con el asiento de apertura.
 */
class Contabilidad
{
    /** 472 → 472000000 · 4751 → 475100000 · 400000001 se queda igual */
    public static function a9(string $c): string
    {
        $c = preg_replace('/\D/', '', $c);

        return strlen($c) >= 9 ? substr($c, 0, 9) : str_pad($c, 9, '0');
    }

    /** Nombre de una cuenta: subcuenta propia o, si no, la cuenta del PGC más específica */
    public static function nombre(string $c9, ?Collection $propias = null): ?string
    {
        if ($propias && ($n = $propias->get($c9))) {
            return $n;
        }
        $pgc = config('pgc');
        for ($l = 4; $l >= 3; $l--) {
            $k = substr($c9, 0, $l);
            if (isset($pgc[$k]) && (strlen(rtrim($c9, '0')) <= $l || $l === 4 || ! isset($pgc[substr($c9, 0, 4)]))) {
                return $pgc[$k];
            }
        }

        return null;
    }

    public static function existeEnPgc(string $c9): bool
    {
        return isset(config('pgc')[substr($c9, 0, 3)]);
    }

    public static function propias(Cliente $c): Collection
    {
        $m = Cuenta::where('cliente_id', $c->id)->where('oculta', false)->pluck('nombre', 'codigo');
        foreach (Tercero::where('cliente_id', $c->id)->get(['cuenta', 'nombre']) as $t) {
            $m[$t->cuenta] = $t->nombre;
        }

        return $m;
    }

    /** Cuentas del PGC que la empresa ha dado de baja */
    public static function ocultas(Cliente $c): array
    {
        return Cuenta::where('cliente_id', $c->id)->where('oculta', true)->pluck('codigo')->map(fn ($x) => (string) $x)->all();
    }

    /** Subcuentas 572… de las cuentas bancarias de la ficha de la empresa (Datos bancarios) */
    public static function sincronizarBancos(Cliente $c): void
    {
        foreach ($c->seccion('bancos') as $b) {
            $iban = strtoupper(preg_replace('/\s+/', '', (string) ($b['iban'] ?? '')));
            if ($iban === '' || Cuenta::where('cliente_id', $c->id)->where('iban', $iban)->exists()) {
                continue;
            }
            $max = Cuenta::where('cliente_id', $c->id)->where('codigo', 'like', '572%')->where('codigo', '!=', '572000000')->max('codigo');
            $cod = $max ? str_pad((string) ((int) $max + 1), 9, '0', STR_PAD_LEFT) : '572000001';
            $ent = trim((string) ($b['entidad'] ?? '')) ?: 'Cuenta';
            Cuenta::create(['cliente_id' => $c->id, 'codigo' => $cod, 'nombre' => 'Bancos · '.$ent.' · ****'.substr($iban, -4), 'iban' => $iban]);
        }
    }

    public static function cuentaPar(string $k): string
    {
        return self::a9((string) Parametros::get('contabilidad')[$k]);
    }

    // ------------------------------------------------------------ ejercicios
    public function ejercicio(Cliente $c, int $anio, bool $crear = true): ?Ejercicio
    {
        $e = Ejercicio::where('cliente_id', $c->id)->where('anio', $anio)->first();

        return $e ?? ($crear ? Ejercicio::create(['cliente_id' => $c->id, 'anio' => $anio, 'estado' => 'Abierto']) : null);
    }

    // --------------------------------------------------------------- asientos
    /**
     * Crea (o sustituye) un asiento. $lineas = [[cuenta, debe, haber, concepto?], ...]
     */
    public function asentar(Cliente $c, string $fecha, string $concepto, array $lineas, string $tipo = 'Manual', ?string $origen = null, ?int $origenId = null, ?int $userId = null, ?Asiento $sustituir = null): Asiento
    {
        $f = CarbonImmutable::parse($fecha);
        $ej = $this->ejercicio($c, (int) $f->format('Y'));
        if ($ej->cerrado()) {
            throw new InvalidArgumentException('El ejercicio '.$ej->anio.' está cerrado: no admite asientos.');
        }
        if ($ej->bloqueo && $f->lt($ej->bloqueo) && ! in_array($tipo, ['Apertura', 'Regularización', 'Cierre'], true)) {
            throw new InvalidArgumentException('No se pueden registrar asientos con fecha anterior al '.Formato::fecha($ej->bloqueo).' (fecha de bloqueo).');
        }
        $ls = collect($lineas)->map(fn ($l) => ['cuenta' => self::a9((string) $l[0]), 'debe' => round((float) $l[1], 2), 'haber' => round((float) $l[2], 2), 'concepto' => $l[3] ?? null, 'documento' => isset($l[4]) && $l[4] !== '' ? mb_substr((string) $l[4], 0, 40) : null])
            ->filter(fn ($l) => $l['debe'] != 0 || $l['haber'] != 0)->values();
        $ocultas = self::ocultas($c);
        foreach ($ls as $l) {
            if (in_array($l['cuenta'], $ocultas, true)) {
                throw new InvalidArgumentException('La cuenta '.$l['cuenta'].' está dada de baja en el PGC de la empresa. Reactívala en Contabilidad › PGC.');
            }
        }
        if ($ls->count() < 2) {
            throw new InvalidArgumentException('Un asiento necesita al menos dos apuntes con importe.');
        }
        foreach ($ls as $l) {
            if (! self::existeEnPgc($l['cuenta'])) {
                throw new InvalidArgumentException('La cuenta '.$l['cuenta'].' no existe en el plan de cuentas.');
            }
            if ($l['debe'] != 0 && $l['haber'] != 0) {
                throw new InvalidArgumentException('Cada apunte va al debe o al haber, no a los dos.');
            }
        }
        $d = round($ls->sum('debe'), 2);
        $h = round($ls->sum('haber'), 2);
        if (abs($d - $h) > 0.004) {
            throw new InvalidArgumentException('El asiento no cuadra: debe '.Formato::eur($d).' y haber '.Formato::eur($h).'.');
        }

        return DB::transaction(function () use ($c, $ej, $f, $concepto, $ls, $tipo, $origen, $origenId, $userId, $sustituir) {
            if ($sustituir) {
                $sustituir->apuntes()->delete();
                $sustituir->update(['fecha' => $f->toDateString(), 'concepto' => $concepto]);
                $a = $sustituir;
            } else {
                $n = (int) Asiento::where('ejercicio_id', $ej->id)->lockForUpdate()->max('numero') + 1;
                $a = Asiento::create(['cliente_id' => $c->id, 'ejercicio_id' => $ej->id, 'numero' => $n, 'fecha' => $f->toDateString(), 'concepto' => mb_substr($concepto, 0, 255),
                    'tipo' => $tipo, 'origen' => $origen, 'origen_id' => $origenId, 'created_by' => $userId]);
            }
            foreach ($ls as $i => $l) {
                Apunte::create(['asiento_id' => $a->id, 'cliente_id' => $c->id, 'orden' => $i, 'cuenta' => $l['cuenta'], 'concepto' => $l['concepto'], 'documento' => $l['documento'], 'debe' => $l['debe'], 'haber' => $l['haber']]);
            }

            return $a;
        });
    }

    public function borrar(Asiento $a): void
    {
        if ($a->ejercicio->cerrado()) {
            throw new InvalidArgumentException('El ejercicio está cerrado.');
        }
        DB::transaction(function () use ($a) {
            if ($a->origen === 'factura') {
                Factura::whereKey($a->origen_id)->where('asiento_id', $a->id)->get()->each(fn ($f) => $f->update(['asiento_id' => null]));
            }
            if ($a->origen === 'recibida') {
                FacturaRecibida::whereKey($a->origen_id)->where('asiento_id', $a->id)->update(['asiento_id' => null]);
            }
            if ($a->origen === 'cobro') {
                Factura::whereKey($a->origen_id)->where('cobro_asiento_id', $a->id)->get()->each(fn ($f) => $f->update(['cobro_asiento_id' => null]));
            }
            if ($a->origen === 'pago') {
                FacturaRecibida::whereKey($a->origen_id)->where('pago_asiento_id', $a->id)->update(['pago_asiento_id' => null]);
            }
            $a->delete();
        });
    }

    // ---------------------------------------------------- subcuentas de clientes
    public function cuentaCliente(Cliente $c, ?string $nif, ?string $nombre): string
    {
        $nif = $nif ? strtoupper($nif) : null;
        if ($nif && ($x = Cuenta::where('cliente_id', $c->id)->where('codigo', 'like', '430%')->where('nif', $nif)->first())) {
            return $x->codigo;
        }
        // Cliente dado de alta en Ventas con su subcuenta 430: se usa esa
        if ($nif && ($t = Tercero::where('cliente_id', $c->id)->where('clase', 'cliente')->where('nif', $nif)->first())) {
            if (! Cuenta::where('cliente_id', $c->id)->where('codigo', $t->cuenta)->exists()) {
                Cuenta::create(['cliente_id' => $c->id, 'codigo' => $t->cuenta, 'nombre' => $t->nombre, 'nif' => $nif]);
            }

            return $t->cuenta;
        }
        if (! $nif && ! $nombre) {
            return '430000000';
        }
        $max = Cuenta::where('cliente_id', $c->id)->where('codigo', 'like', '430%')->max('codigo');
        $cod = $max ? str_pad((string) ((int) $max + 1), 9, '0', STR_PAD_LEFT) : '430000001';
        Cuenta::create(['cliente_id' => $c->id, 'codigo' => $cod, 'nombre' => $nombre ?: $nif, 'nif' => $nif]);

        return $cod;
    }

    // ------------------------------------------------- contabilizar facturas
    public function contabilizarEmitida(Factura $f, ?int $userId = null): Asiento
    {
        if ($f->asiento_id) {
            throw new InvalidArgumentException('La factura '.$f->num.' ya está contabilizada.');
        }
        $c = Cliente::findOrFail($f->cliente_id);
        $cli = $this->cuentaCliente($c, $f->receptor_nif, $f->receptor_nombre);
        $ls = [[$cli, (float) $f->total, 0, $f->receptor_nombre]];
        if ((float) $f->retencion) {
            $ls[] = [self::cuentaPar('ret_soportada'), (float) $f->retencion, 0];
        }
        $ls[] = [self::cuentaPar('ventas'), 0, (float) $f->base];
        if ((float) $f->cuota) {
            $ls[] = [self::cuentaPar('iva_repercutido'), 0, (float) $f->cuota];
        }
        if ((float) $f->suplidos) {
            $ls[] = [self::cuentaPar('suplidos'), 0, (float) $f->suplidos, 'Suplidos'];
        }
        // Rectificativas con importes negativos: se pasan al lado contrario
        $ls = array_map(fn ($l) => $l[1] < 0 || $l[2] < 0 ? [$l[0], -$l[2], -$l[1], $l[3] ?? null] : $l, $ls);
        $a = $this->asentar($c, $f->fecha->toDateString(), 'Fra. emitida '.$f->num.' · '.($f->receptor_nombre ?: 'simplificada'), $ls, 'Factura emitida', 'factura', $f->id, $userId);
        $f->update(['asiento_id' => $a->id]);

        return $a;
    }

    public function cuentaGasto(FacturaRecibida $f): string
    {
        if ($f->clase === 'compra') {
            return self::cuentaPar('compras');
        }

        return self::cuentaPar(match ($f->tercero?->tipo) {
            'Profesional' => 'gasto_profesional',
            'Arrendador' => 'gasto_arrendamiento',
            default => 'gasto_servicios',
        });
    }

    public function contabilizarRecibida(FacturaRecibida $f, ?int $userId = null, ?string $cuentaGasto = null): Asiento
    {
        if ($f->asiento_id) {
            throw new InvalidArgumentException('La factura '.$f->num.' ya está contabilizada.');
        }
        $c = Cliente::findOrFail($f->cliente_id);
        $t = $f->tercero;
        $gasto = $cuentaGasto ? self::a9($cuentaGasto) : $this->cuentaGasto($f);
        $baseGasto = (float) $f->base + ($f->deducible ? 0 : (float) $f->cuota);
        $ls = [[$gasto, $baseGasto, 0, $f->concepto]];
        if ($f->deducible && (float) $f->cuota) {
            $ls[] = [self::cuentaPar('iva_soportado'), (float) $f->cuota, 0];
        }
        if ((float) $f->retencion) {
            $ls[] = [self::cuentaPar('ret_practicada'), 0, (float) $f->retencion];
        }
        $ls[] = [$t->cuenta, 0, (float) $f->total, $t->nombre];
        $ls = array_map(fn ($l) => $l[1] < 0 || $l[2] < 0 ? [$l[0], -$l[2], -$l[1], $l[3] ?? null] : $l, $ls);
        $a = $this->asentar($c, $f->fecha->toDateString(), 'Fra. '.$f->num.' · '.$t->nombre, $ls, 'Factura recibida', 'recibida', $f->id, $userId);
        $f->update(['asiento_id' => $a->id]);

        return $a;
    }

    // ------------------------------------------------- cobros y pagos
    public const MEDIOS = ['banco' => 'Banco', 'caja' => 'Caja'];

    /** Cuenta de tesorería del medio: banco (572) o caja (570) */
    public static function cuentaMedio(string $medio): string
    {
        return self::cuentaPar($medio === 'caja' ? 'caja' : 'banco');
    }

    /** Asiento del cobro de una factura emitida: banco/caja al debe, cliente (430…) al haber. La contabiliza antes si hace falta. */
    public function contabilizarCobro(Factura $f, string $medio, ?int $userId = null, ?string $cuentaTes = null): Asiento
    {
        if ($f->cobro_asiento_id) {
            throw new InvalidArgumentException('El cobro de la factura '.$f->num.' ya está contabilizado.');
        }
        if (! $f->cobrada || ! $f->cobrada_at) {
            throw new InvalidArgumentException('Marca antes la factura '.$f->num.' como cobrada.');
        }

        return DB::transaction(function () use ($f, $medio, $userId, $cuentaTes) {
            if (! $f->asiento_id) {
                $this->contabilizarEmitida($f, $userId);
                $f->refresh();
            }
            $c = Cliente::findOrFail($f->cliente_id);
            $cli = Apunte::where('asiento_id', $f->asiento_id)->where('cuenta', 'like', '430%')->value('cuenta') ?? $this->cuentaCliente($c, $f->receptor_nif, $f->receptor_nombre);
            $imp = round((float) $f->total, 2);
            $tes = $cuentaTes ?: self::cuentaMedio($medio);
            $ls = $imp >= 0 ? [[$tes, $imp, 0, 'Cobro fra. '.$f->num], [$cli, 0, $imp, $f->receptor_nombre]]
                : [[$cli, -$imp, 0, $f->receptor_nombre], [$tes, 0, -$imp, 'Devolución fra. '.$f->num]];
            $a = $this->asentar($c, $f->cobrada_at->toDateString(), 'Cobro fra. '.$f->num.' · '.($f->receptor_nombre ?: 'simplificada').' · '.self::MEDIOS[$medio], $ls, 'Cobro', 'cobro', $f->id, $userId);
            $f->update(['cobro_asiento_id' => $a->id, 'cobro_medio' => $medio]);

            return $a;
        });
    }

    /** Asiento del pago de una factura recibida: proveedor/acreedor al debe, banco/caja al haber. La contabiliza antes si hace falta. */
    public function contabilizarPago(FacturaRecibida $f, string $medio, ?int $userId = null, ?string $cuentaTes = null): Asiento
    {
        if ($f->pago_asiento_id) {
            throw new InvalidArgumentException('El pago de la factura '.$f->num.' ya está contabilizado.');
        }
        if (! $f->pagada_at) {
            throw new InvalidArgumentException('Marca antes la factura '.$f->num.' como pagada.');
        }

        return DB::transaction(function () use ($f, $medio, $userId, $cuentaTes) {
            if (! $f->asiento_id) {
                $this->contabilizarRecibida($f, $userId);
                $f->refresh();
            }
            $c = Cliente::findOrFail($f->cliente_id);
            $t = $f->tercero;
            $imp = round((float) $f->total, 2);
            $tes = $cuentaTes ?: self::cuentaMedio($medio);
            $ls = $imp >= 0 ? [[$t->cuenta, $imp, 0, $t->nombre], [$tes, 0, $imp, 'Pago fra. '.$f->num]]
                : [[$tes, -$imp, 0, 'Abono fra. '.$f->num], [$t->cuenta, 0, -$imp, $t->nombre]];
            $a = $this->asentar($c, $f->pagada_at->toDateString(), 'Pago fra. '.$f->num.' · '.$t->nombre.' · '.self::MEDIOS[$medio], $ls, 'Pago', 'pago', $f->id, $userId);
            $f->update(['pago_asiento_id' => $a->id, 'pago_medio' => $medio]);

            return $a;
        });
    }

    /** Contraasiento al anular una factura ya contabilizada */
    public function anularEmitida(Factura $f, ?int $userId = null): ?Asiento
    {
        $orig = $f->asiento_id ? Asiento::with('apuntes')->find($f->asiento_id) : null;
        if (! $orig) {
            return null;
        }
        $c = Cliente::findOrFail($f->cliente_id);
        $hoy = CarbonImmutable::now()->toDateString();
        $ej = $this->ejercicio($c, (int) substr($hoy, 0, 4));
        if ($ej->cerrado()) {
            return null;
        }
        $ls = $orig->apuntes->map(fn ($p) => [$p->cuenta, (float) $p->haber, (float) $p->debe, $p->concepto])->all();

        return $this->asentar($c, $hoy, 'Anulación fra. '.$f->num, $ls, 'Anulación', 'factura', $f->id, $userId);
    }

    // -------------------------------------------------------------- saldos
    /** Sumas por cuenta 9 dígitos: [cuenta => ['d' => , 'h' => ]] */
    public function sumas(Ejercicio $e, ?string $hasta = null, array $excluirTipos = []): Collection
    {
        return Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')
            ->where('asientos.ejercicio_id', $e->id)
            ->when($hasta, fn ($q) => $q->where('asientos.fecha', '<=', $hasta))
            ->when($excluirTipos, fn ($q) => $q->whereNotIn('asientos.tipo', $excluirTipos))
            ->groupBy('apuntes.cuenta')->orderBy('apuntes.cuenta')
            ->selectRaw('apuntes.cuenta as cuenta, sum(apuntes.debe) as d, sum(apuntes.haber) as h')
            ->get()->mapWithKeys(fn ($r) => [(string) $r->cuenta => ['d' => round((float) $r->d, 2), 'h' => round((float) $r->h, 2)]]);
    }

    /** Ingresos (7), gastos (6) y resultado sin contar regularización ni cierre */
    public function resultado(Ejercicio $e): array
    {
        $s = $this->sumas($e, null, ['Regularización', 'Cierre']);
        $ing = $s->filter(fn ($v, $k) => ((string) $k)[0] === '7')->sum(fn ($v) => $v['h'] - $v['d']);
        $gas = $s->filter(fn ($v, $k) => ((string) $k)[0] === '6')->sum(fn ($v) => $v['d'] - $v['h']);

        return ['ingresos' => round($ing, 2), 'gastos' => round($gas, 2), 'resultado' => round($ing - $gas, 2)];
    }

    // ---------------------------------------------------------- cierre
    public function cerrar(Ejercicio $e, ?int $userId = null): void
    {
        if ($e->cerrado()) {
            throw new InvalidArgumentException('El ejercicio ya está cerrado.');
        }
        $c = Cliente::findOrFail($e->cliente_id);
        $fin = $e->anio.'-12-31';
        DB::transaction(function () use ($e, $c, $fin, $userId) {
            // 1) Regularización: saldos de 6 y 7 contra 129
            $s = $this->sumas($e, null, ['Regularización', 'Cierre']);
            $ls = [];
            $res = 0.0;
            foreach ($s as $cta => $v) {
                $cta = (string) $cta;
                if (! in_array($cta[0], ['6', '7'], true)) {
                    continue;
                }
                $saldo = round($v['d'] - $v['h'], 2);
                if ($saldo == 0) {
                    continue;
                }
                $ls[] = [$cta, $saldo < 0 ? -$saldo : 0, $saldo > 0 ? $saldo : 0];
                $res += -$saldo;
            }
            if ($ls) {
                $ls[] = ['129000000', $res < 0 ? -$res : 0, $res > 0 ? $res : 0];
                $this->asentar($c, $fin, 'Regularización del ejercicio '.$e->anio, $ls, 'Regularización', null, null, $userId);
            }
            // 2) Cierre: todas las cuentas de balance a cero
            $s = $this->sumas($e);
            $ls = [];
            foreach ($s as $cta => $v) {
                $saldo = round($v['d'] - $v['h'], 2);
                if ($saldo != 0) {
                    $ls[] = [$cta, $saldo < 0 ? -$saldo : 0, $saldo > 0 ? $saldo : 0];
                }
            }
            if (count($ls) >= 2) {
                $cierre = $this->asentar($c, $fin, 'Cierre del ejercicio '.$e->anio, $ls, 'Cierre', null, null, $userId);
            }
            $e->update(['estado' => 'Cerrado']);
            // 3) Apertura del siguiente: lo contrario del cierre
            $sig = $this->ejercicio($c, $e->anio + 1);
            if (! empty($cierre) && ! Asiento::where('ejercicio_id', $sig->id)->where('tipo', 'Apertura')->exists()) {
                $ap = $cierre->apuntes->map(fn ($p) => [$p->cuenta, (float) $p->haber, (float) $p->debe])->all();
                $this->asentar($c, ($e->anio + 1).'-01-01', 'Apertura del ejercicio '.($e->anio + 1), $ap, 'Apertura', null, null, $userId);
            }
        });
    }

    /** Reabre un ejercicio: borra cierre, regularización y la apertura del siguiente */
    public function reabrir(Ejercicio $e): void
    {
        DB::transaction(function () use ($e) {
            Asiento::where('ejercicio_id', $e->id)->whereIn('tipo', ['Regularización', 'Cierre'])->delete();
            $sig = Ejercicio::where('cliente_id', $e->cliente_id)->where('anio', $e->anio + 1)->first();
            if ($sig) {
                Asiento::where('ejercicio_id', $sig->id)->where('tipo', 'Apertura')->delete();
            }
            $e->update(['estado' => 'Abierto']);
        });
    }
}
