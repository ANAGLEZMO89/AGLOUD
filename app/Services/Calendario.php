<?php

namespace App\Services;

use App\Http\Controllers\ControlHorarioController;
use App\Models\JornadaAusencia;
use App\Models\JornadaDia;
use App\Models\LaboralHora;
use App\Models\TrabajadorCondicion;
use App\Support\Dec;
use App\Support\Formato;
use App\Support\Plazos;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Calendario laboral del trabajador y su efecto en la nómina (fase 5):
 *  - condiciones del contrato con fecha de efecto (histórico);
 *  - días del mes: en alta, laborables según sus días de trabajo y los festivos de la empresa, trabajados y efectivos;
 *  - ausencias confirmadas (las del control horario llegan como propuesta y no cuentan hasta revisarlas);
 *  - incapacidad temporal: prestación (60 % / 75 % de la base reguladora) y mejora del convenio, por separado;
 *  - horas por cantidad: extraordinarias, complementarias y conceptos del convenio por hora.
 * Lo que no puede calcular con datos ciertos no lo aproxima: lo avisa (error, aviso o información).
 */
class Calendario
{
    /** Tipos de ausencia: retribuida (la paga la empresa como salario) o no; la IT tiene su propio cálculo */
    public const AUSENCIAS = [
        'Vacaciones' => ['retrib' => true],
        'Permiso retribuido' => ['retrib' => true],
        'Asuntos propios' => ['retrib' => true],
        'Incapacidad temporal' => ['it' => true],
        'Permiso no retribuido' => ['retrib' => false],
        'Ausencia injustificada' => ['retrib' => false],
    ];

    public const CONTINGENCIAS = ['cc' => 'Enfermedad común o accidente no laboral', 'at' => 'Accidente de trabajo o enfermedad profesional'];

    /** Días de la semana (ISO: 1 = lunes … 7 = domingo) en que trabaja si la ficha no dice otra cosa */
    public const DIAS_DEFECTO = [1, 2, 3, 4, 5];

    public const DIAS_TXT = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

    /** Campos de la ficha que cambian con fecha de efecto */
    public const CAMPOS_CONDICION = ['salTipo', 'salBase', 'salDia', 'plus', 'jor', 'horas', 'convCat', 'cat', 'grupo', 'contrato', 'pagas', 'prorr', 'diasSemana', 'diasExtra'];

    private static array $cond = [];

    public static function olvidar(): void
    {
        self::$cond = [];
    }

    public static function diasSemana(array $w): array
    {
        $d = array_values(array_unique(array_filter(array_map('intval', (array) ($w['diasSemana'] ?? [])), fn ($x) => $x >= 1 && $x <= 7)));
        sort($d);

        return $d ?: self::DIAS_DEFECTO;
    }

    // ------------------------------------------------------------------ condiciones con fecha de efecto
    /** Histórico de condiciones de un trabajador, de la más antigua a la más reciente */
    public static function historico(int $tid): Collection
    {
        if (! isset(self::$cond[$tid]) || app()->runningUnitTests()) {
            self::$cond[$tid] = TrabajadorCondicion::withoutGlobalScopes()->where('trabajador_id', $tid)->orderBy('efectos_desde')->get();
        }

        return self::$cond[$tid];
    }

    /**
     * Ficha con las condiciones vigentes al empezar el periodo del mes. Si cambian dentro del mes, se anota en _cambiosMes:
     * ese mes se calcula con las del inicio (el cálculo por tramos llega en la fase 6) y se avisa.
     */
    public static function aplicarCondiciones(array $w, int $anio, int $m): array
    {
        if (empty($w['id']) || ($h = self::historico((int) $w['id']))->isEmpty()) {
            return $w;
        }
        $ini = Carbon::create($anio, $m, 1)->toDateString();
        $fin = Carbon::create($anio, $m, 1)->endOfMonth()->toDateString();
        $ref = max($ini, substr((string) ($w['alta'] ?? $ini), 0, 10));
        $vig = $h->filter(fn ($c) => $c->efectos_desde->toDateString() <= $ref)->last();
        if ($vig) {
            foreach (self::CAMPOS_CONDICION as $k) {
                if (array_key_exists($k, $vig->datos ?? [])) {
                    $w[$k] = $vig->datos[$k];
                }
            }
        }
        $w['_cambiosMes'] = $h->filter(fn ($c) => $c->efectos_desde->toDateString() > $ref && $c->efectos_desde->toDateString() <= $fin)
            ->map(fn ($c) => $c->efectos_desde->toDateString())->values()->all();

        return $w;
    }

