<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Evento;
use App\Models\Expediente;
use App\Models\Notificacion;
use App\Models\Tarea;
use App\Services\Auditoria;
use App\Support\Parametros;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Calendario del despacho. Se alimenta solo: vencimientos de notificaciones,
 * caducidad de las que siguen sin abrir, plazos de expedientes y tareas con
 * fecha; además, las citas y eventos internos que se añaden a mano.
 */
class CalendarioController extends Controller
{
    public const TIPOS = [
        'notif' => ['Notificaciones', '#0B0B0B', '#C8F031'],
        'exp' => ['Expedientes', '#FCEBCB', '#6E4300'],
        'tarea' => ['Tareas', '#ECECE7', '#3C3C3C'],
        'cita' => ['Citas', '#E6F6A8', '#2E3A00'],
        'interno' => ['Interno', '#3C3C3C', '#FFFFFF'],
    ];

    public function index(Request $request)
    {
        $par = Parametros::get('calendario');
        $hoy = Plazos::hoy();
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('m')) ? CarbonImmutable::parse($request->query('m').'-01') : $hoy->startOfMonth();
        $vista = in_array($request->query('vista'), ['mes', 'agenda'], true) ? $request->query('vista') : $par['vista'];
        $activos = $request->has('t') ? array_values(array_intersect((array) $request->query('t'), array_keys(self::TIPOS))) : array_keys(self::TIPOS);
        $dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('dia')) ? CarbonImmutable::parse($request->query('dia')) : ($mes->isSameMonth($hoy) ? $hoy : $mes);

        // Rejilla: de lunes a domingo, semanas completas
        $ini = $mes->startOfMonth()->startOfWeek(CarbonImmutable::MONDAY);
        $fin = $mes->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $evs = $this->eventos($ini, $fin, $par);
        $todos = collect($evs);
        $filtrados = $todos->filter(fn ($e) => in_array($e['tipo'], $activos, true));
        $porDia = $filtrados->groupBy('fecha');
        $fest = [];
        if ($par['festivos']) {
            foreach (array_unique([$ini->year, $fin->year]) as $y) {
                $fest += Plazos::festivos($y);
            }
        }

        $celdas = [];
        for ($d = $ini; $d->lte($fin); $d = $d->addDay()) {
            $k = $d->toDateString();
            $celdas[] = ['d' => $d, 'k' => $k, 'fuera' => ! $d->isSameMonth($mes), 'hoy' => $d->isSameDay($hoy), 'sel' => $d->isSameDay($dia), 'fest' => $fest[$k] ?? null, 'evs' => $porDia[$k] ?? collect()];
        }
        $agenda = $filtrados->filter(fn ($e) => substr($e['fecha'], 0, 7) === $mes->format('Y-m'))->sortBy(fn ($e) => $e['fecha'].($e['hora'] ?? '99'))->groupBy('fecha');

        return view('calendario.index', [
            'titulo' => 'Calendario', 'activo' => 'calendario', 'mes' => $mes, 'vista' => $vista, 'activos' => $activos, 'dia' => $dia, 'hoy' => $hoy,
            'celdas' => $celdas, 'agenda' => $agenda, 'fest' => $fest,
            'diaEvs' => ($porDia[$dia->toDateString()] ?? collect())->sortBy(fn ($e) => $e['hora'] ?? '99'),
            'cuenta' => collect(self::TIPOS)->map(fn ($x, $k) => $todos->filter(fn ($e) => $e['tipo'] === $k && substr($e['fecha'], 0, 7) === $mes->format('Y-m'))->count()),
            'nuevo' => $request->boolean('nuevo'),
            'clientes' => Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->orderBy('nombre')->get(['id', 'nombre']),
            'url' => fn (array $x = []) => route('calendario', array_filter(array_merge(['m' => $mes->format('Y-m'), 'vista' => $vista, 't' => count($activos) === count(self::TIPOS) ? null : $activos], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    /** Todos los eventos del rango, de todas las fuentes */
    private function eventos(CarbonImmutable $ini, CarbonImmutable $fin, array $par): array
    {
        $a = $ini->toDateString();
        $b = $fin->toDateString();
        $out = [];
        if ($par['notificaciones']) {
            foreach (Notificacion::with('cliente')->where('archivada', false)->whereNotNull('vence')->whereBetween('vence', [$a, $b])->get() as $n) {
                $out[] = ['fecha' => $n->vence->toDateString(), 'hora' => null, 'tipo' => 'notif', 't' => 'Vence: '.$n->tipo, 's' => $n->cliente?->nombre.' · '.$n->organismo, 'url' => route('notificaciones.index', ['sel' => $n->id, 'chip' => 2])];
            }
            foreach (Notificacion::with('cliente')->where('archivada', false)->where('estado', 'Pendiente')->get() as $n) {
                $cad = $n->caducidad()->toDateString();
                if ($cad >= $a && $cad <= $b) {
                    $out[] = ['fecha' => $cad, 'hora' => null, 'tipo' => 'notif', 't' => 'Caduca sin abrir: '.$n->tipo, 's' => $n->cliente?->nombre.' · '.$n->organismo, 'url' => route('notificaciones.index', ['sel' => $n->id])];
                }
            }
        }
        if ($par['expedientes']) {
            foreach (Expediente::with('cliente')->where('estado', '!=', 'Cerrado')->whereNotNull('plazo')->whereBetween('plazo', [$a, $b])->get() as $e) {
                $out[] = ['fecha' => $e->plazo->toDateString(), 'hora' => null, 'tipo' => 'exp', 't' => $e->plazo_lbl ?: $e->tramite, 's' => $e->ref.' · '.$e->cliente?->nombre, 'url' => route('expedientes.show', $e)];
            }
        }
        if ($par['tareas']) {
            foreach (Tarea::with(['cliente', 'responsable'])->where('hecha', false)->whereNotNull('fecha')->whereBetween('fecha', [$a, $b])->get() as $t) {
                $out[] = ['fecha' => $t->fecha->toDateString(), 'hora' => null, 'tipo' => 'tarea', 't' => $t->titulo, 's' => trim(($t->cliente?->nombre ?? '').' · '.($t->responsable?->name ?? ''), ' ·'), 'url' => route('tareas.index')];
            }
        }
        foreach (Evento::with('cliente')->whereBetween('fecha', [$a, $b])->get() as $e) {
            $out[] = ['fecha' => $e->fecha->toDateString(), 'hora' => $e->hora, 'tipo' => $e->tipo, 't' => $e->titulo, 's' => trim(($e->cliente?->nombre ?? '').($e->detalle ? ' · '.$e->detalle : ''), ' ·'), 'url' => null, 'id' => $e->id];
        }

        return $out;
    }

    public function guardar(Request $request)
    {
        $d = $request->validate([
            'fecha' => ['required', 'date'], 'hora' => ['nullable', 'date_format:H:i'], 'tipo' => ['required', Rule::in(['cita', 'interno'])],
            'titulo' => ['required', 'string', 'max:160'], 'detalle' => ['nullable', 'string', 'max:255'], 'cliente_id' => ['nullable', 'integer', 'exists:clientes,id'],
        ], ['titulo.required' => 'Escribe qué es el evento.']);
        $e = Evento::create($d + ['created_by' => $request->user()->id]);
        Auditoria::registrar('calendario.evento', 'Evento: '.$e->titulo.' ('.$e->fecha->format('d/m/Y').')', $e, $e->cliente_id);

        return redirect()->route('calendario', ['m' => $e->fecha->format('Y-m'), 'dia' => $e->fecha->toDateString()])->with('ok', 'Evento añadido al calendario.');
    }

    public function borrar(Evento $evento)
    {
        $f = $evento->fecha;
        $evento->delete();

        return redirect()->route('calendario', ['m' => $f->format('Y-m'), 'dia' => $f->toDateString()])->with('ok', 'Evento eliminado.');
    }
}
