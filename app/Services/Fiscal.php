<?php

namespace App\Services;

use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\FiscalConfig;
use App\Models\FiscalModelo;
use App\Models\Tercero;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Modelos tributarios de la empresa elegida, calculados con su libro diario.
 *
 * - Detecta qué modelos debe presentar según sus parámetros fiscales y su contabilidad.
 * - Calcula los plazos (si el último día es inhábil pasa al siguiente hábil: art. 30.5 Ley 39/2015).
 * - Rellena las casillas; las que no son calculadas se pueden corregir a mano y guardar como borrador.
 *
 * No genera ficheros para la AEAT ni presenta nada: la presentación se hace en la Sede electrónica.
 */
class Fiscal
{
    public const CATS = ['IVA', 'IRPF', 'Retenciones', 'Sociedades', 'Informativas', 'Patrimonio', 'Censales'];

    private const T3 = ['Autónomo', 'Sociedad', 'Comunidad de bienes'];

    /** código => [nombre, categoría, periodicidad T/A/P/E] */
    public const MODELOS = [
        '303' => ['IVA · autoliquidación', 'IVA', 'T'],
        '390' => ['IVA · declaración resumen anual', 'IVA', 'A'],
        '349' => ['Operaciones intracomunitarias', 'IVA', 'T'],
        '347' => ['Operaciones con terceras personas', 'Informativas', 'A'],
        '130' => ['IRPF · pago fraccionado · estimación directa', 'IRPF', 'T'],
        '131' => ['IRPF · pago fraccionado · estimación objetiva', 'IRPF', 'T'],
        '100' => ['IRPF · declaración de la renta', 'IRPF', 'A'],
        '111' => ['Retenciones de trabajo y actividades profesionales', 'Retenciones', 'T'],
        '115' => ['Retenciones por alquiler de inmuebles urbanos', 'Retenciones', 'T'],
        '123' => ['Retenciones del capital mobiliario', 'Retenciones', 'T'],
        '190' => ['Resumen anual de retenciones de trabajo y profesionales', 'Retenciones', 'A'],
        '180' => ['Resumen anual de retenciones por alquileres', 'Retenciones', 'A'],
        '193' => ['Resumen anual del capital mobiliario', 'Retenciones', 'A'],
        '200' => ['Impuesto sobre Sociedades', 'Sociedades', 'A'],
        '202' => ['Impuesto sobre Sociedades · pago fraccionado', 'Sociedades', 'P'],
        '184' => ['Entidades en régimen de atribución de rentas', 'Informativas', 'A'],
        '720' => ['Bienes y derechos en el extranjero', 'Informativas', 'A'],
        '714' => ['Impuesto sobre el Patrimonio', 'Patrimonio', 'A'],
        '036' => ['Declaración censal', 'Censales', 'E'],
    ];

    public const PERIODOS = ['T' => ['1T', '2T', '3T', '4T'], 'P' => ['1P', '2P', '3P'], 'A' => ['0A'], 'E' => ['E']];

    public const PERTXT = ['T' => 'Trimestral', 'A' => 'Anual', 'P' => 'Abril, octubre y diciembre', 'E' => 'Cuando hay cambios'];

    public const URL = [
        '303' => 'https://sede.agenciatributaria.gob.es/Sede/procedimientoini/G414.shtml',
        '111' => 'https://sede.agenciatributaria.gob.es/Sede/procedimientoini/GH01.shtml',
        '115' => 'https://sede.agenciatributaria.gob.es/Sede/irpf/retenciones-ingresos-cuenta-pagos-fraccionados/retenciones-ingresos-cuenta/modelo-115.html',
        '130' => 'https://sede.agenciatributaria.gob.es/Sede/procedimientoini/G601.shtml',
    ];

    public const URL_TODOS = 'https://sede.agenciatributaria.gob.es/Sede/presentar-consultar-declaraciones-modelo.html';

    /** Mes de fin (acumulado desde enero) de cada pago fraccionado del 202 */
    private const MES_202 = ['1P' => 3, '2P' => 9, '3P' => 11];

    private array $memo = [];

    public static function nombre(string $code): string
    {
        return self::MODELOS[$code][0] ?? '';
    }

    public static function per(string $code): string
    {
        return self::MODELOS[$code][2] ?? 'E';
    }

    // ------------------------------------------------------------ plazos

    /** Último día para presentar (ya trasladado al siguiente hábil). Null en el 036. */
    public static function plazo(string $code, int $anio, string $periodo): ?CarbonImmutable
    {
        $tz = config('app.timezone');
        $per = self::per($code);
        $d = null;
        if ($per === 'T') {
            $q = (int) $periodo[0];
            $d = $q < 4 ? CarbonImmutable::create($anio, [4, 7, 10][$q - 1], 20, 0, 0, 0, $tz)
                : CarbonImmutable::create($anio + 1, 1, in_array($code, ['111', '115', '123'], true) ? 20 : 30, 0, 0, 0, $tz);
        } elseif ($per === 'P') {
            $d = CarbonImmutable::create($anio, ['1P' => 4, '2P' => 10, '3P' => 12][$periodo] ?? 4, 20, 0, 0, 0, $tz);
        } elseif ($per === 'A') {
            $y = $anio + 1;
            $d = match ($code) {
                '390' => CarbonImmutable::create($y, 1, 30, 0, 0, 0, $tz),
                '347' => CarbonImmutable::create($y, 2, 1, 0, 0, 0, $tz)->endOfMonth()->startOfDay(),
                '190', '180', '193', '184' => CarbonImmutable::create($y, 1, 31, 0, 0, 0, $tz),
                '200' => CarbonImmutable::create($y, 7, 25, 0, 0, 0, $tz),
                '100', '714' => CarbonImmutable::create($y, 6, 30, 0, 0, 0, $tz),
                '720' => CarbonImmutable::create($y, 3, 31, 0, 0, 0, $tz),
                default => null,
            };
        }
        while ($d && ! Plazos::esHabil($d)) {
            $d = $d->addDay();
        }

        return $d;
    }

