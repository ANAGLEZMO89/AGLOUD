<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Expediente;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\Parametros;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpedienteController extends Controller
{
    public function index(Request $request)
    {
        $vista = $request->query('vista') === 'lista' ? 'lista' : 'tablero';
        $asesor = (string) $request->query('asesor', 'Todos');
        $cat = (string) $request->query('cat', 'Todas');
        $q = trim((string) $request->query('q', ''));
        $ctx = $request->attributes->get('ctx');
        $soloCli = $ctx && ! $ctx->es_despacho ? $ctx : null;

        $todos = Expediente::with(['cliente', 'responsable'])
            ->when($soloCli, fn ($w) => $w->where('cliente_id', $soloCli->id))
            ->orderByRaw('plazo is null')->orderBy('plazo')->orderByDesc('id')->limit(3000)->get();
        $ql = mb_strtolower($q);
        $fl = $todos->filter(fn ($e) => ($asesor === 'Todos' || (string) $e->responsable_id === $asesor)
            && ($cat === 'Todas' || $e->categoria === $cat)
            && ($ql === '' || str_contains(mb_strtolower($e->ref.' '.$e->tramite.' '.$e->cliente?->nombre), $ql)))->values();

        $abiertos = $todos->reject->cerrado();
        $resumen = [
            ['Abiertos', Formato::entero($abiertos->count())],
            ['Esperando al cliente', Formato::entero($todos->where('estado', 'Esperando cliente')->count())],
            ['Vencen en 7 días', Formato::entero($abiertos->filter(fn ($e) => $e->dias() !== null && $e->dias() <= 7)->count())],
            ['Pendiente de facturar', Formato::eur($todos->where('estado', 'Presentado')->sum('honorarios'), 0)],
        ];
        $cols = collect(array_slice(Expediente::ESTADOS, 0, 4))->map(fn ($est) => ['estado' => $est, 'items' => $fl->where('estado', $est)->values()]);

        // Nuevo expediente (cajón)
        $nuevo = $request->boolean('nuevo');
        $notif = $nuevo && $request->query('notif') ? Notificacion::find((int) $request->query('notif')) : null;
        $tplDef = 'Constitución de SL';
        if ($notif) {
            $tplDef = match ($notif->organismo) {
                'AEAT' => 'Requerimiento AEAT',
                'TGSS' => 'Reclamación TGSS',
                default => 'Otro trámite',
            };
        }
        $tplsDisp = Parametros::get('expedientes')['plantillas'];
        if (! isset($tplsDisp[$tplDef])) {
            $tplDef = array_key_first($tplsDisp) ?? '';
        }

        return view('expedientes.index', [
            'titulo' => 'Expedientes', 'activo' => 'expedientes', 'vista' => $vista, 'asesor' => $asesor, 'cat' => $cat, 'q' => $q,
            'resumen' => $resumen, 'cols' => $cols, 'lista' => $fl, 'soloCli' => $soloCli,
            'equipo' => User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get(['id', 'name']),
            'cats' => collect(Parametros::get('expedientes')['plantillas'])->pluck('cat')->unique()->values(),
            'nuevo' => $nuevo, 'notif' => $notif, 'tplDef' => old('tramite', $tplDef),
            'cliDef' => old('cliente_id', $request->query('cli', $soloCli?->id)),
            'clientes' => $nuevo ? Cliente::where('es_despacho', false)->where('estado', '!=', 'Baja')->orderBy('nombre')->get(['id', 'nombre', 'asesor_id']) : collect(),
            'url' => fn (array $x = []) => route('expedientes.index', array_filter(array_merge(['vista' => $vista === 'lista' ? 'lista' : null, 'asesor' => $asesor === 'Todos' ? null : $asesor, 'cat' => $cat === 'Todas' ? null : $cat, 'q' => $q ?: null], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function store(Request $request)
    {
        $tpls = Parametros::get('expedientes')['plantillas'];
        $data = $request->validate([
            'cliente_id' => ['required', 'integer', Rule::exists('clientes', 'id')->where('es_despacho', false)],
            'tramite' => ['required', Rule::in(array_keys($tpls))],
            'responsable_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)],
            'plazo' => ['nullable', 'date'],
            'plazo_lbl' => ['nullable', 'string', 'max:160'],
            'honorarios' => ['nullable', 'string', 'max:20'],
            'notificacion_id' => ['nullable', 'integer', 'exists:notificaciones,id'],
        ], ['cliente_id.required' => 'Selecciona el cliente.']);
        $t = $tpls[$data['tramite']];
        $cli = Cliente::findOrFail($data['cliente_id']);
        $hon = isset($data['honorarios']) && $data['honorarios'] !== '' ? Formato::parseNum($data['honorarios']) : (float) $t['hon'];

        $e = null;
        for ($i = 0; $i < 5 && ! $e; $i++) {
            try {
                $e = DB::transaction(fn () => Expediente::create([
                    'cliente_id' => $cli->id, 'ref' => Expediente::siguienteRef(), 'tramite' => $data['tramite'], 'categoria' => $t['cat'],
                    'responsable_id' => $data['responsable_id'] ?? $cli->asesor_id ?? $request->user()->id, 'estado' => 'Abierto',
                    'apertura' => now()->toDateString(), 'plazo' => $data['plazo'] ?? null, 'plazo_lbl' => $data['plazo_lbl'] ?? null,
                    'pasos' => array_map(fn ($l) => ['l' => $l, 'ok' => false], $t['pasos']),
                    'docs' => array_map(fn ($l) => ['l' => $l, 'ok' => false], $t['docs']),
                    'honorarios' => max(0, $hon), 'portal' => (bool) Parametros::get('expedientes')['portal_defecto'], 'created_by' => $request->user()->id,
                ]));
            } catch (QueryException $ex) {
                if ($i === 4) {
                    throw $ex;
                }
            }
        }
        $e->evento('Expediente abierto por '.$request->user()->name);
        if (! empty($data['notificacion_id'])) {
            $n = Notificacion::where('cliente_id', $cli->id)->find($data['notificacion_id']);
            if ($n) {
                $n->expediente_id = $e->id;
                $n->save();
                $e->evento('Vinculado a la notificación: '.$n->organismo.' · '.$n->tipo);
            }
        }
        Auditoria::registrar('expediente.alta', 'Expediente '.$e->ref.' · '.$e->tramite, $e);

        return redirect()->route('expedientes.show', $e)->with('ok', 'Expediente '.$e->ref.' abierto.');
    }

    public function show(Request $request, Expediente $expediente)
    {
        $e = $expediente->load(['cliente', 'responsable', 'eventos.autor']);
        $notif = Notificacion::where('expediente_id', $e->id)->first();

        return view('expedientes.show', ['titulo' => $e->ref, 'activo' => 'expedientes', 'e' => $e, 'notif' => $notif]);
    }

    public function estado(Request $request, Expediente $expediente)
    {
        $d = $request->validate(['estado' => ['required', Rule::in(Expediente::ESTADOS)]]);
        if ($expediente->estado !== $d['estado']) {
            $expediente->estado = $d['estado'];
            $expediente->save();
            $expediente->evento('Estado cambiado a «'.$d['estado'].'»');
            Auditoria::registrar('expediente.estado', $expediente->ref.' → '.$d['estado'], $expediente);
        }

        return back();
    }

    public function paso(Request $request, Expediente $expediente, int $i)
    {
        return $this->alternar($expediente, 'pasos', $i, 'Paso completado: ');
    }

    public function doc(Request $request, Expediente $expediente, int $i)
    {
        return $this->alternar($expediente, 'docs', $i, 'Documento recibido: ');
    }

    private function alternar(Expediente $e, string $campo, int $i, string $txt)
    {
        $l = $e->{$campo};
        abort_unless(isset($l[$i]), 404);
        $l[$i]['ok'] = ! $l[$i]['ok'];
        $e->{$campo} = $l;
        $e->save();
        if ($l[$i]['ok']) {
            $e->evento($txt.$l[$i]['l']);
        }

        return back();
    }

    public function nota(Request $request, Expediente $expediente)
    {
        $d = $request->validate(['texto' => ['required', 'string', 'max:2000']]);
        $expediente->evento(trim($d['texto']), true);

        return back();
    }

    public function portal(Expediente $expediente)
    {
        $expediente->portal = ! $expediente->portal;
        $expediente->save();
        $expediente->evento($expediente->portal ? 'Visible en el portal del cliente' : 'Oculto en el portal del cliente');

        return back();
    }

    public function actualizar(Request $request, Expediente $expediente)
    {
        $d = $request->validate([
            'responsable_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)],
            'plazo' => ['nullable', 'date'],
            'plazo_lbl' => ['nullable', 'string', 'max:160'],
            'honorarios' => ['nullable', 'string', 'max:20'],
        ]);
        $antes = $expediente->plazo?->toDateString();
        $expediente->fill([
            'responsable_id' => $d['responsable_id'] ?? null, 'plazo' => $d['plazo'] ?? null, 'plazo_lbl' => $d['plazo_lbl'] ?? null,
            'honorarios' => max(0, Formato::parseNum($d['honorarios'] ?? '0')),
        ])->save();
        if ($antes !== $expediente->plazo?->toDateString()) {
            $expediente->evento('Fecha límite: '.($expediente->plazo ? Formato::fecha($expediente->plazo) : 'sin fecha'));
        }

        return back()->with('ok', 'Datos del expediente guardados.');
    }

    public function destroy(Request $request, Expediente $expediente)
    {
        abort_unless($request->user()->esAdmin(), 403);
        Auditoria::registrar('expediente.baja', 'Expediente eliminado: '.$expediente->ref.' · '.$expediente->tramite.' · '.$expediente->cliente?->nombre, $expediente);
        $expediente->delete();

        return redirect()->route('expedientes.index')->with('ok', 'Expediente eliminado.');
    }
}