    // ------------------------------------------------------------------ días del mes
    /** Ausencias de un trabajador que se solapan con unas fechas */
    public static function ausencias(int $tid, string $desde, string $hasta, array $estados = ['confirmada']): Collection
    {
        return JornadaAusencia::withoutGlobalScopes()->where('trabajador_id', $tid)->whereIn('estado', $estados)
            ->whereDate('desde', '<=', $hasta)->whereDate('hasta', '>=', $desde)->orderBy('desde')->get();
    }

    /**
     * Calendario del mes: [dias => [fecha => estado], alta, diasMes, laborables, trabajados, efectivos, noRetrib, it, pagados, itAus, pendientes, desde, hasta].
     *  - laborables: días de trabajo de su calendario (sus días de la semana menos festivos) en alta.
     *  - trabajados: laborables menos IT y ausencias no retribuidas (vacaciones y permisos retribuidos cuentan).
     *  - efectivos: laborables sin ninguna ausencia (presencia real).
     *  - noRetrib e it: días naturales que no paga la empresa como salario.
     */
    public static function mes(array $w, int $anio, int $m): array
    {
        [$pd, $ph, $dias, $dm] = Nomina::periodo($w, $anio, $m);
        $ini = Carbon::create($anio, $m, 1)->startOfDay();
        $patron = self::diasSemana($w);
        $fest = Plazos::festivos($anio, ! empty($w['_cid']) ? (int) $w['_cid'] : null);
        $aus = ! empty($w['id']) ? self::ausencias((int) $w['id'], $ini->toDateString(), $ini->copy()->endOfMonth()->toDateString()) : collect();
        $pend = ! empty($w['id']) ? self::ausencias((int) $w['id'], $ini->toDateString(), $ini->copy()->endOfMonth()->toDateString(), ['propuesta'])->count() : 0;
        $out = ['dias' => [], 'alta' => $dias, 'diasMes' => $dm, 'laborables' => 0, 'trabajados' => 0, 'efectivos' => 0, 'noRetrib' => 0, 'it' => 0,
            'itAus' => $aus->filter(fn ($a) => ! empty(self::AUSENCIAS[$a->tipo]['it']))->values(), 'aus' => $aus, 'pendientes' => $pend, 'desde' => $pd, 'hasta' => $ph, 'patron' => $patron];
        for ($i = 0; $i < $dm; $i++) {
            $d = $ini->copy()->addDays($i);
            $f = $d->toDateString();
            $alta = $dias > 0 && $f >= $pd && $f <= $ph;
            $fe = $fest[$f] ?? null;
            $lab = $alta && in_array($d->dayOfWeekIso, $patron, true) && ! $fe;
            $a = $alta ? $aus->first(fn ($x) => $x->desde->toDateString() <= $f && $x->hasta->toDateString() >= $f) : null;
            $tipo = $a ? (self::AUSENCIAS[$a->tipo] ?? ['retrib' => true]) : null;
            $esIt = $a && ! empty($tipo['it']);
            $noRet = $a && ! $esIt && ! ($tipo['retrib'] ?? true);
            $out['dias'][$f] = ['alta' => $alta, 'lab' => $lab, 'fest' => $alta ? $fe : null, 'aus' => $a?->tipo, 'it' => $esIt, 'noRet' => $noRet, 'wd' => $d->dayOfWeekIso];
            $out['laborables'] += $lab ? 1 : 0;
            $out['trabajados'] += $lab && ! $esIt && ! $noRet ? 1 : 0;
            $out['efectivos'] += $lab && ! $a ? 1 : 0;
            $out['noRetrib'] += $noRet ? 1 : 0;
            $out['it'] += $esIt ? 1 : 0;
        }
        $out['pagados'] = max(0, $dias - $out['noRetrib'] - $out['it']);

        return $out;
    }

    /**
     * Fracción del salario mensual que paga la empresa: mes completo = 1; si no, días / 30 (mes comercial),
     * descontando a razón de 1/30 por cada día de IT o de ausencia no retribuida. Devuelve [fracción, días que se pagan de 30].
     */
    public static function fraccionPagada(array $cal): array
    {
        $desc = $cal['noRetrib'] + $cal['it'];
        $base = $cal['alta'] >= $cal['diasMes'] ? 30 : $cal['alta'];
        if (! $desc) {
            return [$cal['alta'] >= $cal['diasMes'] ? 1.0 : max(0, min(1, $cal['alta'] / 30)), $base];
        }
        $n = $cal['pagados'] > 0 ? max(0, $base - $desc) : 0;

        return [$n / 30, $n];
    }

