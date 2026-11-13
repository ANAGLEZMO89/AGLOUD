<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Services\Dehu\NotificacionesDehu;
use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Notificaciones administrativas.
 *
 * - DEHú: se consultan con el certificado de la ficha de cada cliente (cada día y al pulsar DEHú, AEAT o Seg. Social)
 *   y solo se abren (comparecencia, art. 43 Ley 39/2015) cuando un usuario del despacho lo pide.
 * - Lo que solo llega a otras sedes se sigue anotando a mano.
 * La aplicación calcula la caducidad (10 días naturales) y el vencimiento del plazo para actuar.
 */
class NotificacionController extends Controller
{
    private const CHIPS = ['Pendientes de abrir', 'Con plazo en curso', 'Todas', 'Archivadas'];

    public function __construct(private AlmacenDocumentos $almacen) {}

    public static function avisos(): array
    {
        $a = Parametros::get('notificaciones');

        return ['email' => (bool) $a['email'], 'wa' => (bool) $a['wa'], 'dias' => (int) $a['dias']];
    }

    /** Número de notificaciones sin abrir (para la insignia del menú) */
    public static function nPendientes(?Cliente $ctx = null): int
    {
        return Notificacion::where('estado', 'Pendiente')->where('archivada', false)
            ->when($ctx && ! $ctx->es_despacho, fn ($q) => $q->where('cliente_id', $ctx->id))->count();
    }

    public function index(Request $request)
    {
        $c = $request->attributes->get('ctx');
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/notificaciones'])->with('aviso', 'Elige primero con quién vas a trabajar.');
        }
        Notificacion::aplicarCaducidad();
        $desp = (bool) $c->es_despacho;
        $chip = max(0, min(3, (int) $request->query('chip', 0)));
        $org = (string) $request->query('org', 'Todos');
        $fuente = in_array($request->query('fuente'), ['dehu', 'aeat', 'ss'], true) ? (string) $request->query('fuente') : '';
        $q = trim((string) $request->query('q', ''));
        $hoy = Plazos::hoy();

        $todas = Notificacion::with(['cliente', 'responsable', 'documento'])
            ->when(! $desp, fn ($w) => $w->where('cliente_id', $c->id))
            ->orderByDesc('puesta')->orderByDesc('id')->limit(3000)->get();

        $filtros = [
            fn (Notificacion $n) => ! $n->archivada && $n->pendiente(),
            fn (Notificacion $n) => ! $n->archivada && $n->plazoEnCurso(),
            fn (Notificacion $n) => ! $n->archivada,
            fn (Notificacion $n) => $n->archivada,
        ];
        $ql = mb_strtolower($q);
        $lista = $todas->filter($filtros[$chip])
            ->filter(fn ($n) => $org === 'Todos' || $n->organismo === $org)
            ->filter(fn ($n) => match ($fuente) {
                'dehu' => $n->canal === 'DEHú', 'aeat' => $n->organismo === 'AEAT', 'ss' => $n->organismo === 'TGSS', default => true
            })
            ->filter(fn ($n) => $ql === '' || str_contains(mb_strtolower($n->tipo.' '.$n->asunto.' '.$n->cliente?->nombre.' '.$n->referencia), $ql))
            ->values();

        $pend = $todas->filter($filtros[0]);
        $enCurso = $todas->filter($filtros[1]);
        $kpis = [
            ['Sin abrir', Formato::entero($pend->count()), 'Caducan a los 10 días naturales'],
            ['Caducan en 3 días o menos', Formato::entero($pend->filter(fn ($n) => $n->diasCaducidad() <= 3)->count()), 'Si no se abren, se dan por notificadas'],
            ['Plazos en curso', Formato::entero($enCurso->count()), 'Desde la apertura'],
            ['Vencen en 7 días', Formato::entero($enCurso->filter(fn ($n) => $n->vence->lte($hoy->addDays(7)))->count()), 'Plazo para responder'],
        ];
        $chips = collect(self::CHIPS)->map(fn ($l, $i) => ['l' => $l, 'n' => $todas->filter($filtros[$i])->count(), 'on' => $i === $chip, 'i' => $i]);

