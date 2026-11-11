<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Fichaje;
use App\Models\Solicitud;
use App\Models\Tarea;
use App\Models\User;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Tareas del equipo y registro de jornada del despacho (art. 34.9 ET). */
class TareaController extends Controller
{
    // ================================================================ tareas
    public function index(Request $request)
    {
        $u = $request->user();
        $hoy = Plazos::hoy();
        $todo = $request->query('ver') === 'equipo';
        $verHechas = $request->boolean('hechas');
        // Empresa elegida: con un cliente, solo sus tareas; con el despacho, todas
        $ctx = $request->attributes->get('ctx');
        $cli = $ctx && ! $ctx->es_despacho ? $ctx->id : null;
        $base = Tarea::with(['cliente', 'responsable'])->when(! $todo, fn ($q) => $q->where('responsable_id', $u->id))->when($cli, fn ($q) => $q->where('cliente_id', $cli));
        $pend = (clone $base)->where('hecha', false)->orderByRaw('fecha is null')->orderBy('fecha')->get();
        $finSemana = $hoy->endOfWeek(CarbonImmutable::SUNDAY);
        $grupos = [
            ['Vencidas', $pend->filter(fn ($t) => $t->fecha && $t->fecha->lt($hoy)), true],
            ['Hoy', $pend->filter(fn ($t) => $t->fecha && $t->fecha->isSameDay($hoy)), false],
            ['Esta semana', $pend->filter(fn ($t) => $t->fecha && $t->fecha->gt($hoy) && $t->fecha->lte($finSemana)), false],
            ['Más adelante', $pend->filter(fn ($t) => $t->fecha && $t->fecha->gt($finSemana)), false],
            ['Sin fecha', $pend->filter(fn ($t) => ! $t->fecha), false],
        ];
        if ($verHechas) {
            $grupos[] = ['Hechas (últimos 30 días)', (clone $base)->where('hecha', true)->where('hecha_at', '>=', now()->subDays(30))->orderByDesc('hecha_at')->get(), false];
        }
        $equipo = User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get();
        $cargaTodas = Tarea::where('hecha', false)->when($cli, fn ($q) => $q->where('cliente_id', $cli))->get()->groupBy('responsable_id');
        $maxCarga = max(1, $cargaTodas->map->count()->max() ?? 1);

        return view('tareas.index', [
            'titulo' => 'Tareas y horario', 'activo' => 'tareas', 'hoy' => $hoy, 'todo' => $todo, 'verHechas' => $verHechas, 'grupos' => $grupos,
            'resumen' => [['Pendientes', $pend->count()], ['Vencidas', $grupos[0][1]->count()], ['Para hoy', $grupos[1][1]->count()], ['Hechas esta semana', (clone $base)->where('hecha', true)->where('hecha_at', '>=', $hoy->startOfWeek())->count()]],
            'carga' => $equipo->map(fn ($e) => ['n' => $e->name, 'c' => $cargaTodas->get($e->id)?->count() ?? 0, 'w' => round((($cargaTodas->get($e->id)?->count() ?? 0) / $maxCarga) * 100)]),
            'recurrentes' => Tarea::where('hecha', false)->when($cli, fn ($q) => $q->where('cliente_id', $cli))->whereNotNull('repetir')->orderBy('fecha')->limit(20)->get(),
            'equipo' => $equipo, 'clientes' => $cli ? collect([$ctx]) : Cliente::where('estado', '!=', 'Baja')->orderByDesc('es_despacho')->orderBy('nombre')->get(['id', 'nombre']), 'ctxCli' => $cli,
            'nueva' => $request->boolean('nueva'), 'par' => Parametros::get('tareas'),
            'url' => fn (array $x = []) => route('tareas.index', array_filter(array_merge(['ver' => $todo ? 'equipo' : null, 'hechas' => $verHechas ? 1 : null], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function guardar(Request $request)
    {
        $d = $request->validate([
            'titulo' => ['required', 'string', 'max:200'], 'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
            'responsable_id' => ['required', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)], 'fecha' => ['nullable', 'date'],
            'prioridad' => ['required', Rule::in(array_keys(Tarea::PRIORIDADES))], 'repetir' => ['nullable', Rule::in(array_keys(Tarea::REPETIR))],
        ], ['titulo.required' => 'Escribe la tarea.']);
        if (! empty($d['repetir']) && empty($d['fecha'])) {
            return back()->withInput()->withErrors(['fecha' => 'Una tarea que se repite necesita fecha.']);
        }
        $t = Tarea::create(['repetir' => ($d['repetir'] ?? '') ?: null] + $d + ['created_by' => $request->user()->id]);
        Auditoria::registrar('tarea.alta', 'Tarea: '.$t->titulo, $t, $t->cliente_id);

        return redirect()->route('tareas.index', $t->responsable_id === $request->user()->id ? [] : ['ver' => 'equipo'])->with('ok', 'Tarea creada.');
    }

    public function hecha(Request $request, Tarea $tarea)
    {
        $tarea->hecha = ! $tarea->hecha;
        $tarea->hecha_at = $tarea->hecha ? now() : null;
        $tarea->save();
        $msg = $tarea->hecha ? 'Tarea hecha.' : 'Tarea pendiente otra vez.';
        // Tareas que se repiten: al terminarla se crea la siguiente
        if ($tarea->hecha && $tarea->repetir && $tarea->fecha) {
            $sig = match ($tarea->repetir) {
                'semanal' => $tarea->fecha->addWeek(),
                'mensual' => $tarea->fecha->addMonthNoOverflow(),
                default => $tarea->fecha->addMonthsNoOverflow(3),
            };
            if (! Tarea::where('titulo', $tarea->titulo)->where('hecha', false)->whereDate('fecha', $sig)->exists()) {
                Tarea::create($tarea->only(['titulo', 'cliente_id', 'responsable_id', 'prioridad', 'repetir']) + ['fecha' => $sig->toDateString(), 'created_by' => $request->user()->id]);
                $msg .= ' La siguiente queda para el '.Formato::fecha($sig).'.';
            }
        }

        return back()->with('ok', $msg);
    }

    public function borrar(Request $request, Tarea $tarea)
    {
        abort_unless($request->user()->esAdmin() || $tarea->created_by === $request->user()->id, 403);
        $tarea->delete();

        return back()->with('ok', 'Tarea eliminada.');
    }

    // ============================================================ jornada
    /** Minutos trabajados y estado a partir de las marcas de un día */
    public static function calcular(Collection $marcas, ?CarbonImmutable $ahora = null): array
    {
        $min = 0;
        $pausa = 0;
        $desde = null;
        $pausaDesde = null;
        $estado = 'fuera';
        foreach ($marcas->sortBy('momento') as $m) {
            $t = CarbonImmutable::parse($m->momento);
            switch ($m->tipo) {
                case 'entrada':
                case 'reanuda':
                    if ($pausaDesde) {
                        $pausa += $pausaDesde->diffInMinutes($t);
                        $pausaDesde = null;
                    }
                    $desde = $t;
                    $estado = 'trabajando';
                    break;
                case 'pausa':
                    if ($desde) {
                        $min += $desde->diffInMinutes($t);
                    }
                    $desde = null;
                    $pausaDesde = $t;
                    $estado = 'pausa';
                    break;
                case 'salida':
                    if ($desde) {
                        $min += $desde->diffInMinutes($t);
                    }
                    $desde = null;
                    $pausaDesde = null;
                    $estado = 'fuera';
                    break;
            }
        }
        if ($ahora && $desde) {
            $min += $desde->diffInMinutes($ahora);
        }
        if ($ahora && $pausaDesde) {
            $pausa += $pausaDesde->diffInMinutes($ahora);
        }
        $entrada = $marcas->firstWhere('tipo', 'entrada');
        $salida = $marcas->where('tipo', 'salida')->last();

        return ['min' => (int) round($min), 'pausa' => (int) round($pausa), 'estado' => $estado,
            'entrada' => $entrada ? CarbonImmutable::parse($entrada->momento)->timezone(config('app.timezone'))->format('H:i') : null,
            'salida' => $salida ? CarbonImmutable::parse($salida->momento)->timezone(config('app.timezone'))->format('H:i') : null];
    }

    public static function hm(int $min): string
    {
        return intdiv($min, 60).' h '.str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT).' min';
    }

    private function marcasDia(int $uid, CarbonImmutable $d): Collection
    {
        $tz = config('app.timezone');
        $ini = CarbonImmutable::parse($d->toDateString().' 00:00:00', $tz);

        return Fichaje::where('user_id', $uid)->whereBetween('momento', [$ini, $ini->addDay()->subSecond()])->orderBy('momento')->get();
    }

    public function horario(Request $request)
    {
        $u = $request->user();
        $hoy = Plazos::hoy();
        $ahora = CarbonImmutable::now();
        $par = Parametros::get('tareas');
        $marcasHoy = $this->marcasDia($u->id, $hoy);
        $calc = self::calcular($marcasHoy, $ahora);
        $jornadaDia = (int) round((float) $par['jornada_diaria'] * 60);

        $lunes = $hoy->startOfWeek(CarbonImmutable::MONDAY);
        $semana = [];
        $total = 0;
        $teorico = 0;
        foreach (range(0, 4) as $i) {
            $d = $lunes->addDays($i);
            $fest = Plazos::festivos($d->year)[$d->toDateString()] ?? null;
            $c = $d->lte($hoy) ? self::calcular($this->marcasDia($u->id, $d), $d->isSameDay($hoy) ? $ahora : null) : ['min' => 0, 'pausa' => 0, 'entrada' => null, 'salida' => null];
            $total += $c['min'];
            $teorico += $fest ? 0 : $jornadaDia;
            $semana[] = ['d' => $d, 'fest' => $fest, 'hoy' => $d->isSameDay($hoy)] + $c;
        }

        $equipo = User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get()->map(function ($e) use ($hoy, $ahora) {
            $c = self::calcular($this->marcasDia($e->id, $hoy), $ahora);

            return ['u' => $e, 'estado' => $c['estado'], 'det' => $c['entrada'] ? 'Desde las '.$c['entrada'].' · '.self::hm($c['min']) : 'Sin fichar hoy'];
        });

        return view('tareas.horario', [
            'titulo' => 'Tareas y horario', 'activo' => 'tareas', 'hoy' => $hoy, 'calc' => $calc, 'marcasHoy' => $marcasHoy, 'jornadaDia' => $jornadaDia,
            'semana' => $semana, 'semTotal' => $total, 'semTeorico' => $teorico, 'equipo' => $equipo, 'esAdmin' => $u->esAdmin(),
            'solicitudes' => Solicitud::with('usuario')->when(! $u->esAdmin(), fn ($q) => $q->where('user_id', $u->id))->orderByRaw("estado = 'Pendiente' desc")->orderByDesc('id')->limit(30)->get(),
            'vacUsadas' => Solicitud::where('user_id', $u->id)->where('tipo', 'Vacaciones')->where('estado', 'Aprobada')->whereYear('desde', $hoy->year)->get()->sum(fn ($s) => $this->habiles($s->desde, $s->hasta)),
            'vacTotal' => (int) $par['vacaciones'],
        ]);
    }

    private function habiles(CarbonImmutable $a, CarbonImmutable $b): int
    {
        $n = 0;
        for ($d = $a; $d->lte($b); $d = $d->addDay()) {
            if (Plazos::esHabil($d)) {
                $n++;
            }
        }

        return $n;
    }

    public function fichar(Request $request)
    {
        $d = $request->validate(['tipo' => ['required', Rule::in(['entrada', 'pausa', 'reanuda', 'salida'])]]);
        $u = $request->user();
        $estado = self::calcular($this->marcasDia($u->id, Plazos::hoy()))['estado'];
        $valido = ['fuera' => ['entrada'], 'trabajando' => ['pausa', 'salida'], 'pausa' => ['reanuda', 'salida']][$estado];
        if (! in_array($d['tipo'], $valido, true)) {
            return back()->with('aviso', 'Esa marca no corresponde ahora (estado: '.$estado.').');
        }
        Fichaje::create(['user_id' => $u->id, 'tipo' => $d['tipo'], 'momento' => now(), 'ip' => $request->ip()]);
        $txt = ['entrada' => 'Entrada registrada', 'pausa' => 'Pausa iniciada', 'reanuda' => 'Jornada reanudada', 'salida' => 'Salida registrada'][$d['tipo']];

        return back()->with('ok', $txt.' a las '.now()->timezone(config('app.timezone'))->format('H:i').'.');
    }

    public function solicitar(Request $request)
    {
        $d = $request->validate([
            'tipo' => ['required', Rule::in(Solicitud::TIPOS)], 'desde' => ['required', 'date'], 'hasta' => ['required', 'date', 'after_or_equal:desde'],
            'motivo' => ['nullable', 'string', 'max:255', 'required_if:tipo,Corrección de fichaje'],
        ], ['motivo.required_if' => 'Explica qué hay que corregir (hora real de entrada o salida).', 'hasta.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.']);
        $s = Solicitud::create($d + ['user_id' => $request->user()->id]);
        Auditoria::registrar('jornada.solicitud', $s->tipo.' '.Formato::fecha($s->desde).' – '.Formato::fecha($s->hasta), $s);

        return back()->with('ok', 'Solicitud enviada. La administración la aprobará o rechazará.');
    }

    public function resolver(Request $request, Solicitud $solicitud)
    {
        abort_unless($request->user()->esAdmin(), 403);
        $d = $request->validate(['estado' => ['required', Rule::in(['Aprobada', 'Rechazada'])]]);
        abort_unless($solicitud->estado === 'Pendiente', 422);
        $solicitud->update(['estado' => $d['estado'], 'resuelto_por' => $request->user()->id, 'resuelto_at' => now()]);
        Auditoria::registrar('jornada.solicitud', $solicitud->tipo.' de '.$solicitud->usuario?->name.': '.$d['estado'], $solicitud);

        return back()->with('ok', 'Solicitud '.mb_strtolower($d['estado']).'.');
    }

    /** Registro mensual de jornada (CSV). La administración puede sacar el de todo el equipo. */
    public function exportar(Request $request)
    {
        $u = $request->user();
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('m')) ? CarbonImmutable::parse($request->query('m').'-01') : Plazos::hoy()->startOfMonth();
        $usuarios = $u->esAdmin() ? User::whereIn('role', User::ROLES_DESPACHO)->orderBy('name')->get() : collect([$u]);
        Auditoria::registrar('jornada.exportacion', 'Registro de jornada '.$mes->format('m/Y'));

        return response()->streamDownload(function () use ($usuarios, $mes) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, ['Trabajador', 'Fecha', 'Entrada', 'Salida', 'Pausas', 'Tiempo trabajado', 'Marcas'], ';');
            foreach ($usuarios as $w) {
                for ($d = $mes; $d->month === $mes->month; $d = $d->addDay()) {
                    $m = $this->marcasDia($w->id, $d);
                    if ($m->isEmpty()) {
                        continue;
                    }
                    $c = self::calcular($m);
                    fputcsv($o, [$w->name, $d->format('d/m/Y'), $c['entrada'], $c['salida'], self::hm($c['pausa']), self::hm($c['min']),
                        $m->map(fn ($x) => $x->tipo.' '.CarbonImmutable::parse($x->momento)->timezone(config('app.timezone'))->format('H:i'))->implode(' · ')], ';');
                }
            }
            fclose($o);
        }, 'registro-jornada-'.$mes->format('Y-m').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