    // ------------------------------------------------------------------ incapacidad temporal
    /**
     * Base reguladora diaria de una IT: la indicada en la ausencia o, si no, la base de contingencias comunes de la nómina emitida
     * del mes anterior a la baja, entre 30 (salario mensual) o entre los días de ese mes (salario diario). [importe|null, explicación]
     */
    public static function baseReguladora(array $w, JornadaAusencia $a): array
    {
        if ($a->base_reguladora !== null && (float) $a->base_reguladora > 0) {
            return [Dec::de((string) $a->base_reguladora), 'base reguladora diaria indicada en la baja'];
        }
        $p = Carbon::parse($a->desde)->startOfMonth()->subMonth();
        $g = ! empty($w['id']) ? Nomina::guardada((int) $w['id'], $p->year, $p->month) : null;
        $base = $g && $g->emitida() ? ($g->datos['calc']['base'] ?? null) : null;
        if ($base === null || (float) $base <= 0) {
            return [null, 'no hay nómina emitida de '.mb_strtolower(Nomina::MESES[$p->month - 1]).' de '.$p->year.' con su base de cotización'];
        }
        $div = Nomina::diario($w) ? $p->daysInMonth : 30;

        return [Dec::de((float) $base)->entre($div), 'base de contingencias comunes de '.mb_strtolower(Nomina::MESES[$p->month - 1]).' ('.Formato::eur((float) $base).') / '.$div.' días'];
    }

