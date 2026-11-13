<?php

namespace App\Http\Controllers;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\MovimientoBanco;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\ExtractoBancario;
use App\Support\Formato;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Conciliador bancario: se importan los extractos y cada movimiento se concilia con la contabilidad
 * (apunte del banco con el mismo importe, cobro o pago de una factura pendiente) o se le crea su asiento.
 */
class ConciliadorController extends ContabilidadController
{
    /** Reglas por concepto → cuenta de contrapartida propuesta (prototipo) */
    public const REGLAS = [['TRANSFERENCIA RECIBIDA', '430'], ['TGSS', '476'], ['SEGURIDAD SOCIAL', '476'], ['MODELO 303', '4750'], ['MODELO 111', '4751'], ['MODELO 115', '4751'],
        ['AEAT', '4750'], ['COMISION', '626'], ['COMISIÓN', '626'], ['MANTENIMIENTO', '626'], ['NOMINA', '465'], ['NÓMINA', '465'], ['ENDESA', '410'], ['IBERDROLA', '410'],
        ['TELEFONICA', '410'], ['MOVISTAR', '410'], ['SEGURO', '410'], ['INTERESES', '769'], ['PRESTAMO', '520'], ['PRÉSTAMO', '520']];

    public const DIAS = 7;

    private function bancos($c): array
    {
        Contabilidad::sincronizarBancos($c);
        $b = Cuenta::where('cliente_id', $c->id)->where('codigo', 'like', '572%')->where('oculta', false)->orderBy('codigo')->pluck('nombre', 'codigo')->all();

        return $b ?: [Contabilidad::cuentaPar('banco') => Contabilidad::nombre(Contabilidad::cuentaPar('banco'))];
    }

    public function index(Request $request)
    {
        $b = $this->base($request, 'conciliador');
        if (! is_array($b)) {
            return $b;
        }
        $c = $b['c'];
        $bancos = $this->bancos($c);
        $cta = array_key_exists((string) $request->query('cta'), $bancos) ? (string) $request->query('cta') : (string) array_key_first($bancos);
        $ver = in_array($request->query('ver'), ['pendientes', 'conciliados', 'todos'], true) ? $request->query('ver') : 'pendientes';
        $movs = MovimientoBanco::with('apunte')->where('cliente_id', $c->id)->where('cuenta', $cta)->whereYear('fecha', $b['ej']->anio)->orderByDesc('fecha')->orderByDesc('id')->get();
        $lista = $movs->filter(fn ($m) => $ver === 'todos' || ($ver === 'pendientes' ? ! $m->apunte_id : (bool) $m->apunte_id))->values();
        // Candidatos para conciliar a mano: apuntes del banco sin conciliar con el mismo importe
        $libres = $this->apuntesLibres($c, $cta, $b['ej']->anio);
        $sugerencias = [];
        foreach ($lista as $m) {
            if ($m->apunte_id) {
                continue;
            }
            $sugerencias[$m->id] = [
                'apuntes' => $libres->filter(fn ($a) => abs($this->importeApunte($a) - $m->importe) < 0.005)->take(5)->values(),
                'regla' => collect(self::REGLAS)->first(fn ($r) => str_contains(mb_strtoupper($m->concepto), $r[0]))[1] ?? null,
            ];
        }
        $saldoConta = round((float) Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('apuntes.cliente_id', $c->id)->where('apuntes.cuenta', $cta)
            ->where('asientos.ejercicio_id', $b['ej']->id)->selectRaw('coalesce(sum(apuntes.debe - apuntes.haber), 0) as s')->value('s'), 2);
        $ultimo = $movs->sortBy([['fecha', 'desc'], ['id', 'desc']])->first(fn ($m) => $m->saldo !== null);

        return view('contabilidad.conciliador', $b + ['bancos' => $bancos, 'cta' => $cta, 'ver' => $ver, 'lista' => $lista, 'sug' => $sugerencias, 'catalogo' => $this->catalogo($c),
            'nMovs' => $movs->count(), 'nPend' => $movs->whereNull('apunte_id')->count(), 'saldoConta' => $saldoConta, 'saldoBanco' => $ultimo?->saldo, 'fechaSaldo' => $ultimo?->fecha]);
    }

    private function importeApunte($a): float
    {
        return round((float) $a->debe - (float) $a->haber, 2);
    }