        $sel = $request->query('sel') ? $todas->firstWhere('id', (int) $request->query('sel')) : null;

        return view('notificaciones.index', [
            'titulo' => 'Notificaciones', 'activo' => 'notificaciones', 'c' => $c, 'desp' => $desp,
            'lista' => $lista, 'kpis' => $kpis, 'chips' => $chips, 'chip' => $chip, 'org' => $org, 'q' => $q,
            'fuente' => $fuente, 'dehuEstado' => self::estadoDehu($c),
            'sel' => $sel, 'ask' => (string) $request->query('ask', ''), 'avisos' => self::avisos(), 'avOpen' => $request->boolean('avisos'),
            'nuevo' => $request->boolean('nueva'),
            'clientes' => $desp ? Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->orderBy('nombre')->get(['id', 'nombre', 'nif', 'asesor_id']) : collect([$c]),
            'equipo' => User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get(['id', 'name']),
            'url' => fn (array $x = []) => route('notificaciones.index', array_filter(array_merge(['chip' => $chip ?: null, 'org' => $org === 'Todos' ? null : $org, 'fuente' => $fuente ?: null, 'q' => $q ?: null], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    /** Estado de la conexión con la DEHú: [texto, listo] para un cliente o el resumen del despacho */
    private static function estadoDehu(Cliente $c): array
    {
        $ult = fn (?array $s) => $s ? ' Última consulta: '.Formato::fecha(substr($s['at'], 0, 10)).' a las '.substr($s['at'], 11, 5).' · '.($s['ok'] ? $s['msg'] : 'error: '.$s['msg']).'.' : ' Aún no se ha consultado.';
        if (! $c->es_despacho) {
            [$x, $motivo] = NotificacionesDehu::certificado($c);

            return $motivo
                ? [$motivo.'. Revísalo en la ficha del cliente › Certificados digitales.', false]
                : ['Conectada con el certificado de '.($x['titular'] ?? $c->nombre).'.'.$ult($c->datos['dehuSync'] ?? null), true];
        }
        $todos = Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->get();
        $listos = $todos->filter(fn ($x) => NotificacionesDehu::certificado($x)[1] === null);
        $err = $listos->filter(fn ($x) => isset($x->datos['dehuSync']) && ! $x->datos['dehuSync']['ok'])->count();

        return [$listos->count().' de '.$todos->count().' clientes con el certificado listo para la DEHú.'.($err ? ' '.$err.' con error en la última consulta.' : '').' Se consulta cada día a las 06:40.', $listos->isNotEmpty()];
    }

    public function store(Request $request)
    {
        $ctx = $request->attributes->get('ctx');
        abort_unless($ctx, 403);
        $data = $request->validate([
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('es_despacho', false)],
            'plantilla' => ['required', 'string'],
            'organismo' => ['required', Rule::in(array_keys(config('agcloud.organismos')))],
            'canal' => ['required', Rule::in(config('agcloud.canales'))],
            'tipo' => ['required', 'string', 'max:160'],
            'asunto' => ['nullable', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:80'],
            'puesta' => ['required', 'date', 'before_or_equal:today'],
            'plazo_n' => ['required', 'integer', 'min:0', 'max:365'],
            'plazo_tipo' => ['required', Rule::in(['h', 'n', 'm'])],
            'accion' => ['nullable', 'string', 'max:120'],
            'abierta' => ['nullable', 'boolean'],
            'acceso' => ['nullable', 'required_if:abierta,1', 'date', 'after_or_equal:puesta', 'before_or_equal:today'],
            'responsable_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)],
            'nota' => ['nullable', 'string', 'max:2000'],
            'archivo' => ['nullable', 'file', 'mimes:pdf', 'max:'.config('agcloud.max_upload_kb')],
        ], [
            'puesta.before_or_equal' => 'La fecha de puesta a disposición no puede ser futura.',
            'acceso.required_if' => 'Indica cuándo se abrió en la sede.',
            'acceso.after_or_equal' => 'La apertura no puede ser anterior a la puesta a disposición.',
            'acceso.before_or_equal' => 'La fecha de apertura no puede ser futura.',
            'archivo.mimes' => 'El justificante debe ser un PDF.',
        ]);
        if (! $ctx->es_despacho && (int) $data['cliente_id'] !== $ctx->id) {
            abort(403);
        }
        $cli = Cliente::findOrFail($data['cliente_id']);

        $n = new Notificacion([
            'cliente_id' => $cli->id, 'organismo' => $data['organismo'], 'canal' => $data['canal'], 'tipo' => trim($data['tipo']),
            'asunto' => $data['asunto'] ?? null, 'referencia' => $data['referencia'] ?? null, 'puesta' => $data['puesta'],
            'plazo_n' => (int) $data['plazo_n'], 'plazo_tipo' => $data['plazo_tipo'], 'accion' => $data['accion'] ?? null,
            'responsable_id' => $data['responsable_id'] ?? $cli->asesor_id, 'nota' => $data['nota'] ?? null, 'created_by' => $request->user()->id,
        ]);
        if ($request->boolean('abierta')) {
            $n->estado = 'Aceptada';
            $n->acceso = $data['acceso'];
        } elseif (Plazos::caducidad($data['puesta'])->lt(Plazos::hoy())) {
            // Ya pasaron los 10 días: se registra directamente como caducada
            $n->estado = 'Rechazada';
            $n->caducada = true;
            $n->acceso = Plazos::caducidad($data['puesta']);
        }
        $n->recalcular();
        $n->save();
        if ($request->hasFile('archivo')) {
            $this->adjuntarArchivo($request, $n);
        }
        Auditoria::registrar('notificacion.alta', 'Notificación registrada: '.$n->organismo.' · '.$n->tipo, $n);

        return redirect()->route('notificaciones.index', ['sel' => $n->id, 'chip' => $n->pendiente() ? 0 : 2])
            ->with('ok', 'Notificación registrada para '.$cli->nombre.'.'.($n->caducada ? ' Ya habían pasado 10 días naturales: se da por notificada el '.Formato::fecha($n->acceso).'.' : ''));
    }

    /** Botones DEHú / AEAT / Seg. Social: lista en la DEHú lo pendiente del cliente (o de todos, desde el despacho). No abre nada. */
    public function consultarDehu(Request $request, NotificacionesDehu $dehu)
    {
        $ctx = $request->attributes->get('ctx');
        abort_unless($ctx, 403);
        @set_time_limit(300);
        $fuente = in_array($request->input('fuente'), ['dehu', 'aeat', 'ss'], true) ? (string) $request->input('fuente') : null;
        $volver = redirect()->route('notificaciones.index', array_filter(['fuente' => $fuente]));
        $clientes = $ctx->es_despacho
            ? Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->orderBy('nombre')->get()->filter(fn ($c) => NotificacionesDehu::certificado($c)[1] === null)
            : collect([$ctx]);
        if ($ctx->es_despacho && $clientes->isEmpty()) {
            return $volver->withErrors(['dehu' => 'Ningún cliente tiene listo el certificado para la DEHú (marcado «Uso DEHú», .p12/.pfx con contraseña, vigente y con el alta como Gran Destinatario).']);
        }
        $nuevas = 0;
        $fallos = [];
        foreach ($clientes as $c) {
            try {
                $nuevas += $dehu->sincronizar($c)['nuevas'];
            } catch (\Throwable $e) {
                $fallos[] = ($ctx->es_despacho ? $c->nombre.': ' : '').NotificacionesDehu::mensaje($e);
            }
        }
        $ok = $clientes->count() - count($fallos);
        if ($ok) {
            $volver->with('ok', 'Consultado con el certificado '.($ctx->es_despacho ? 'de '.$ok.' de '.$clientes->count().' clientes' : 'de '.$ctx->nombre).': '.($nuevas ? $nuevas.' '.($nuevas === 1 ? 'notificación nueva' : 'notificaciones nuevas').' sin abrir.' : 'sin novedades.'));
        }

        return $fallos ? $volver->withErrors(['dehu' => ($ok ? '' : 'No se ha podido consultar. ').implode(' · ', array_slice($fallos, 0, 5)).(count($fallos) > 5 ? ' · y '.(count($fallos) - 5).' más' : '')]) : $volver;
    }

    /** Abrir en la DEHú (comparecencia): desde este momento se entiende notificada y empieza el plazo */
    public function comparecer(Request $request, Notificacion $notificacion, NotificacionesDehu $dehu)
    {
        $request->validate(['confirmo' => ['accepted']], ['confirmo.accepted' => 'Marca que entiendes que, al abrirla, empieza el plazo.']);
        try {
            $n = $dehu->comparecer($notificacion, $request->user()->id);
        } catch (\Throwable $e) {
            return back()->withErrors(['dehu' => 'No se pudo abrir en la DEHú: '.NotificacionesDehu::mensaje($e)]);
        }
        $docs = count(json_decode((string) $n->dehu, true)['documentos'] ?? []);

        return redirect()->route('notificaciones.index', ['sel' => $n->id, 'chip' => 2])
            ->with('ok', 'Abierta en la DEHú el '.Formato::fecha($n->acceso).'. '.($docs ? $docs.' '.($docs === 1 ? 'documento guardado' : 'documentos guardados').' en Documentos › Fiscal › Requerimientos y notificaciones.' : 'La DEHú no devolvió documentos: descárgalos en su sede.').' Revisa el plazo que indique la notificación.');
    }

    public function abrir(Request $request, Notificacion $notificacion)
    {
        return $this->resolver($request, $notificacion, 'Aceptada');
    }

    public function rechazar(Request $request, Notificacion $notificacion)
    {
        return $this->resolver($request, $notificacion, 'Rechazada');
    }

    private function resolver(Request $request, Notificacion $n, string $estado)
    {
        abort_unless($n->pendiente(), 422, 'Esta notificación ya no está pendiente.');
        $data = $request->validate([
            'fecha' => ['required', 'date', 'after_or_equal:'.$n->puesta->toDateString(), 'before_or_equal:today', 'before_or_equal:'.$n->caducidad()->toDateString()],
        ], [
            'fecha.after_or_equal' => 'La fecha no puede ser anterior a la puesta a disposición.',
            'fecha.before_or_equal' => 'La fecha no puede ser futura ni posterior a la caducidad ('.Formato::fecha($n->caducidad()).').',
        ]);
        $n->estado = $estado;
        $n->acceso = $data['fecha'];
        $n->recalcular();
        $n->save();
        Auditoria::registrar('notificacion.'.($estado === 'Aceptada' ? 'apertura' : 'rechazo'), ($estado === 'Aceptada' ? 'Abierta' : 'Rechazada').' el '.Formato::fecha($n->acceso).': '.$n->tipo, $n);
        $msg = $estado === 'Aceptada'
            ? 'Notificación marcada como abierta.'.($n->vence ? ' El plazo vence el '.Formato::fecha($n->vence).'.' : '')
            : 'Notificación rechazada. Legalmente se considera notificada y el procedimiento sigue su curso.';

        return redirect()->route('notificaciones.index', ['sel' => $n->id, 'chip' => 2])->with('ok', $msg);
    }

    public function archivar(Notificacion $notificacion)
    {
        $notificacion->archivada = ! $notificacion->archivada;
        $notificacion->save();
        Auditoria::registrar('notificacion.archivo', ($notificacion->archivada ? 'Archivada: ' : 'Recuperada: ').$notificacion->tipo, $notificacion);

        return redirect()->route('notificaciones.index', ['chip' => $notificacion->archivada ? 0 : 3])->with('ok', $notificacion->archivada ? 'Notificación archivada.' : 'Notificación recuperada.');
    }

    public function actualizar(Request $request, Notificacion $notificacion)
    {
        $data = $request->validate([
            'responsable_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)],
            'nota' => ['nullable', 'string', 'max:2000'],
        ]);
        $notificacion->responsable_id = $data['responsable_id'] ?? null;
        $notificacion->nota = $data['nota'] ?? null;
        // Plazo y actuación (en las de la DEHú se completan al leer el documento)
        if ($request->has('plazo_n')) {
            $p = $request->validate(['plazo_n' => ['required', 'integer', 'min:0', 'max:365'], 'plazo_tipo' => ['required', Rule::in(['h', 'n', 'm'])], 'accion' => ['nullable', 'string', 'max:120']]);
            $notificacion->plazo_n = (int) $p['plazo_n'];
            $notificacion->plazo_tipo = $p['plazo_tipo'];
            $notificacion->accion = $p['accion'] ?? null;
            $notificacion->recalcular();
        }
        $notificacion->save();

        return back()->with('ok', 'Cambios guardados.');
    }

    public function adjuntar(Request $request, Notificacion $notificacion)
    {
        $request->validate(['archivo' => ['required', 'file', 'mimes:pdf', 'max:'.config('agcloud.max_upload_kb')]], ['archivo.mimes' => 'El justificante debe ser un PDF.']);
        $this->adjuntarArchivo($request, $notificacion);

        return back()->with('ok', 'Justificante guardado en Documentos › Fiscal › Requerimientos y notificaciones.');
    }

    private function adjuntarArchivo(Request $request, Notificacion $n): void
    {
        $f = $request->file('archivo');
        $g = $this->almacen->guardar($f, $n->cliente_id);
        $nombre = ClasificadorDocumentos::nombreSeguro(($n->acceso ?? $n->puesta)->format('Y-m-d').' '.$n->organismo.' '.$n->tipo.'.pdf');
        $d = Documento::create([
            'cliente_id' => $n->cliente_id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => 'fiscal/requerimientos', 'tipo' => 'docu',
            'nombre' => $nombre, 'nombre_original' => ClasificadorDocumentos::nombreSeguro($f->getClientOriginalName()), 'fecha' => ($n->acceso ?? $n->puesta)->toDateString(),
            'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'],
            'origen' => 'Despacho', 'subido_por' => $request->user()->id,
        ]);
        $n->documento_id = $d->id;
        $n->save();
        Auditoria::registrar('documento.subida', 'Justificante de notificación: '.$d->nombre, $d);
    }

    /** Vencimiento en formato .ics para añadirlo a cualquier calendario */
    public function ics(Notificacion $notificacion)
    {
        abort_unless($notificacion->vence, 404);
        $n = $notificacion;
        $esc = fn (string $s) => str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $s);
        $d = $n->vence->format('Ymd');
        $fin = $n->vence->addDay()->format('Ymd');
        $txt = implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//GA AGOPYME//AG Cloud//ES', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:notif-'.$n->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.gmdate('Ymd\THis\Z'),
            'DTSTART;VALUE=DATE:'.$d, 'DTEND;VALUE=DATE:'.$fin,
            'SUMMARY:'.$esc('Vence: '.$n->tipo.' · '.$n->cliente?->nombre),
            'DESCRIPTION:'.$esc($n->organismoTexto().' · '.($n->asunto ?? '').' · '.$n->plazoTexto().($n->accion ? ' · '.$n->accion : '')),
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.$esc('Vence: '.$n->tipo), 'TRIGGER:-P3D', 'END:VALARM',
            'END:VEVENT', 'END:VCALENDAR',
        ])."\r\n";

        return response($txt, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="vencimiento-'.$n->vence->format('Y-m-d').'.ics"',
        ]);
    }

    public function destroy(Request $request, Notificacion $notificacion)
    {
        abort_unless($request->user()->esAdmin(), 403);
        Auditoria::registrar('notificacion.baja', 'Registro eliminado: '.$notificacion->organismo.' · '.$notificacion->tipo.' · '.$notificacion->cliente?->nombre, $notificacion);
        $notificacion->delete();

        return redirect()->route('notificaciones.index')->with('ok', 'Registro eliminado.');
    }

    public function guardarAvisos(Request $request)
    {
        $d = $request->validate(['dias' => ['required', Rule::in(['1', '3', '5'])]]);
        Ajuste::put('avisos_notificaciones', json_encode(['email' => $request->boolean('email'), 'wa' => $request->boolean('wa'), 'dias' => (int) $d['dias']]));

        return redirect()->route('notificaciones.index', ['avisos' => 1])->with('ok', 'Avisos guardados.');
    }
}