    /**
     * Prestación de IT (por separado: a cargo de la empresa y en pago delegado) y mejora del convenio.
     * Porcentajes generales del Régimen General: contingencias comunes, nada los días 1 a 3, 60 % del 4 al 20 (del 4 al 15 a cargo
     * de la empresa) y 75 % desde el 21; contingencias profesionales, 75 % desde el primer día de baja (arts. 169-173 LGSS).
     * Devuelve [lineas, cotización (base reguladora × días de IT del mes), avisos].
     */
    public static function it(array $w, array $cal, int $anio, int $m): array
    {
        $L = [];
        $cot = Dec::de(0);
        $av = [];
        $reglas = $w['_conv']['it'] ?? null;
        $baseTxt = trim((string) ($reglas['base'] ?? ''));
        $baseOk = $baseTxt === '' || preg_match('/reguladora/i', $baseTxt);
        foreach ($cal['itAus'] as $k => $a) {
            $cont = $a->contingencia === 'at' ? 'at' : 'cc';
            if (! $a->contingencia) {
                $av[] = ['aviso', 'La baja del '.Formato::fecha($a->desde).' no indica la contingencia: se calcula como enfermedad común. Corrígela en el calendario si es accidente de trabajo.'];
            }
            $dias = array_keys(array_filter($cal['dias'], fn ($d, $f) => $d['it'] && $f >= $a->desde->toDateString() && $f <= $a->hasta->toDateString(), ARRAY_FILTER_USE_BOTH));
            if (! $dias) {
                continue;
            }
            [$br, $brTxt] = self::baseReguladora($w, $a);
            if (! $br) {
                $av[] = ['error', 'IT desde el '.Formato::fecha($a->desde).': falta la base reguladora ('.$brTxt.'). Indícala en la baja del calendario; sin ella no se calcula la prestación.'];

                continue;
            }
            $cot = $cot->mas($br->por(count($dias)));
            // Agrupa los días por tramo: [clave => [concepto, pct prestación, pct mejora, días]]
            $tr = [];
            foreach ($dias as $f) {
                $n = (int) $a->desde->diffInDays(Carbon::parse($f)) + 1;
                [$clave, $nom, $pct] = $cont === 'at' ? ['at', 'Prestación IT pago delegado (75 %, contingencia profesional)', 75]
                    : ($n <= 3 ? ['c0', null, 0] : ($n <= 15 ? ['c1', 'Prestación IT a cargo de la empresa (60 %, días 4 a 15)', 60]
                    : ($n <= 20 ? ['c2', 'Prestación IT pago delegado (60 %, días 16 a 20)', 60] : ['c3', 'Prestación IT pago delegado (75 %, desde el día 21)', 75])));
                $mej = null;
                foreach ((array) ($reglas[$cont] ?? []) as $t) {
                    if ($n >= (int) $t['desde'] && ($t['hasta'] === null || $n <= (int) $t['hasta']) && (empty($t['hosp']) || $a->hospitalizacion)) {
                        $mej = (float) $t['pct'];
                    }
                }
                $tr[$clave.'|'.($mej ?? '-')] ??= ['nom' => $nom, 'pct' => $pct, 'mej' => $mej, 'dias' => 0, 'de' => $n];
                $tr[$clave.'|'.($mej ?? '-')]['dias']++;
                $tr[$clave.'|'.($mej ?? '-')]['a'] = $n;
            }
            $norma = 'Ley General de la Seguridad Social, arts. 169 a 173 (porcentajes generales del Régimen General)';
            foreach (array_values($tr) as $j => $t) {
                $rango = 'días '.$t['de'].' a '.$t['a'].' de la baja';
                if ($t['pct'] > 0) {
                    $pd = $br->por($t['pct'])->entre(100);
                    $imp = $pd->por($t['dias'])->redondear();
                    $L[] = ['id' => 'it'.$k.'p'.$j, 'cod' => '40', 'concepto' => $t['nom'], 'cant' => $t['dias'], 'precio' => $pd->redondear(4)->float(), 'importe' => $imp->float(),
                        'clase' => 'tributa', 'origen' => 'calendario', 'conv' => null, 'regla' => $norma,
                        'expl' => $t['dias'].' días × '.$br->txt(4).' € × '.$t['pct'].' % = '.$imp->txt().' € · '.$rango.' · '.$brTxt];
                }
                if ($t['mej'] !== null && $t['mej'] > $t['pct']) {
                    if (! $baseOk) {
                        $av[] = ['aviso', 'El convenio mejora la IT sobre «'.$baseTxt.'»: esa base no la interpreta el motor. Calcula el complemento y añádelo a mano.'];

                        continue;
                    }
                    $md = $br->por($t['mej'] - $t['pct'])->entre(100);
                    $imp = $md->por($t['dias'])->redondear();
                    $L[] = ['id' => 'it'.$k.'m'.$j, 'cod' => '41', 'concepto' => 'Complemento de IT del convenio (hasta el '.Formato::num($t['mej'], 0).' %)', 'cant' => $t['dias'], 'precio' => $md->redondear(4)->float(),
                        'importe' => $imp->float(), 'clase' => 'tributa', 'origen' => 'convenio', 'conv' => null,
                        'regla' => 'Convenio'.(($w['_conv']['version'] ?? null) ? ' · '.$w['_conv']['version'] : '').' · incapacidad temporal'.(! empty($reglas['art']) ? ' · '.$reglas['art'] : ''),
                        'expl' => $t['dias'].' días × '.$br->txt(4).' € × ('.Formato::num($t['mej'], 0).' % − '.$t['pct'].' % de la prestación) = '.$imp->txt().' € · '.$rango];
                }
            }
            if ($a->hasta->toDateString() >= Carbon::create($anio, $m, 1)->toDateString() && $cont === 'cc' && collect($tr)->contains(fn ($t) => $t['a'] >= 16)) {
                $av[] = ['info', 'La prestación en pago delegado (desde el día 16) la adelanta la empresa y se compensa en la liquidación de cuotas a la Seguridad Social.'];
            }
        }
        if ($cal['it'] > 0) {
            $av[] = ['info', 'Días de IT: el salario de esos días no se paga (se descuentan a razón de 1/30) y se cotiza por la base reguladora. Los porcentajes de la prestación son los generales: revisa regímenes especiales y recaídas.'];
        }

        return [$L, $cot->redondear()->float(), $av];
    }

    // ------------------------------------------------------------------ horas
    /** Valor de la hora ordinaria: salario anual / (jornada anual del convenio × coeficiente de jornada). [Dec|null, explicación] */
    public static function horaOrdinaria(array $w): array
    {
        $ha = Nomina::n($w['_conv']['jornada'] ?? 0);
        $coef = (Nomina::n($w['jor'] ?? 100) ?: 100) / 100;
        if ($ha <= 0) {
            return [null, 'el convenio no tiene la jornada anual (sección Jornada)'];
        }
        $anual = Nomina::anual($w);

        return [Dec::de($anual)->entre(Dec::de($ha)->por($coef)), Formato::eur($anual).' al año / '.Formato::num($ha * $coef, 2).' h'];
    }