    private function apuntesLibres($c, string $cta, int $anio)
    {
        $usados = MovimientoBanco::where('cliente_id', $c->id)->whereNotNull('apunte_id')->pluck('apunte_id')->all();

        return Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('apuntes.cliente_id', $c->id)->where('apuntes.cuenta', $cta)
            ->whereYear('asientos.fecha', $anio)->whereNotIn('asientos.tipo', ['Apertura', 'Cierre'])->whereNotIn('apuntes.id', $usados ?: [0])
            ->orderBy('asientos.fecha')->get(['apuntes.id', 'apuntes.debe', 'apuntes.haber', 'asientos.numero', 'asientos.fecha', 'asientos.concepto']);
    }

    public function importar(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $request->validate(['extracto' => ['required', 'file', 'max:5120', 'extensions:xlsx,csv,txt'], 'cta' => ['required', 'regex:/^572\d{6}$/']],
            ['extracto.required' => 'Elige el archivo del extracto.', 'extracto.extensions' => 'El extracto tiene que estar en Excel (.xlsx) o CSV.']);
        $cta = (string) $request->input('cta');
        abort_unless(array_key_exists($cta, $this->bancos($c)), 422);
        $f = $request->file('extracto');
        try {
            $movs = ExtractoBancario::leer($f->getRealPath(), $f->getClientOriginalName());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['extracto' => $e->getMessage()]);
        }
        $nuevos = 0;
        $vistos = [];
        foreach ($movs as $i => $m) {
            // Huella: cuenta + fecha + importe + concepto + nº de repetición en el mismo extracto
            $base = $cta.'|'.$m['fecha'].'|'.number_format($m['importe'], 2, '.', '').'|'.mb_strtoupper($m['concepto']);
            $vistos[$base] = ($vistos[$base] ?? 0) + 1;
            $huella = sha1($base.'|'.$vistos[$base]);
            $mv = MovimientoBanco::firstOrCreate(['cliente_id' => $c->id, 'huella' => $huella], ['cuenta' => $cta, 'fecha' => $m['fecha'], 'concepto' => $m['concepto'], 'importe' => $m['importe'],
                'saldo' => $m['saldo'], 'importacion' => mb_substr($f->getClientOriginalName(), 0, 120), 'created_by' => $request->user()->id]);
            $nuevos += $mv->wasRecentlyCreated ? 1 : 0;
        }
        $auto = $this->autoConciliar($c, $cta, $request->user()->id);
        Auditoria::registrar('contabilidad.conciliador', 'Extracto importado ('.$f->getClientOriginalName().'): '.$nuevos.' movimientos nuevos, '.$auto.' conciliados', null, $c->id);