    public static function etiquetaPeriodo(string $code, int $anio, string $periodo): string
    {
        return match (self::per($code)) {
            'T', 'P' => $periodo.' '.$anio,
            'A' => (string) $anio,
            default => 'Alta o modificación',
        };
    }

    /** Periodos candidatos (año anterior, actual y siguiente) ordenados por plazo: [[anio, periodo, plazo], …] */
    public static function candidatos(string $code, int $anioActual): array
    {
        $per = self::per($code);
        if ($per === 'E') {
            return [[$anioActual, 'E', null]];
        }
        $out = [];
        foreach ([$anioActual - 1, $anioActual, $anioActual + 1] as $y) {
            foreach (self::PERIODOS[$per] as $p) {
                $out[] = [$y, $p, self::plazo($code, $y, $p)];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------ parámetros

    /** Parámetros fiscales de la empresa: los guardados sobre unos valores por defecto tomados de su ficha. */
    public function parametros(Cliente $c): array
    {
        $aut = $c->tipo === 'Autónomo';
        $part = $c->tipo === 'Particular';
        $act = collect($c->seccion('acts'))->first(fn ($a) => ! empty($a['principal']) && empty($a['fFin'])) ?? (collect($c->seccion('acts'))->first() ?? []);
        $ivaFicha = (string) ($act['iva'] ?? 'Régimen general');
        $ivaReg = $part ? 'Exento' : match (true) {
            str_starts_with($ivaFicha, 'Recargo') => 'Recargo de equivalencia',
            str_starts_with($ivaFicha, 'Simplificado') => 'Simplificado',
            $ivaFicha === 'Exento' => 'Exento',
            default => 'General',
        };
        $irpf = (string) ($act['irpf'] ?? '');
        $edMet = ! $aut ? '' : (str_contains($irpf, 'objetiva') ? 'Estimación objetiva' : (str_contains($irpf, 'normal') ? 'Estimación directa normal' : 'Estimación directa simplificada'));
        $banco = collect($c->seccion('bancos'))->first(fn ($b) => ! empty($b['principal'])) ?? (collect($c->seccion('bancos'))->first() ?? []);
        $trab = collect($c->seccion('centros'))->sum(fn ($x) => (int) ($x['trab'] ?? 0));
        $w = preg_split('/\s+/', trim((string) $c->nombre)) ?: [''];
        $det = $this->detectado($c);
        $def = [
            'nif' => (string) $c->nif, 'razon' => $aut || $part ? implode(' ', array_slice($w, 1)) : (string) $c->nombre, 'nombre' => $aut || $part ? $w[0] : '',
            'tel' => (string) ($c->telefono ?? ''), 'contacto' => (string) (data_get($c->datos, 'general.asesor') ?? ''),
            'ivaReg' => $ivaReg, 'redeme' => false, 'sii' => false, 'roi' => false,
            'edMet' => $edMet, 'exento130' => false, 'tipoIS' => '25', 'modal202' => 'Artículo 40.2', 'comuneros' => '2',
            'trab' => (string) $trab, 'alq' => $det['alq'], 'prof' => $det['prof'], 'divid' => false, 'ext720' => false, 'patr714' => false,
            'iban' => (string) ($banco['iban'] ?? ''), 'formaPago' => 'Ingreso',
        ];
        $guardado = FiscalConfig::where('cliente_id', $c->id)->first()?->datos ?? [];

        return array_merge($def, array_intersect_key($guardado, $def), ['det' => $det]);
    }

    public function guardarParametros(Cliente $c, array $in, ?int $userId): void
    {
        $base = $this->parametros($c);
        unset($base['det']);
        $bool = ['redeme', 'sii', 'roi', 'exento130', 'alq', 'prof', 'divid', 'ext720', 'patr714'];
        $v = [];
        foreach ($base as $k => $def) {
            $v[$k] = in_array($k, $bool, true) ? ! empty($in[$k]) : trim((string) ($in[$k] ?? $def));
        }
        FiscalConfig::updateOrCreate(['cliente_id' => $c->id], ['datos' => $v, 'updated_by' => $userId]);
        $this->memo = [];
    }

    /** Pistas sacadas de la contabilidad (alquiler y profesionales con retención, terceros del 347, IS anterior) */
    public function detectado(Cliente $c): array
    {
        $y = Plazos::hoy()->year;
        $a = $this->agregar($c, $y, 1, 12);
        $prev = $this->agregar($c, $y - 1, 1, 12);

        return ['alq' => $a['alqR'] > 0, 'prof' => $a['profR'] > 0, 'n347' => count($this->terceros347($c, $prev)), 'isPrev' => max(0, $prev['ing'] - $prev['gas'])];
    }

    /** Modelos que debe presentar: código => motivo */
    public static function detectar(Cliente $c, array $P): array
    {
        $t = $c->tipo;
        $part = $t === 'Particular';
        $soc = $t === 'Sociedad';
        $aut = $t === 'Autónomo';
        $cb = $t === 'Comunidad de bienes';
        $det = $P['det'] ?? [];
        $R = [];
        if (! $part) {
            if ($P['ivaReg'] !== 'Exento') {
                $R['303'] = 'Régimen '.mb_strtolower((string) $P['ivaReg']).' de IVA';
                if (empty($P['sii'])) {
                    $R['390'] = 'Resumen anual del IVA';
                }
            }
            if (! empty($P['roi'])) {
                $R['349'] = 'Inscrito en el ROI';
            }
            $n347 = (int) ($det['n347'] ?? 0);
            if (empty($P['sii']) && $n347 > 0) {
                $R['347'] = $n347.($n347 === 1 ? ' tercero supera' : ' terceros superan').' '.Formato::eur(self::umbral347()).' en la contabilidad';
            }
            $nt = (int) $P['trab'];
            if ($nt > 0 || ! empty($P['prof'])) {
                $R['111'] = implode(' y ', array_filter([$nt > 0 ? $nt.($nt === 1 ? ' trabajador' : ' trabajadores').' en nómina' : '', ! empty($P['prof']) ? 'profesionales con retención' : '']));
                $R['190'] = 'Resumen anual del 111';
            }
            if (! empty($P['alq'])) {
                $R['115'] = 'Alquiler de local con retención';
                $R['180'] = 'Resumen anual del 115';
            }
            if (! empty($P['divid'])) {
                $R['123'] = 'Dividendos o intereses a socios';
                $R['193'] = 'Resumen anual del 123';
            }
            if ($soc) {
                $R['200'] = 'Impuesto sobre Sociedades';
                if ($P['modal202'] === 'Artículo 40.3') {
                    $R['202'] = 'Modalidad del artículo 40.3';
                } elseif (($det['isPrev'] ?? 0) > 0) {
                    $R['202'] = 'El último Impuesto sobre Sociedades salió a pagar';
                }
            }
            if ($cb) {
                $R['184'] = 'Comunidad de bienes · atribución de rentas';
            }
            if ($aut) {
                if ($P['edMet'] === 'Estimación objetiva') {
                    $R['131'] = 'Estimación objetiva (módulos)';
                } elseif (empty($P['exento130'])) {
                    $R['130'] = (string) $P['edMet'];
                }
            }
        }
        if ($aut || $part) {
            $R['100'] = 'Declaración de la renta';
        }
        if (! empty($P['ext720'])) {
            $R['720'] = 'Bienes en el extranjero de más de 50.000 €';
        }
        if (! empty($P['patr714']) && ($aut || $part)) {
            $R['714'] = 'Patrimonio de más de 2.000.000 €';
        }
        $R['036'] = 'Solo cuando cambien los datos censales';

        return $R;
    }

    public static function umbral347(): float
    {
        return (float) (Parametros::get('fiscal')['umbral_347'] ?? 3005.06);
    }

    // ------------------------------------------------------------ datos desde el libro diario

    /** Totales del libro diario entre dos meses (1-12) del año */
    public function agregar(Cliente $c, int $anio, int $m0, int $m1): array
    {
        $key = $c->id.'|'.$anio.'|'.$m0.'|'.$m1;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $o = ['n' => 0, 'ing' => 0.0, 'gas' => 0.0, 'dev' => [4 => ['b' => 0.0, 'c' => 0.0], 10 => ['b' => 0.0, 'c' => 0.0], 21 => ['b' => 0.0, 'c' => 0.0]], 'devOt' => ['b' => 0.0, 'c' => 0.0],
            'ivaRep' => 0.0, 'sopB' => 0.0, 'sopC' => 0.0, 'invB' => 0.0, 'invC' => 0.0, 'trabP' => 0.0, 'trabR' => 0.0, 'profP' => 0.0, 'profR' => 0.0, 'alqP' => 0.0, 'alqR' => 0.0,
            'retSop' => 0.0, 'pers' => 0.0, 'aprov' => 0.0, 'terc' => [], 'profN' => 0, 'alqN' => 0];
        $tz = config('app.timezone');
        $desde = CarbonImmutable::create($anio, $m0, 1, 0, 0, 0, $tz)->toDateString();
        $hasta = CarbonImmutable::create($anio, $m1, 1, 0, 0, 0, $tz)->endOfMonth()->toDateString();
        $asientos = Asiento::with('apuntes')->where('cliente_id', $c->id)->whereBetween('fecha', [$desde, $hasta])
            ->whereNotIn('tipo', ['Apertura', 'Regularización', 'Cierre'])->get();
        $facturas = Factura::whereIn('id', $asientos->where('origen', 'factura')->pluck('origen_id')->filter()->all())->get()->keyBy('id');
        $profS = [];
        $alqS = [];
        foreach ($asientos as $a) {
            $o['n']++;
            $L = $a->apuntes->map(fn ($l) => ['c' => (string) $l->cuenta, 'd' => (float) $l->debe, 'h' => (float) $l->haber]);
            $sum = fn (string $p, string $s) => (float) $L->filter(fn ($l) => str_starts_with($l['c'], $p))->sum($s);
            $has = fn (string $p) => $L->contains(fn ($l) => str_starts_with($l['c'], $p));
            $v7 = $sum('7', 'h') - $sum('7', 'd');
            $g6 = $sum('6', 'd') - $sum('6', 'h');
            $o['ing'] += $v7;
            $o['gas'] += $g6;
            $o['pers'] += $sum('64', 'd');
            $o['aprov'] += $sum('60', 'd');
            $o['retSop'] += $sum('473', 'd') - $sum('473', 'h');
            $rep = $sum('477', 'h') - $sum('477', 'd');
            if (abs($rep) > 0.0001) {
                $o['ivaRep'] += $rep;
                $f = $a->origen === 'factura' ? $facturas->get($a->origen_id) : null;
                if ($f && abs((float) $f->cuota) > 0.0001 && is_array($f->ivas)) {
                    // Desglose real por tipo de la factura (el signo sigue al asiento: anulaciones en negativo)
                    $k = $rep / (float) $f->cuota;
                    foreach ($f->ivas as $tipo => $x) {
                        $t = (int) round((float) $tipo);
                        $bk = isset($o['dev'][$t]) ? $t : null;
                        $b = (float) ($x['base'] ?? 0) * $k;
                        $cu = (float) ($x['cuota'] ?? 0) * $k;
                        if ($bk === null) {
                            $o['devOt']['b'] += $b;
                            $o['devOt']['c'] += $cu;
                        } else {
                            $o['dev'][$bk]['b'] += $b;
                            $o['dev'][$bk]['c'] += $cu;
                        }
                    }
                } else {
                    $t = abs($v7) > 0.0001 ? (int) round($rep / $v7 * 100) : 0;
                    if (isset($o['dev'][$t])) {
                        $o['dev'][$t]['b'] += $v7;
                        $o['dev'][$t]['c'] += $rep;
                    } else {
                        $o['devOt']['b'] += $v7;
                        $o['devOt']['c'] += $rep;
                    }
                }
            }
            $sop = $sum('472', 'd') - $sum('472', 'h');
            if (abs($sop) > 0.0001) {
                $inv = $sum('2', 'd');
                if ($inv > 0) {
                    $o['invB'] += $inv;
                    $o['invC'] += $sop;
                } else {
                    $o['sopB'] += $g6;
                    $o['sopC'] += $sop;
                }
            }
            $ret = $sum('4751', 'h') - $sum('4751', 'd');
            $ter = $L->first(fn ($l) => preg_match('/^4[01]0/', $l['c']) && $l['h'] > 0);
            if (abs($ret) > 0.0001) {
                if ($has('640') || $has('641')) {
                    $o['trabP'] += $sum('640', 'd') + $sum('641', 'd');
                    $o['trabR'] += $ret;
                } elseif ($has('621')) {
                    $o['alqP'] += $sum('621', 'd');
                    $o['alqR'] += $ret;
                    if ($ter) {
                        $alqS[$ter['c']] = true;
                    }
                } else {
                    $o['profP'] += $g6;
                    $o['profR'] += $ret;
                    if ($ter) {
                        $profS[$ter['c']] = true;
                    }
                }
            } elseif (! $has('64')) {
                // 347: ventas (430) y compras (400/410) sin retención; no entran las cuentas genéricas …000000
                if (abs($v7) > 0.0001) {
                    foreach ($L as $l) {
                        if (str_starts_with($l['c'], '430') && $l['d'] > 0 && ! str_ends_with($l['c'], '000000')) {
                            $o['terc'][$l['c']] ??= ['imp' => 0.0, 'cl' => 'B · Ventas'];
                            $o['terc'][$l['c']]['imp'] += $l['d'];
                        }
                    }
                }
                if (abs($g6) > 0.0001 && $ter && ! str_ends_with($ter['c'], '000000')) {
                    $o['terc'][$ter['c']] ??= ['imp' => 0.0, 'cl' => 'A · Compras'];
                    $o['terc'][$ter['c']]['imp'] += $ter['h'];
                }
            }
        }
        $o['profN'] = count($profS);
        $o['alqN'] = count($alqS);

        return $this->memo[$key] = $o;
    }

    /** Terceros que superan el umbral del 347: [[nif, nombre, clave, importe], …] */
    public function terceros347(Cliente $c, array $agg): array
    {
        $umbral = self::umbral347();
        $sel = array_filter($agg['terc'], fn ($x) => $x['imp'] > $umbral);
        if (! $sel) {
            return [];
        }
        $cods = array_map('strval', array_keys($sel));
        $info = [];
        foreach (Cuenta::where('cliente_id', $c->id)->whereIn('codigo', $cods)->get() as $x) {
            $info[$x->codigo] = [$x->nif, $x->nombre];
        }
        foreach (Tercero::where('cliente_id', $c->id)->whereIn('cuenta', $cods)->get() as $x) {
            $info[$x->cuenta] = [$x->nif, $x->nombre];
        }
        $out = [];
        foreach ($sel as $k => $x) {
            $k = (string) $k;
            $out[] = [($info[$k][0] ?? '') ?: '—', ($info[$k][1] ?? '') ?: (Contabilidad::nombre($k) ?: $k), $x['cl'], round($x['imp'], 2)];
        }
        usort($out, fn ($a, $b) => $b[3] <=> $a[3]);

        return $out;
    }

    /** Datos de un periodo para rellenar las casillas */
    public function datos(Cliente $c, string $code, int $anio, string $periodo, array $P): array
    {
        $per = self::per($code);
        if ($per === 'T') {
            $q = (int) $periodo[0];
            $d = $this->agregar($c, $anio, ($q - 1) * 3 + 1, $q * 3);
            $d['cum'] = $this->agregar($c, $anio, 1, $q * 3);
            $pct = self::pct('pct_130', 20);
            $paid = 0.0;
            for ($k = 1; $k < $q; $k++) {
                $ck = $this->agregar($c, $anio, 1, $k * 3);
                $paid += max(0, round($pct / 100 * ($ck['ing'] - $ck['gas']), 2) - $paid - $ck['retSop']);
            }
            $d['prevPagos'] = round($paid, 2);
            $carry = 0.0;
            for ($k = 1; $k < $q; $k++) {
                $x = $this->agregar($c, $anio, ($k - 1) * 3 + 1, $k * 3);
                $r = $x['ivaRep'] - $x['sopC'] - $x['invC'];
                $carry = $r < 0 ? $carry - $r : $carry - min($carry, $r);
            }
            $d['prevComp'] = round($carry, 2);
        } elseif ($per === 'P') {
            $mes = self::MES_202[$periodo] ?? 3;
            $d = $this->agregar($c, $anio, 1, $mes);
            // Pagos fraccionados anteriores del mismo año (modalidad 40.3)
            $prev = 0.0;
            foreach (self::MES_202 as $p => $m) {
                if ($m >= $mes) {
                    break;
                }
                $x = $this->agregar($c, $anio, 1, $m);
                $prev += max(0, round(max(0, $x['ing'] - $x['gas']) * self::pct('pct_202_403', 17) / 100, 2) - $x['retSop'] - $prev);
            }
            $d['prev202'] = round($prev, 2);
            $pv = $this->agregar($c, $anio - 1, 1, 12);
            $d['isPrev'] = round(max(0, $pv['ing'] - $pv['gas']) * ((float) $P['tipoIS'] ?: 25) / 100 - $pv['retSop'], 2);
        } else {
            $y = $per === 'A' ? $anio : Plazos::hoy()->year;
            $d = $this->agregar($c, $y, 1, 12);
            $pv = $this->agregar($c, $y - 1, 1, 12);
            $d['isPrev'] = round(max(0, $pv['ing'] - $pv['gas']) * ((float) $P['tipoIS'] ?: 25) / 100 - $pv['retSop'], 2);
        }
        $d['tercList'] = $code === '347' ? $this->terceros347($c, $d) : [];
        $d['trabN'] = (int) $P['trab'];
        $d['comuneros'] = (int) $P['comuneros'] ?: 2;
        $d['tipoIS'] = (float) str_replace(',', '.', (string) $P['tipoIS']) ?: 25;
        $d['ivaReg'] = $P['ivaReg'];
        $d['anio'] = $anio;

        return $d;
    }

    private static function pct(string $k, float $def): float
    {
        return (float) (Parametros::get('fiscal')[$k] ?? $def);
    }

    // ------------------------------------------------------------ casillas

    /**
     * Definición de las casillas del modelo.
     * ['h' => título] | ['n','l','a' => fn(datos), 'k' => pct|int|txt] (editable) | ['n','l','calc' => fn(v), 'res' => bool]
     */
    public static function casillas(string $code, Cliente $c, array $P): array
    {
        $H = fn ($h) => ['h' => $h];
        $X = fn ($n, $l, Closure $a, ?string $k = null) => ['n' => $n, 'l' => $l, 'a' => $a, 'k' => $k];
        $K = fn ($n, $l, Closure $calc, bool $res = false) => ['n' => $n, 'l' => $l, 'calc' => $calc, 'res' => $res];
        $r2 = fn ($x) => round($x, 2);
        $pct130 = self::pct('pct_130', 20);

        switch ($code) {
            case '303':
                return [$H('IVA devengado · régimen general'),
                    $X('01', 'Base imponible al 4 %', fn ($d) => $d['dev'][4]['b']), $X('02', 'Tipo %', fn () => 4, 'pct'), $K('03', 'Cuota', fn ($v) => $r2($v('01') * $v('02') / 100)),
                    $X('04', 'Base imponible al 10 %', fn ($d) => $d['dev'][10]['b']), $X('05', 'Tipo %', fn () => 10, 'pct'), $K('06', 'Cuota', fn ($v) => $r2($v('04') * $v('05') / 100)),
                    $X('07', 'Base imponible al 21 %', fn ($d) => $d['dev'][21]['b']), $X('08', 'Tipo %', fn () => 21, 'pct'), $K('09', 'Cuota', fn ($v) => $r2($v('07') * $v('08') / 100)),
                    $K('27', 'Total cuota devengada', fn ($v) => $v('03') + $v('06') + $v('09')),
                    $H('IVA deducible'),
                    $X('28', 'Base · operaciones interiores corrientes', fn ($d) => $d['sopB']), $X('29', 'Cuota deducible', fn ($d) => $d['sopC']),
                    $X('30', 'Base · bienes de inversión', fn ($d) => $d['invB']), $X('31', 'Cuota · bienes de inversión', fn ($d) => $d['invC']),
                    $K('45', 'Total a deducir', fn ($v) => $v('29') + $v('31')),
                    $H('Resultado'),
                    $K('46', 'Resultado del régimen general', fn ($v) => $v('27') - $v('45')),
                    $X('65', '% atribuible a la Administración del Estado', fn () => 100, 'pct'),
                    $K('66', 'Atribuible a la Administración del Estado', fn ($v) => $r2($v('46') * $v('65') / 100)),
                    $X('110', 'Cuotas a compensar pendientes de periodos anteriores', fn ($d) => $d['prevComp'] ?? 0),
                    $K('78', 'Cuotas a compensar aplicadas en este periodo', fn ($v) => max(0, min($v('110'), $v('66')))),
                    $K('87', 'Cuotas a compensar pendientes en periodos posteriores', fn ($v) => $v('110') - $v('78')),
                    $K('69', 'Resultado', fn ($v) => $v('66') - $v('78')),
                    $X('70', 'A deducir (solo si es complementaria)', fn () => 0),
                    $K('71', 'Resultado de la liquidación', fn ($v) => $v('69') - $v('70'), true)];
            case '130':
                return [$H('Actividades económicas en estimación directa · acumulado del año'),
                    $X('01', 'Ingresos computables', fn ($d) => $d['cum']['ing']), $X('02', 'Gastos fiscalmente deducibles', fn ($d) => $d['cum']['gas']),
                    $K('03', 'Rendimiento neto', fn ($v) => $v('01') - $v('02')),
                    $K('04', Formato::num($pct130, 0).' % del rendimiento neto', fn ($v) => max(0, $r2($v('03') * $pct130 / 100))),
                    $X('05', 'Pagos fraccionados de trimestres anteriores', fn ($d) => $d['prevPagos']),
                    $X('06', 'Retenciones e ingresos a cuenta', fn ($d) => $d['cum']['retSop']),
                    $K('07', 'Pago fraccionado previo del trimestre', fn ($v) => $v('04') - $v('05') - $v('06')),
                    $K('12', 'Suma de pagos fraccionados', fn ($v) => max(0, $v('07'))),
                    $X('13', 'Minoración por el artículo 110.3 c) del Reglamento', fn () => 0),
                    $K('14', 'Diferencia', fn ($v) => max(0, $v('12') - $v('13'))),
                    $X('15', 'Resultados negativos de trimestres anteriores', fn () => 0),
                    $X('16', 'Deducción por préstamo de vivienda habitual', fn () => 0),
                    $K('17', 'Total', fn ($v) => $v('14') - $v('15') - $v('16')),
                    $X('18', 'A deducir (solo si es complementaria)', fn () => 0),
                    $K('19', 'Resultado de la autoliquidación', fn ($v) => $v('17') - $v('18'), true)];
            case '111':
                return [$H('Rendimientos del trabajo'),
                    $X('01', 'Nº de perceptores', fn ($d) => $d['trabP'] ? $d['trabN'] : 0, 'int'), $X('02', 'Importe de las percepciones', fn ($d) => $d['trabP']), $X('03', 'Importe de las retenciones', fn ($d) => $d['trabR']),
                    $H('Rendimientos de actividades económicas'),
                    $X('07', 'Nº de perceptores', fn ($d) => $d['profN'], 'int'), $X('08', 'Importe de las percepciones', fn ($d) => $d['profP']), $X('09', 'Importe de las retenciones', fn ($d) => $d['profR']),
                    $H('Total'),
                    $K('28', 'Suma de retenciones', fn ($v) => $v('03') + $v('09')),
                    $X('29', 'A deducir (solo si es complementaria)', fn () => 0),
                    $K('30', 'Resultado a ingresar', fn ($v) => $v('28') - $v('29'), true)];
            case '115':
                return [$H('Arrendamiento de inmuebles urbanos'),
                    $X('01', 'Nº de perceptores', fn ($d) => $d['alqN'], 'int'), $X('02', 'Base de las retenciones', fn ($d) => $d['alqP']), $X('03', 'Retenciones e ingresos a cuenta', fn ($d) => $d['alqR']),
                    $X('04', 'A deducir (solo si es complementaria)', fn () => 0),
                    $K('05', 'Resultado a ingresar', fn ($v) => $v('03') - $v('04'), true)];
            case '123': case '193':
                return [$H('Capital mobiliario'), $X('01', 'Nº de perceptores', fn () => 0, 'int'), $X('02', 'Base de las retenciones', fn () => 0), $X('03', 'Retenciones', fn () => 0),
                    $K('', 'Resultado a ingresar', fn ($v) => $v('03'), true)];
            case '349':
                return [$H('Operaciones intracomunitarias'), $X('', 'Nº de operadores', fn () => 0, 'int'), $X('', 'Entregas intracomunitarias', fn () => 0), $X('', 'Adquisiciones intracomunitarias', fn () => 0),
                    $K('', 'Total de operaciones', fn ($v) => $v('Entregas intracomunitarias') + $v('Adquisiciones intracomunitarias'), true)];
            case '131':
                return [$H('Estimación objetiva (módulos)'), $X('', 'Rendimiento neto previo por módulos', fn () => 0), $X('', 'Porcentaje aplicable', fn () => 4, 'pct'),
                    $K('', 'Pago fraccionado', fn ($v) => $r2($v('Rendimiento neto previo por módulos') * $v('Porcentaje aplicable') / 100), true)];
            case '390':
                return [$H('Resumen anual · régimen general'),
                    $X('', 'Base imponible al 4 %', fn ($d) => $d['dev'][4]['b']), $X('', 'Base imponible al 10 %', fn ($d) => $d['dev'][10]['b']), $X('', 'Base imponible al 21 %', fn ($d) => $d['dev'][21]['b']),
                    $X('', 'Total cuotas devengadas', fn ($d) => $d['ivaRep']), $X('', 'Total cuotas deducibles', fn ($d) => $d['sopC'] + $d['invC']),
                    $K('', 'Resultado del régimen general', fn ($v) => $v('Total cuotas devengadas') - $v('Total cuotas deducibles')),
                    $X('108', 'Volumen total de operaciones', fn ($d) => $d['ing']),
                    $K('', 'Resultado anual', fn ($v) => $v('Resultado del régimen general'), true)];
            case '347':
                return [$H('Resumen de la declaración'),
                    $X('', 'Nº total de personas y entidades', fn ($d) => count($d['tercList']), 'int'),
                    $X('', 'Importe total de las operaciones', fn ($d) => array_sum(array_column($d['tercList'], 3))),
                    $K('', 'Importe declarado', fn ($v) => $v('Importe total de las operaciones'), true)];
            case '190':
                return [$H('Resumen anual de retenciones'),
                    $X('', 'Nº total de perceptores', fn ($d) => ($d['trabP'] ? $d['trabN'] : 0) + $d['profN'], 'int'),
                    $X('', 'Importe total de las percepciones', fn ($d) => $d['trabP'] + $d['profP']),
                    $X('', 'Importe total de las retenciones', fn ($d) => $d['trabR'] + $d['profR']),
                    $K('', 'Total retenciones declaradas', fn ($v) => $v('Importe total de las retenciones'), true)];
            case '180':
                return [$H('Resumen anual de alquileres'), $X('', 'Nº total de perceptores', fn ($d) => $d['alqN'], 'int'), $X('', 'Base de las retenciones', fn ($d) => $d['alqP']), $X('', 'Retenciones', fn ($d) => $d['alqR']),
                    $K('', 'Total retenciones declaradas', fn ($v) => $v('Retenciones'), true)];
            case '200':
                return [$H('Liquidación'),
                    $X('', 'Resultado de la cuenta de pérdidas y ganancias', fn ($d) => $d['ing'] - $d['gas']),
                    $X('', 'Ajustes extracontables', fn () => 0),
                    $K('', 'Base imponible', fn ($v) => max(0, $v('Resultado de la cuenta de pérdidas y ganancias') + $v('Ajustes extracontables'))),
                    $X('', 'Tipo de gravamen %', fn ($d) => $d['tipoIS'], 'pct'),
                    $K('', 'Cuota íntegra', fn ($v) => $r2($v('Base imponible') * $v('Tipo de gravamen %') / 100)),
                    $X('', 'Deducciones y bonificaciones', fn () => 0),
                    $X('', 'Retenciones e ingresos a cuenta', fn ($d) => $d['retSop']),
                    $X('', 'Pagos fraccionados (202)', fn ($d) => ($P['modal202'] ?? '') === 'Artículo 40.3' ? 0 : 3 * $r2(max(0, $d['isPrev']) * self::pct('pct_202_402', 18) / 100)),
                    $K('', 'Cuota diferencial', fn ($v) => $v('Cuota íntegra') - $v('Deducciones y bonificaciones') - $v('Retenciones e ingresos a cuenta') - $v('Pagos fraccionados (202)'), true)];
            case '202':
                if (($P['modal202'] ?? '') === 'Artículo 40.3') {
                    return [$H('Modalidad del artículo 40.3 de la LIS · acumulado del año'),
                        $X('', 'Base imponible del periodo', fn ($d) => max(0, $d['ing'] - $d['gas'])),
                        $X('', 'Porcentaje', fn () => self::pct('pct_202_403', 17), 'pct'),
                        $K('', 'Resultado', fn ($v) => $r2($v('Base imponible del periodo') * $v('Porcentaje') / 100)),
                        $X('', 'Retenciones e ingresos a cuenta', fn ($d) => $d['retSop']),
                        $X('', 'Pagos fraccionados anteriores del ejercicio', fn ($d) => $d['prev202']),
                        $K('', 'Pago fraccionado', fn ($v) => max(0, $v('Resultado') - $v('Retenciones e ingresos a cuenta') - $v('Pagos fraccionados anteriores del ejercicio')), true)];
                }

                return [$H('Modalidad del artículo 40.2 de la LIS'),
                    $X('', 'Cuota íntegra del último IS presentado, menos deducciones y retenciones', fn ($d) => max(0, $d['isPrev'])),
                    $X('', 'Porcentaje', fn () => self::pct('pct_202_402', 18), 'pct'),
                    $K('', 'Pago fraccionado', fn ($v) => $r2($v('Cuota íntegra del último IS presentado, menos deducciones y retenciones') * $v('Porcentaje') / 100), true)];
            case '184':
                return [$H('Rendimientos de la entidad'), $X('', 'Ingresos íntegros', fn ($d) => $d['ing']), $X('', 'Gastos deducibles', fn ($d) => $d['gas']),
                    $K('', 'Rendimiento neto', fn ($v) => $v('Ingresos íntegros') - $v('Gastos deducibles')),
                    $X('', 'Nº de comuneros', fn ($d) => $d['comuneros'], 'int'),
                    $K('', 'Rendimiento atribuido a cada comunero', fn ($v) => $v('Nº de comuneros') ? $r2($v('Rendimiento neto') / $v('Nº de comuneros')) : 0, true)];
            case '100':
                $part = $c->tipo === 'Particular';

                return [$H($part ? 'Rendimientos del trabajo' : 'Rendimientos de actividades económicas'),
                    $X('', 'Rendimiento neto', fn ($d) => $part ? 0 : $d['ing'] - $d['gas']),
                    $X('', 'Reducciones', fn () => 0),
                    $K('', 'Base liquidable general', fn ($v) => max(0, $v('Rendimiento neto') - $v('Reducciones'))),
                    $X('', 'Mínimo personal y familiar', fn () => 5550),
                    $K('', 'Cuota íntegra orientativa', fn ($v) => max(0, $r2(self::escala($v('Base liquidable general')) - self::escala($v('Mínimo personal y familiar'))))),
                    $X('', 'Retenciones y pagos fraccionados', fn ($d) => $part ? 0 : $d['retSop'] + max(0, $d['ing'] - $d['gas']) * $pct130 / 100),
                    $K('', 'Resultado orientativo', fn ($v) => $v('Cuota íntegra orientativa') - $v('Retenciones y pagos fraccionados'), true)];
            case '720':
                return [$H('Bienes en el extranjero'), $X('', 'Cuentas en entidades financieras', fn () => 0), $X('', 'Valores, seguros y rentas', fn () => 0), $X('', 'Inmuebles', fn () => 0),
                    $K('', 'Total declarado', fn ($v) => $v('Cuentas en entidades financieras') + $v('Valores, seguros y rentas') + $v('Inmuebles'), true)];
            case '714':
                return [$H('Patrimonio'), $X('', 'Inmuebles', fn () => 0), $X('', 'Depósitos y cuentas', fn () => 0), $X('', 'Otros bienes y derechos', fn () => 0), $X('', 'Deudas', fn () => 0),
                    $K('', 'Patrimonio neto', fn ($v) => $v('Inmuebles') + $v('Depósitos y cuentas') + $v('Otros bienes y derechos') - $v('Deudas'), true)];
            case '036':
                return [$H('Datos censales'),
                    $X('', 'NIF', fn () => (string) $c->nif, 'txt'), $X('', 'Nombre o razón social', fn () => (string) $c->nombre, 'txt'), $X('', 'Forma jurídica', fn () => (string) $c->tipo, 'txt'),
                    $X('', 'Actividad', fn () => $c->actividadPrincipal(), 'txt'), $X('', 'Régimen de IVA', fn ($d) => (string) $d['ivaReg'], 'txt'),
                    $X('', 'Régimen de tributación directa', fn () => match ($c->tipo) {
                        'Sociedad' => 'Impuesto sobre Sociedades', 'Autónomo' => 'IRPF · '.mb_strtolower((string) ($P['edMet'] ?: 'Estimación directa simplificada')),
                        'Comunidad de bienes' => 'Atribución de rentas', default => 'IRPF' }, 'txt')];
        }

        return [];
    }

    /** Escala general orientativa del IRPF (estatal + autonómica estándar) */
    public static function escala(float $b): float
    {
        $prev = 0.0;
        $t = 0.0;
        foreach ([[12450, 19], [20200, 24], [35200, 30], [60000, 37], [300000, 45], [INF, 47]] as [$lim, $pct]) {
            if ($b <= $prev) {
                break;
            }
            $t += (min($b, $lim) - $prev) * $pct / 100;
            $prev = $lim;
        }

        return $t;
    }

    // ------------------------------------------------------------ cálculo del modelo

    /**
     * Calcula el modelo con las correcciones guardadas (o las enviadas en el formulario).
     *
     * @return array{filas: array, res: ?array, resV: float, resLbl: string, tipoOpts: array, tipo: string, n: int, devOt: float, terc: array, P: array}
     */
    public function calcular(Cliente $c, string $code, int $anio, string $periodo, ?array $over = null, ?string $tipoPedido = null): array
    {
        $P = $this->parametros($c);
        $reg = FiscalModelo::where('cliente_id', $c->id)->where('modelo', $code)->where('anio', $anio)->where('periodo', $periodo)->first();
        $o = $over ?? ($reg?->casillas ?? []);
        $d = $this->datos($c, $code, $anio, $periodo, $P);
        $cas = self::casillas($code, $c, $P);
        $byId = [];
        foreach ($cas as $x) {
            if (! isset($x['h'])) {
                $byId[$x['n'] ?: $x['l']] = $x;
            }
        }
        $memo = [];
        $v = function (string $id) use (&$v, &$memo, $byId, $o, $d) {
            if (array_key_exists($id, $memo)) {
                return $memo[$id];
            }
            $x = $byId[$id] ?? null;
            if (! $x) {
                return 0;
            }
            if (isset($x['calc'])) {
                $r = round(($x['calc'])($v), 2);
            } elseif (($x['k'] ?? null) === 'txt') {
                $r = array_key_exists($id, $o) ? (string) $o[$id] : ($x['a'])($d);
            } else {
                $r = array_key_exists($id, $o) && $o[$id] !== '' && $o[$id] !== null ? Formato::parseNum((string) $o[$id]) : round((float) ($x['a'])($d), 2);
            }

            return $memo[$id] = $r;
        };
        $fmt = fn ($x, $val) => match ($x['k'] ?? null) {
            'int' => (string) (int) round((float) $val), 'pct' => str_replace('.', ',', (string) (float) $val), 'txt' => (string) $val, default => Formato::eur((float) $val),
        };
        $filas = [];
        $res = null;
        foreach ($cas as $x) {
            if (isset($x['h'])) {
                $filas[] = ['h' => $x['h']];

                continue;
            }
            $id = $x['n'] ?: $x['l'];
            $val = $v($id);
            $ed = ! isset($x['calc']);
            $fila = ['id' => $id, 'n' => $x['n'], 'l' => $x['l'], 'ed' => $ed, 'res' => ! empty($x['res']), 'k' => $x['k'] ?? null,
                'txt' => $ed && array_key_exists($id, $o) && $o[$id] !== null ? (string) $o[$id] : $fmt($x, $val), 'cambiada' => $ed && array_key_exists($id, $o) && $o[$id] !== null, 'v' => $val,
                'def' => $ed ? $fmt($x, ($x['k'] ?? null) === 'txt' ? ($x['a'])($d) : round((float) ($x['a'])($d), 2)) : null];
            if ($fila['res']) {
                $res = $fila;
            }
            $filas[] = $fila;
        }
        $resV = $res ? (float) $res['v'] : 0.0;
        $resLbl = ! $res ? '' : (in_array($code, ['303', '390'], true) ? ($resV > 0 ? 'A ingresar' : ($resV < 0 ? 'A compensar o devolver' : 'Sin actividad'))
            : (in_array($code, ['100', '200'], true) ? ($resV >= 0 ? 'A ingresar' : 'A devolver') : ($resV > 0 ? 'A ingresar' : 'Sin importe a ingresar')));
        $tipoOpts = $resV > 0 ? ['I' => 'Ingreso', 'U' => 'Domiciliación del ingreso']
            : ($resV < 0 && $code === '303' ? ($periodo === '4T' ? ['D' => 'Devolución', 'C' => 'A compensar'] : ['C' => 'A compensar']) : ['N' => 'Sin actividad o resultado cero']);
        $tipoGuardado = $tipoPedido ?? $reg?->tipo_decl;
        $tipo = isset($tipoOpts[$tipoGuardado]) ? $tipoGuardado : ($resV > 0 && $P['formaPago'] === 'Domiciliación' ? 'U' : array_key_first($tipoOpts));
        $n = $code === '130' ? $d['cum']['n'] : $d['n'];

        return ['filas' => $filas, 'res' => $res, 'resV' => $resV, 'resLbl' => $resLbl, 'tipoOpts' => $tipoOpts, 'tipo' => $tipo, 'n' => $n,
            'devOt' => round((float) $d['devOt']['b'], 2), 'terc' => $d['tercList'], 'P' => $P, 'reg' => $reg];
    }
}