    /** Horas del mes llevadas a la nómina, agrupadas y valoradas. Devuelve [lineas, avisos] */
    public static function horas(array $w, array $cal, array $ctx, int $anio, int $m): array
    {
        if (empty($w['id']) || $cal['alta'] <= 0) {
            return [[], []];
        }
        $rows = LaboralHora::withoutGlobalScopes()->where('trabajador_id', $w['id'])->whereDate('fecha', '>=', $cal['desde'])->whereDate('fecha', '<=', $cal['hasta'])->orderBy('fecha')->get();
        if ($rows->isEmpty()) {
            return [[], []];
        }
        $L = [];
        $av = [];
        [$ho, $hoTxt] = self::horaOrdinaria($w);
        $parcial = Nomina::parcial($w);
        $cat = $w['convCat'] ?? '';
        foreach ($rows->groupBy(fn ($r) => $r->tipo.'|'.$r->concepto) as $k => $g) {
            $h = Dec::de((string) $g->sum(fn ($r) => (float) $r->horas));
            $r0 = $g->first();
            $hTxt = Formato::num($h->float(), 2).' h';
            if ($r0->tipo === 'extra') {
                $hx = $w['_conv']['hx'] ?? null;
                if ($parcial) {
                    $av[] = ['error', $hTxt.' extraordinarias: a tiempo parcial no se pueden hacer horas extraordinarias salvo fuerza mayor (art. 12.4.c ET). Regístralas como complementarias si hay pacto.'];

                    continue;
                }
                if (! $hx) {
                    $av[] = ['error', $hTxt.' extraordinarias sin precio: el convenio no fija el valor de la hora extra (sección Jornada).'];

                    continue;
                }
                if (($hx['compensacion'] ?? 'economica') === 'descanso') {
                    $av[] = ['info', $hTxt.' extraordinarias se compensan con descanso según el convenio: no se pagan en la nómina.'];

                    continue;
                }
                if ($hx['tipo'] === 'importe') {
                    $precio = Dec::de((float) $hx['valor']);
                    $orig = 'precio del convenio';
                } elseif ($ho) {
                    $precio = $ho->por(100 + (float) $hx['valor'])->entre(100);
                    $orig = 'hora ordinaria '.$ho->txt(4).' € ('.$hoTxt.') + '.Formato::num((float) $hx['valor'], 0).' %';
                } else {
                    $av[] = ['error', $hTxt.' extraordinarias: no se puede calcular la hora ordinaria ('.$hoTxt.').'];

                    continue;
                }
                if ($ho && $precio->menorQue($ho)) {
                    $av[] = ['aviso', 'La hora extraordinaria ('.$precio->txt().' €) vale menos que la ordinaria ('.$ho->txt().' €): el art. 35.1 ET no lo permite.'];
                }
                $anioH = (float) LaboralHora::withoutGlobalScopes()->where('trabajador_id', $w['id'])->where('tipo', 'extra')->whereYear('fecha', $anio)->sum('horas');
                if ($anioH > 80) {
                    $av[] = ['aviso', 'Lleva '.Formato::num($anioH, 2).' h extraordinarias en '.$anio.': el límite es de 80 al año (art. 35.2 ET), sin contar las compensadas con descanso.'];
                }
                $imp = $precio->por($h)->redondear();
                $L[] = ['id' => 'hx'.count($L), 'cod' => '10', 'concepto' => 'Horas extraordinarias', 'cant' => $h->float(), 'precio' => $precio->redondear(4)->float(), 'importe' => $imp->float(),
                    'clase' => 'hx', 'origen' => 'calendario', 'conv' => null, 'expl' => $hTxt.' × '.$precio->txt(4).' € = '.$imp->txt().' € · '.$orig,
                    'regla' => 'Convenio · horas extraordinarias'.(! empty($hx['art']) ? ' · '.$hx['art'] : '')];
            } elseif ($r0->tipo === 'complementaria') {
                if (! $parcial) {
                    $av[] = ['error', $hTxt.' complementarias: solo existen en contratos a tiempo parcial (art. 12.5 ET).'];

                    continue;
                }
                if (! $ho) {
                    $av[] = ['error', $hTxt.' complementarias: no se puede calcular la hora ordinaria ('.$hoTxt.').'];

                    continue;
                }
                $imp = $ho->por($h)->redondear();
                $L[] = ['id' => 'hc'.count($L), 'cod' => '15', 'concepto' => 'Horas complementarias', 'cant' => $h->float(), 'precio' => $ho->redondear(4)->float(), 'importe' => $imp->float(),
                    'clase' => 'salarial', 'origen' => 'calendario', 'conv' => null, 'expl' => $hTxt.' × '.$ho->txt(4).' € = '.$imp->txt().' € · hora ordinaria ('.$hoTxt.')',
                    'regla' => 'Art. 12.5 ET: se retribuyen como ordinarias'];
            } else {
                $p = collect($w['_conv']['pluses'] ?? [])->first(fn ($x) => $x['nombre'] === $r0->concepto && $x['unidad'] === 'hora');
                if (! $p || ($p['soportado'] ?? true) === false || ($p['tipo'] ?? '') === 'porcentaje_base') {
                    $av[] = ['error', $hTxt.' de «'.$r0->concepto.'»: el convenio no tiene ese concepto por hora con un importe o una fórmula que el motor pueda calcular.'];

                    continue;
                }
                try {
                    if (($p['calculo'] ?? '') === 'formula') {
                        [$precio, $txtF] = Formula::evaluar($p['formula'], $ctx + ['cantidad' => $h]);
                        $orig = 'fórmula «'.$p['formula'].'» ('.$txtF.')';
                    } else {
                        $porCat = isset($p['importes'][$cat]);
                        $precio = Dec::de($porCat ? $p['importes'][$cat] : $p['valor']);
                        $orig = $porCat ? 'importe de la categoría «'.$cat.'»' : 'importe general';
                    }
                } catch (\Throwable) {
                    $av[] = ['error', $hTxt.' de «'.$r0->concepto.'»: a la fórmula le falta un dato.'];

                    continue;
                }
                $imp = $precio->por($h)->redondear(($p['redondeo'] ?? '2d') === '0d' ? 0 : 2);
                $base = ['cod' => '06', 'cant' => $h->float(), 'precio' => $precio->redondear(4)->float(), 'origen' => 'convenio', 'conv' => null,
                    'regla' => 'Convenio'.(($w['_conv']['version'] ?? null) ? ' · '.$w['_conv']['version'] : '').' · '.$p['nombre'].(($p['art'] ?? '') ? ' · '.$p['art'] : '')];
                foreach (Nomina::partirLimite($p, $imp, $cal['trabajados']) as $j => [$parte, $clase, $suf]) {
                    $L[] = $base + ['id' => 'hp'.count($L).($j ? 'x' : ''), 'concepto' => $p['nombre'].$suf, 'importe' => $parte->float(), 'clase' => $clase,
                        'expl' => $hTxt.' × '.$precio->txt(4).' € = '.$imp->txt().' € · '.$orig];
                }
            }
        }

        return [$L, $av];
    }