        return redirect()->route('contabilidad.conciliador', ['cta' => $cta])->with('ok', count($movs).' movimientos leídos · '.$nuevos.' nuevos'.($nuevos < count($movs) ? ' (los demás ya estaban importados)' : '').' · '.$auto.' conciliados automáticamente.');
    }

    public function automatico(Request $request): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $cta = (string) $request->input('cta');
        abort_unless(array_key_exists($cta, $this->bancos($c)), 422);
        $n = $this->autoConciliar($c, $cta, $request->user()->id);

        return back()->with('ok', $n ? $n.' movimientos conciliados automáticamente.' : 'No se ha encontrado ninguna coincidencia nueva. Concilia el resto a mano.');
    }

    /**
     * 1) Apunte del banco con el mismo importe y fecha cercana (±7 días).
     * 2) Factura emitida pendiente de cobro (o recibida pendiente de pago) por el mismo total, si es la única: se marca y se asienta el cobro/pago.
     */
    private function autoConciliar($c, string $cta, int $uid): int
    {
        $n = 0;
        foreach (MovimientoBanco::where('cliente_id', $c->id)->where('cuenta', $cta)->whereNull('apunte_id')->orderBy('fecha')->get() as $m) {
            $libres = $this->apuntesLibres($c, $cta, $m->fecha->year)
                ->filter(fn ($a) => abs($this->importeApunte($a) - $m->importe) < 0.005 && abs(CarbonImmutable::parse($a->fecha)->diffInDays($m->fecha, false)) <= self::DIAS)
                ->sortBy(fn ($a) => abs(CarbonImmutable::parse($a->fecha)->diffInDays($m->fecha, false)));
            if ($a = $libres->first()) {
                $m->update(['apunte_id' => $a->id, 'como' => 'automatica']);
                $n++;

                continue;
            }
            try {
                if ($m->importe > 0) {
                    $fs = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->where('cobrada', false)->where('total', $m->importe)->where('fecha', '<=', $m->fecha->toDateString())->get();
                    if ($fs->count() === 1) {
                        $f = $fs->first();
                        DB::transaction(function () use ($f, $m, $cta, $uid) {
                            $f->cobrada = true;
                            $f->cobrada_at = $m->fecha->toDateString();
                            $f->save();
                            $as = $this->conta->contabilizarCobro($f->fresh(), 'banco', $uid, $cta);
                            $m->update(['apunte_id' => Apunte::where('asiento_id', $as->id)->where('cuenta', $cta)->value('id'), 'como' => 'cobro']);
                        });
                        $n++;
                    }
                } else {
                    $fs = FacturaRecibida::with('tercero')->where('cliente_id', $c->id)->whereNull('pagada_at')->where('total', -$m->importe)->where('fecha', '<=', $m->fecha->toDateString())->get();
                    if ($fs->count() === 1) {
                        $f = $fs->first();
                        DB::transaction(function () use ($f, $m, $cta, $uid) {
                            $f->update(['pagada_at' => $m->fecha->toDateString()]);
                            $as = $this->conta->contabilizarPago($f->fresh('tercero'), 'banco', $uid, $cta);
                            $m->update(['apunte_id' => Apunte::where('asiento_id', $as->id)->where('cuenta', $cta)->value('id'), 'como' => 'pago']);
                        });
                        $n++;
                    }
                }
            } catch (InvalidArgumentException) {
                // ejercicio cerrado, fecha bloqueada…: se deja para conciliar a mano
            }
        }

        return $n;
    }

    /** Conciliar a mano con un apunte concreto */
    public function conciliar(Request $request, MovimientoBanco $mov): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && $mov->cliente_id === $c->id, 404);
        $a = Apunte::where('cliente_id', $c->id)->where('cuenta', $mov->cuenta)->findOrFail((int) $request->input('apunte_id'));
        if (MovimientoBanco::where('apunte_id', $a->id)->exists()) {
            return back()->with('aviso', 'Ese apunte ya está conciliado con otro movimiento.');
        }
        $mov->update(['apunte_id' => $a->id, 'como' => 'manual']);

        return back()->with('ok', 'Movimiento conciliado con el asiento '.Asiento::find($a->asiento_id)?->numero.'.');
    }

    /** Crea el asiento del movimiento contra la cuenta elegida y lo concilia */
    public function crearAsiento(Request $request, MovimientoBanco $mov): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && $mov->cliente_id === $c->id && ! $mov->apunte_id, 404);
        $d = $request->validate(['contra' => ['required', 'regex:/^\d{3,9}$/'], 'concepto' => ['nullable', 'string', 'max:255']], ['contra.required' => 'Elige la cuenta de contrapartida.']);
        $contra = Contabilidad::a9($d['contra']);
        if (str_starts_with($contra, '572')) {
            return back()->with('aviso', 'La contrapartida no puede ser otra cuenta de bancos: usa un traspaso manual.');
        }
        $imp = abs($mov->importe);
        $ls = $mov->importe > 0 ? [[$mov->cuenta, $imp, 0], [$contra, 0, $imp]] : [[$contra, $imp, 0], [$mov->cuenta, 0, $imp]];
        try {
            $a = $this->conta->asentar($c, $mov->fecha->toDateString(), trim((string) ($d['concepto'] ?? '')) ?: 'Banco · '.$mov->concepto, $ls, 'Banco', null, null, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        $mov->update(['apunte_id' => Apunte::where('asiento_id', $a->id)->where('cuenta', $mov->cuenta)->value('id'), 'como' => 'asiento']);
        Auditoria::registrar('contabilidad.conciliador', 'Asiento '.$a->numero.' creado desde el banco: '.$mov->concepto.' · '.Formato::eur($mov->importe), null, $c->id);

        return back()->with('ok', 'Asiento '.$a->numero.' creado y conciliado ('.$contra.' · '.(Contabilidad::nombre($contra, Contabilidad::propias($c)) ?? '').').');
    }

    public function deshacer(Request $request, MovimientoBanco $mov): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && $mov->cliente_id === $c->id, 404);
        $mov->update(['apunte_id' => null, 'como' => null]);

        return back()->with('ok', 'Conciliación deshecha. El asiento sigue en el libro diario; bórralo allí si sobra.');
    }

    public function borrarMovimiento(Request $request, MovimientoBanco $mov): RedirectResponse
    {
        $c = $this->ctx($request);
        abort_unless($c && $mov->cliente_id === $c->id && ! $mov->apunte_id, 404);
        $mov->delete();

        return back()->with('ok', 'Movimiento eliminado del extracto importado.');
    }
}
