<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\JornadaAusencia;
use App\Models\JornadaCorreccion;
use App\Models\JornadaDia;
use App\Models\Trabajador;
use App\Services\Auditoria;
use App\Services\Nomina;
use App\Support\Formato;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Control horario (prototipo): registro diario de jornada de los trabajadores de la empresa elegida
 * (art. 34.9 ET). Las correcciones quedan trazadas con su motivo; el registro no se borra.
 */
class ControlHorarioController extends Controller
{
    public const TIPOS = ['Vacaciones', 'Incapacidad temporal', 'Permiso retribuido', 'Asuntos propios', 'Ausencia injustificada'];

    public const DOW = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];

    private function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    private function sinEmpresa(): RedirectResponse
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/control-horario'])->with('aviso', 'Elige primero la empresa cuyo registro de jornada vas a llevar.');
    }

    public static function hm(int|float $m): string
    {
        $neg = $m < 0;
        $m = (int) abs(round($m));

        return ($neg ? '−' : '').intdiv($m, 60).':'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
    }

    private static function min(?string $s): ?int
    {
        return $s && preg_match('/^(\d{1,2}):(\d{2})$/', $s, $m) ? (int) $m[1] * 60 + (int) $m[2] : null;
    }

    /** Trabajadores de la plantilla con su jornada diaria teórica en minutos */
    private function trabajadores(Cliente $c): Collection
    {
        return Nomina::asalariados($c)->map(function ($w) {
            $h = Nomina::n($w['horas'] ?? 0);
            $w['dia'] = $h > 0 ? (int) round($h * 60 / 5) : (int) round(480 * (Nomina::n($w['jor']) ?: 100) / 100);
            $w['jorTxt'] = (Nomina::parcial($w) ? 'Parcial ' : 'Completa ').Formato::num(Nomina::n($w['horas']), 0).' h/sem';

            return $w;
        })->values();
    }

    private static function activoEn(array $w, string $f): bool
    {
        return ($w['alta'] ?? '9999') <= $f && (empty($w['baja']) || $w['baja'] >= $f);
    }

    public static function minutos(array $tramos, bool $hoy, int $ahora): int
    {
        $t = 0;
        foreach ($tramos as $x) {
            $i = self::min($x['in'] ?? null);
            $o = ! empty($x['out']) ? self::min($x['out']) : ($hoy ? $ahora : null);
            $t += $i !== null && $o !== null && $o > $i ? $o - $i : 0;
        }

        return $t;
    }

    /** Días de un mes hasta hoy para un trabajador */
    private function mes(array $w, string $ym, Collection $dias, Collection $aus, CarbonImmutable $ahora): array
    {
        $ini = CarbonImmutable::parse($ym.'-01');
        $hoy = $ahora->toDateString();
        $nowM = $ahora->hour * 60 + $ahora->minute;
        $out = [];
        for ($d = $ini; $d->month === $ini->month && $d->toDateString() <= $hoy; $d = $d->addDay()) {
            $f = $d->toDateString();
            $a = $aus->first(fn ($x) => $x->trabajador_id === $w['id'] && $x->desde->toDateString() <= $f && $x->hasta->toDateString() >= $f);
            $lab = Plazos::esHabil($d) && self::activoEn($w, $f);
            $tr = $dias->get($w['id'].'|'.$f)?->tramos ?? [];
            $mins = self::minutos($tr, $f === $hoy, $nowM);
            $out[] = ['f' => $f, 'wd' => $d->dayOfWeek, 'lab' => $lab, 'aus' => $a, 'tramos' => $tr, 'mins' => $mins, 'teo' => $lab && ! $a ? $w['dia'] : 0,
                'fest' => ! $d->isWeekend() && ! Plazos::esHabil($d) ? (Plazos::festivos($d->year)[$f] ?? 'Festivo') : null];
        }

        return $out;
    }

    public static function tramosTxt(array $tr): string
    {
        return implode(' · ', array_map(fn ($x) => $x['in'].'–'.($x['out'] ?? '…'), $tr));
    }

    public function index(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $ahora = CarbonImmutable::now(config('app.timezone'));
        $hoy = $ahora->toDateString();
        $nowM = $ahora->hour * 60 + $ahora->minute;
        $tab = in_array($request->query('tab'), ['hoy', 'reg', 'res', 'aus'], true) ? $request->query('tab') : 'hoy';
        $ym = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('ym')) ? $request->query('ym') : $ahora->format('Y-m');
        $W = $this->trabajadores($c);
        $ini = $ym.'-01';
        $fin = CarbonImmutable::parse($ini)->endOfMonth()->toDateString();
        $W = $W->filter(fn ($w) => $tab === 'hoy' ? self::activoEn($w, $hoy) : (($w['alta'] ?? '9999') <= $fin && (empty($w['baja']) || $w['baja'] >= $ini)))->values();
        $desde = min($ini, $hoy);
        $dias = JornadaDia::where('cliente_id', $c->id)->whereDate('fecha', '>=', $desde)->whereDate('fecha', '<=', max($fin, $hoy))->get()->keyBy(fn ($d) => $d->trabajador_id.'|'.$d->fecha->toDateString());
        $aus = JornadaAusencia::where('cliente_id', $c->id)->orderByDesc('desde')->get();
        $base = ['titulo' => 'Control horario', 'activo' => 'horario', 'c' => $c, 'tab' => $tab, 'ym' => $ym, 'W' => $W, 'ahora' => $ahora,
            'meses' => collect(range(0, 11))->map(fn ($i) => $ahora->startOfMonth()->subMonths($i))->map(fn ($m) => [$m->format('Y-m'), Nomina::MESES[$m->month - 1].' '.$m->year])->all()];

        if ($tab === 'hoy') {
            $hoyL = $W->map(function ($w) use ($dias, $aus, $hoy, $nowM) {
                $a = $aus->first(fn ($x) => $x->trabajador_id === $w['id'] && $x->desde->toDateString() <= $hoy && $x->hasta->toDateString() >= $hoy);
                $tr = $dias->get($w['id'].'|'.$hoy)?->tramos ?? [];
                $last = $tr ? end($tr) : null;
                $st = $a ? $a->tipo : (! $last ? 'Sin fichar' : (empty($last['out']) ? 'Trabajando desde '.$last['in'] : (! empty($last['p']) ? 'En pausa desde '.$last['out'] : 'Salida a las '.$last['out'])));

                return ['w' => $w, 'st' => $st, 'aus' => (bool) $a, 'tramos' => self::tramosTxt($tr) ?: '—', 'horas' => self::hm(self::minutos($tr, true, $nowM)).' de '.self::hm($w['dia']),
                    'dot' => $a ? '#9A9A94' : ($last && empty($last['out']) ? '#4CAF50' : ($last && ! empty($last['p']) ? '#F5A524' : '#CFCFC8')),
                    'in' => ! $a && (! $last || (! empty($last['out']) && empty($last['p']))), 'pause' => ! $a && $last && empty($last['out']),
                    'resume' => ! $a && $last && ! empty($last['out']) && ! empty($last['p']), 'out' => ! $a && $last && (empty($last['out']) || ! empty($last['p']))];
            });

            return view('horario.index', $base + ['hoyL' => $hoyL]);
        }
        if ($tab === 'reg') {
            $w = $W->firstWhere('id', (int) $request->query('t')) ?? $W->first();
            $days = $w ? $this->mes($w, $ym, $dias, $aus, $ahora) : [];
            $corr = $w ? JornadaCorreccion::where('trabajador_id', $w['id'])->whereDate('fecha', '>=', $ini)->whereDate('fecha', '<=', $fin)->with('trabajador')->orderBy('id')->get()->groupBy(fn ($x) => $x->fecha->toDateString()) : collect();
            if ($w && in_array($request->query('exportar'), ['excel', 'csv'], true)) {
                $tot = array_sum(array_column($days, 'mins'));
                $teo = array_sum(array_column($days, 'teo'));
                $filas = array_map(fn ($d) => [Formato::fecha($d['f']), self::tramosTxt($d['tramos']) ?: ($d['aus'] ? $d['aus']->tipo : (! $d['lab'] ? 'Descanso' : '')), $d['mins'] ? self::hm($d['mins']) : '', $d['teo'] ? self::hm($d['teo']) : '', $d['teo'] || $d['mins'] ? self::hm($d['mins'] - $d['teo']) : '',
                    ($corr->get($d['f'])?->last()?->motivo) ?? ''], $days);
                $filas[] = ['', 'Total del mes', self::hm($tot), self::hm($teo), self::hm($tot - $teo), ''];

                return $this->csv($request->query('exportar'), 'registro-jornada-'.$ym.'-'.$w['nif'], 'Registro de jornada · '.$w['nombre'].' · '.$w['nif'].' · '.$c->nombre.' · '.$ym,
                    ['Día', 'Entradas y salidas', 'Horas', 'Jornada', 'Diferencia', 'Corrección'], $filas);
            }

            return view('horario.index', $base + ['w' => $w, 'days' => $days, 'corr' => $corr, 'edit' => (string) $request->query('editar', '')]);
        }
        if ($tab === 'res') {
            $res = $W->map(function ($w) use ($ym, $dias, $aus, $ahora) {
                $ds = $this->mes($w, $ym, $dias, $aus, $ahora);
                $m = array_sum(array_column($ds, 'mins'));
                $t = array_sum(array_column($ds, 'teo'));

                return ['w' => $w, 'dias' => count(array_filter($ds, fn ($d) => $d['mins'] > 0)), 'mins' => $m, 'teo' => $t, 'aus' => count(array_filter($ds, fn ($d) => $d['aus'] && Plazos::esHabil(CarbonImmutable::parse($d['f']))))];
            });
            if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
                return $this->csv($request->query('exportar'), 'resumen-jornada-'.$ym, 'Resumen de jornada · '.$c->nombre.' · '.$ym, ['Trabajador', 'NIF', 'Jornada', 'Días trabajados', 'Horas', 'Teóricas', 'Diferencia', 'Días de ausencia'],
                    $res->map(fn ($r) => [$r['w']['nombre'], $r['w']['nif'], $r['w']['jorTxt'], $r['dias'], self::hm($r['mins']), self::hm($r['teo']), self::hm($r['mins'] - $r['teo']), $r['aus']])->all());
            }

            return view('horario.index', $base + ['res' => $res]);
        }
        $anio = $ahora->year;
        $todos = $this->trabajadores($c);
        $vac = $todos->filter(fn ($w) => $w['estado'] === 'Alta')->map(function ($w) use ($aus, $anio) {
            $n = $aus->filter(fn ($a) => $a->trabajador_id === $w['id'] && $a->tipo === 'Vacaciones')->sum(function ($a) use ($anio) {
                $d = max($a->desde, CarbonImmutable::create($anio, 1, 1));
                $h = min($a->hasta, CarbonImmutable::create($anio, 12, 31));

                return $h->gte($d) ? (int) $d->diffInDays($h) + 1 : 0;
            });

            return ['w' => $w, 'n' => $n, 'max' => (int) ($w['vac'] ?: 30)];
        });

        return view('horario.index', $base + ['aus' => $aus->filter(fn ($a) => $todos->contains('id', $a->trabajador_id))->values(), 'todos' => $todos, 'vac' => $vac, 'anio' => $anio]);
    }

    private function dia(Cliente $c, int $tid, string $fecha): JornadaDia
    {
        return JornadaDia::where('trabajador_id', $tid)->whereDate('fecha', $fecha)->first()
            ?? new JornadaDia(['cliente_id' => $c->id, 'trabajador_id' => $tid, 'fecha' => $fecha]);
    }

    private function trabajador(Cliente $c, Trabajador $t): array
    {
        abort_unless($t->cliente_id === $c->id, 404);

        return $this->trabajadores($c)->firstWhere('id', $t->id);
    }

    public function fichar(Request $request, Trabajador $trabajador, string $accion)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        abort_unless(in_array($accion, ['entrada', 'pausa', 'reanudar', 'salida'], true), 404);
        $w = $this->trabajador($c, $trabajador);
        $ahora = CarbonImmutable::now(config('app.timezone'));
        $hoy = $ahora->toDateString();
        $hora = $ahora->format('H:i');
        $volver = redirect()->route('horario.index');
        if (! self::activoEn($w, $hoy)) {
            return $volver->withErrors(['horario' => $w['nombre'].' no está de alta hoy.']);
        }
        if (JornadaAusencia::where('trabajador_id', $w['id'])->whereDate('desde', '<=', $hoy)->whereDate('hasta', '>=', $hoy)->exists()) {
            return $volver->withErrors(['horario' => $w['nombre'].' tiene una ausencia registrada hoy.']);
        }
        $dia = $this->dia($c, $w['id'], $hoy);
        $tr = $dia->tramos ?? [];
        $n = count($tr);
        $last = $n ? $tr[$n - 1] : null;
        $ok = match ($accion) {
            'entrada' => ! $last || (! empty($last['out']) && empty($last['p'])),
            'pausa' => $last && empty($last['out']),
            'reanudar' => $last && ! empty($last['out']) && ! empty($last['p']),
            'salida' => $last && (empty($last['out']) || ! empty($last['p'])),
        };
        if (! $ok) {
            return $volver->withErrors(['horario' => 'Esa marca no corresponde al estado actual de '.$w['nombre'].'.']);
        }
        if ($last && in_array($accion, ['pausa', 'salida'], true) && empty($last['out']) && self::min($hora) <= self::min($last['in'])) {
            $hora = $last['in'];
        }
        switch ($accion) {
            case 'entrada':
                $tr[] = ['in' => $hora, 'out' => null, 'p' => false];
                break;
            case 'pausa':
                $tr[$n - 1]['out'] = $hora;
                $tr[$n - 1]['p'] = true;
                break;
            case 'reanudar':
                $tr[$n - 1]['p'] = false;
                $tr[] = ['in' => $hora, 'out' => null, 'p' => false];
                break;
            case 'salida':
                if (empty($tr[$n - 1]['out'])) {
                    $tr[$n - 1]['out'] = $hora;
                }
                $tr[$n - 1]['p'] = false;
                break;
        }
        $dia->tramos = $tr;
        $dia->updated_by = $request->user()->id;
        $dia->save();
        $txt = ['entrada' => 'entrada registrada a las ', 'pausa' => 'pausa a las ', 'reanudar' => 'vuelve de la pausa a las ', 'salida' => 'salida registrada a las '][$accion];

        return $volver->with('ok', $w['nombre'].': '.$txt.$hora.'.');
    }

    public function corregir(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $w = $this->trabajador($c, $trabajador);
        $fecha = (string) $request->input('fecha');
        $ym = substr($fecha, 0, 7);
        $volver = fn () => redirect()->route('horario.index', ['tab' => 'reg', 't' => $w['id'], 'ym' => $ym, 'editar' => $fecha])->withInput();
        $hoy = Plazos::hoy()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > $hoy) {
            return $volver()->withErrors(['horario' => 'No se pueden registrar jornadas de días futuros.']);
        }
        $motivo = mb_substr(trim((string) $request->input('motivo')), 0, 255);
        if ($motivo === '') {
            return $volver()->withErrors(['horario' => 'Indica el motivo de la corrección: queda registrado.']);
        }
        $txt = trim((string) $request->input('txt'));
        $tr = [];
        $prev = -1;
        foreach (array_filter(array_map('trim', explode(',', $txt))) as $x) {
            $p = array_map('trim', explode('-', $x, 2));
            $i = self::min($p[0] ?? null);
            $o = ($p[1] ?? '') !== '' ? self::min($p[1]) : null;
            $abierto = ($p[1] ?? '') === '';
            if ($i === null || (! $abierto && ($o === null || $o <= $i)) || $i < $prev || ($abierto && $fecha !== $hoy)) {
                return $volver()->withErrors(['horario' => 'Formato: 08:00-14:00, 15:00-17:00 (horas en orden y sin solaparse; solo hoy puede quedar un tramo abierto).']);
            }
            $prev = $o ?? $i;
            $fmt = fn ($m) => str_pad((string) intdiv($m, 60), 2, '0', STR_PAD_LEFT).':'.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT);
            $tr[] = ['in' => $fmt($i), 'out' => $o === null ? null : $fmt($o), 'p' => false];
        }
        $dia = $this->dia($c, $w['id'], $fecha);
        $antes = str_replace('–', '-', self::tramosTxt($dia->tramos ?? []));
        $despues = str_replace('–', '-', self::tramosTxt($tr));
        if ($antes === $despues) {
            return redirect()->route('horario.index', ['tab' => 'reg', 't' => $w['id'], 'ym' => $ym])->with('ok', 'Sin cambios en el registro.');
        }
        $dia->tramos = $tr;
        $dia->updated_by = $request->user()->id;
        $dia->save();
        JornadaCorreccion::create(['cliente_id' => $c->id, 'trabajador_id' => $w['id'], 'fecha' => $fecha, 'antes' => $antes ?: null, 'despues' => $despues ?: null, 'motivo' => $motivo, 'user_id' => $request->user()->id]);
        Auditoria::registrar('horario.corregir', 'Registro de jornada de '.$w['nombre'].' del '.Formato::fecha($fecha).' corregido: «'.($antes ?: 'sin registro').'» → «'.($despues ?: 'sin registro').'» ('.$motivo.')', $trabajador, $c->id);

        return redirect()->route('horario.index', ['tab' => 'reg', 't' => $w['id'], 'ym' => $ym])->with('ok', 'Registro corregido. El cambio queda trazado con su motivo.');
    }

    public function ausencia(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $t = Trabajador::where('cliente_id', $c->id)->find((int) $request->input('trabajador'));
        $desde = (string) $request->input('desde');
        $hasta = (string) $request->input('hasta');
        $tipo = in_array($request->input('tipo'), self::TIPOS, true) ? $request->input('tipo') : null;
        $err = ! $t ? 'Elige el trabajador.' : (! $tipo ? 'Elige el tipo de ausencia.' : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? 'Indica las fechas.' : ($hasta < $desde ? 'La fecha final no puede ser anterior a la inicial.' : '')));
        if (! $err && JornadaAusencia::where('trabajador_id', $t->id)->whereDate('desde', '<=', $hasta)->whereDate('hasta', '>=', $desde)->exists()) {
            $err = 'Ya hay una ausencia de '.($t->datos['nombre'] ?? '').' que coincide con esas fechas.';
        }
        if ($err) {
            return redirect()->route('horario.index', ['tab' => 'aus'])->withInput()->withErrors(['horario' => $err]);
        }
        JornadaAusencia::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'tipo' => $tipo, 'desde' => $desde, 'hasta' => $hasta, 'created_by' => $request->user()->id]);
        Auditoria::registrar('horario.ausencia', $tipo.' de '.($t->datos['nombre'] ?? '').' del '.Formato::fecha($desde).' al '.Formato::fecha($hasta), $t, $c->id);

        return redirect()->route('horario.index', ['tab' => 'aus'])->with('ok', 'Ausencia registrada.');
    }

    public function borrarAusencia(Request $request, JornadaAusencia $ausencia)
    {
        $c = $this->ctx($request);
        abort_unless($c && $ausencia->cliente_id === $c->id, 404);
        Auditoria::registrar('horario.ausencia.borrar', $ausencia->tipo.' del '.Formato::fecha($ausencia->desde).' al '.Formato::fecha($ausencia->hasta).' eliminada', $ausencia->trabajador, $c->id);
        $ausencia->delete();

        return redirect()->route('horario.index', ['tab' => 'aus'])->with('ok', 'Ausencia eliminada.');
    }

    private function csv(string $fmt, string $nombre, string $titulo, array $cab, array $filas)
    {
        $sep = $fmt === 'excel' ? ';' : ',';

        return response()->streamDownload(function () use ($titulo, $cab, $filas, $sep) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, [$titulo], $sep);
            fputcsv($o, $cab, $sep);
            foreach ($filas as $f) {
                fputcsv($o, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $f), $sep);
            }
            fclose($o);
        }, $nombre.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