    // ------------------------------------------------------------------ propuestas desde el control horario
    /**
     * Exceso diario de jornada según los fichajes (minutos trabajados − jornada teórica del día), solo en días cerrados.
     * Es una propuesta: no entra en la nómina hasta que se revisa y se lleva como horas.
     */
    public static function excesoFichajes(array $w, array $cal): array
    {
        if (empty($w['id'])) {
            return [];
        }
        $h = Nomina::n($w['horas'] ?? 0);
        $nd = count(self::diasSemana($w));
        $teo = $h > 0 ? (int) round($h * 60 / max(1, $nd)) : (int) round(480 * (Nomina::n($w['jor'] ?? 100) ?: 100) / 100);
        $hoy = now()->toDateString();
        $ya = LaboralHora::withoutGlobalScopes()->where('trabajador_id', $w['id'])->where('origen', 'control_horario')->whereBetween('fecha', [$cal['desde'], $cal['hasta']])->get()
            ->map(fn ($r) => $r->fecha->toDateString())->all();
        $out = [];
        foreach (JornadaDia::withoutGlobalScopes()->where('trabajador_id', $w['id'])->whereBetween('fecha', [$cal['desde'], $cal['hasta']])->orderBy('fecha')->get() as $d) {
            $f = Carbon::parse($d->fecha)->toDateString();
            if ($f >= $hoy || in_array($f, $ya, true) || collect($d->tramos)->contains(fn ($x) => empty($x['out']))) {
                continue;
            }
            $mins = ControlHorarioController::minutos($d->tramos ?? [], false, 0);
            $dia = $cal['dias'][$f] ?? null;
            $t = $dia && $dia['lab'] && ! $dia['aus'] ? $teo : 0;
            if ($mins - $t >= 15) {
                $out[] = ['fecha' => $f, 'mins' => $mins, 'teo' => $t, 'exceso' => round(($mins - $t) / 60, 2)];
            }
        }

        return $out;
    }
}
