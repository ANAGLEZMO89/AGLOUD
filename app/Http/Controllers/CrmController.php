<?php

namespace App\Http\Controllers;

use App\Models\Actividad;
use App\Models\Cliente;
use App\Models\FacturacionConfig;
use App\Models\Oportunidad;
use App\Models\Presupuesto;
use App\Services\Auditoria;
use App\Services\Facturacion;
use App\Support\Formato;
use App\Support\Plazos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** CRM de la empresa elegida: clientes potenciales, presupuestos y seguimiento. */
class CrmController extends Controller
{
    private function emisor(Request $request): ?Cliente
    {
        return $request->attributes->get('ctx');
    }

    private function de(Request $request, $m): Cliente
    {
        $c = $this->emisor($request);
        abort_unless($c && $m->cliente_id === $c->id, 404);

        return $c;
    }

    public function index(Request $request)
    {
        $c = $this->emisor($request);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/crm'])->with('aviso', 'Elige primero con quién vas a trabajar.');
        }
        $tab = in_array($request->query('tab'), ['pres', 'agenda'], true) ? $request->query('tab') : 'embudo';
        $hoy = Plazos::hoy();
        $ops = Oportunidad::where('cliente_id', $c->id)->orderBy('fecha')->get();
        $abiertas = $ops->reject->cerrada();
        $won = $ops->where('etapa', 'Ganado')->filter(fn ($o) => $o->fecha->year === $hoy->year);
        $ganadas = $ops->where('etapa', 'Ganado');
        $perdidas = $ops->where('etapa', 'Perdido');
        $acts = Actividad::where('cliente_id', $c->id)->with('oportunidad')->orderBy('hecha')->orderBy('fecha')->orderBy('hora')->limit(500)->get();
        $pendientes = $acts->where('hecha', false)->count();

        if ($request->query('exportar') === 'csv') {
            return response()->streamDownload(function () use ($ops) {
                $o = fopen('php://output', 'w');
                fwrite($o, "\xEF\xBB\xBF");
                fputcsv($o, ['Cliente', 'Oportunidad', 'Etapa', 'Importe', 'Probabilidad', 'Cierre', 'Origen'], ';');
                foreach ($ops as $x) {
                    fputcsv($o, [$x->nombre, $x->titulo, $x->etapa, Formato::num($x->importe), Oportunidad::probabilidad($x->etapa).' %', Formato::fecha($x->fecha), $x->origen], ';');
                }
                fclose($o);
            }, 'crm-oportunidades.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return view('crm.index', [
            'titulo' => 'CRM', 'activo' => 'crm', 'c' => $c, 'tab' => $tab, 'hoy' => $hoy,
            'kpis' => [
                ['Embudo abierto', Formato::eur($abiertas->sum('importe'), 0), Formato::entero($abiertas->count()).' oportunidades'],
                ['Previsión ponderada', Formato::eur($abiertas->sum(fn ($o) => $o->importe * Oportunidad::probabilidad($o->etapa) / 100), 0), 'Según la probabilidad de cada etapa'],
                ['Ganado en '.$hoy->year, Formato::eur($won->sum('importe'), 0), Formato::entero($won->count()).' cerradas'],
                ['Conversión', ($ganadas->count() + $perdidas->count() ? round($ganadas->count() / ($ganadas->count() + $perdidas->count()) * 100) : 0).' %', 'Ganadas frente a cerradas'],
            ],
            'cols' => collect(array_keys(Oportunidad::ETAPAS))->map(fn ($et) => ['et' => $et, 'items' => $ops->where('etapa', $et)->values()]),
            'pres' => Presupuesto::where('cliente_id', $c->id)->orderByDesc('fecha')->orderByDesc('id')->get(),
            'acts' => $acts, 'pendientes' => $pendientes, 'abiertas' => $abiertas,
            'nuevaOp' => $request->boolean('nueva'),
            'presNuevo' => $request->boolean('presupuesto') ? ($request->query('op') ? Oportunidad::where('cliente_id', $c->id)->find((int) $request->query('op')) : new Oportunidad) : null,
            'catalogo' => FacturacionConfig::de($c)->catalogo ?? [],
        ]);
    }

    public function guardarOportunidad(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:255'], 'titulo' => ['required', 'string', 'max:255'], 'importe' => ['nullable', 'string', 'max:20'],
            'etapa' => ['required', Rule::in(array_keys(Oportunidad::ETAPAS))], 'fecha' => ['required', 'date'], 'origen' => ['required', Rule::in(Oportunidad::origenes())],
        ], ['nombre.required' => 'Indica el cliente y qué le ofreces.', 'titulo.required' => 'Indica el cliente y qué le ofreces.']);
        $o = Oportunidad::create(['cliente_id' => $c->id, 'nombre' => trim($d['nombre']), 'titulo' => trim($d['titulo']), 'importe' => max(0, Formato::parseNum($d['importe'] ?? '0')),
            'etapa' => $d['etapa'], 'fecha' => $d['fecha'], 'origen' => $d['origen'], 'responsable_id' => $request->user()->id]);
        Auditoria::registrar('crm.oportunidad', 'Oportunidad: '.$o->nombre.' · '.$o->titulo, $o);

        return redirect()->route('crm.index')->with('ok', 'Oportunidad creada.');
    }

    public function etapa(Request $request, Oportunidad $oportunidad)
    {
        $this->de($request, $oportunidad);
        $d = $request->validate(['etapa' => ['required', Rule::in(array_keys(Oportunidad::ETAPAS))]]);
        $oportunidad->etapa = $d['etapa'];
        if (in_array($d['etapa'], ['Ganado', 'Perdido'], true)) {
            $oportunidad->fecha = now()->toDateString();
        }
        $oportunidad->save();

        return back();
    }

    public function borrarOportunidad(Request $request, Oportunidad $oportunidad)
    {
        $this->de($request, $oportunidad);
        Auditoria::registrar('crm.oportunidad.baja', 'Oportunidad eliminada: '.$oportunidad->nombre.' · '.$oportunidad->titulo, $oportunidad);
        $oportunidad->delete();

        return back()->with('ok', 'Oportunidad eliminada.');
    }

    public function guardarPresupuesto(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $request->validate([
            'cli' => ['required', 'string', 'max:255'], 'nif' => ['nullable', 'string', 'max:20'], 'validez' => ['required', Rule::in(['15', '30', '60', '90'])],
            'oportunidad_id' => ['nullable', 'integer'], 'lineas' => ['required', 'array', 'max:100'],
            'lineas.*.d' => ['nullable', 'string', 'max:255'], 'lineas.*.q' => ['nullable', 'string', 'max:20'], 'lineas.*.p' => ['nullable', 'string', 'max:20'], 'lineas.*.iva' => ['nullable', Rule::in(FacturaController::IVAS)],
        ], ['cli.required' => 'Indica el cliente y al menos una línea con precio.']);
        $L = collect($request->input('lineas'))->map(fn ($l) => ['d' => trim((string) ($l['d'] ?? '')), 'q' => Formato::parseNum($l['q'] ?? '1') ?: 1, 'p' => Formato::parseNum($l['p'] ?? ''), 'iva' => (float) ($l['iva'] ?? 21)])
            ->filter(fn ($l) => $l['d'] !== '' && $l['p'] > 0)->values()->all();
        if (! $L) {
            throw ValidationException::withMessages(['form' => 'Indica el cliente y al menos una línea con precio.']);
        }
        $t = Facturacion::totales($L);
        $op = $request->filled('oportunidad_id') ? Oportunidad::where('cliente_id', $c->id)->find((int) $request->input('oportunidad_id')) : null;
        $p = DB::transaction(function () use ($c, $request, $L, $t, $op) {
            $y = now()->format('Y');
            $ult = Presupuesto::where('cliente_id', $c->id)->where('num', 'like', "P-$y-%")->lockForUpdate()->orderByDesc('num')->value('num');

            return Presupuesto::create(['cliente_id' => $c->id, 'oportunidad_id' => $op?->id, 'num' => sprintf('P-%s-%04d', $y, $ult ? (int) substr($ult, -4) + 1 : 1),
                'receptor_nombre' => trim($request->input('cli')), 'receptor_nif' => strtoupper(trim((string) $request->input('nif'))) ?: null, 'fecha' => now()->toDateString(),
                'validez' => (int) $request->input('validez'), 'estado' => 'Borrador', 'lineas' => $L, 'base' => $t['base'], 'cuota' => $t['cuota'], 'total' => $t['base'] + $t['cuota'],
                'created_by' => $request->user()->id]);
        });
        Auditoria::registrar('crm.presupuesto', 'Presupuesto '.$p->num.' · '.$p->receptor_nombre, $p);

        return redirect()->route('crm.index', ['tab' => 'pres'])->with('ok', 'Presupuesto '.$p->num.' creado como borrador.');
    }

    public function estadoPresupuesto(Request $request, Presupuesto $presupuesto)
    {
        $c = $this->de($request, $presupuesto);
        $d = $request->validate(['estado' => ['required', Rule::in(['Enviado', 'Aceptado', 'Rechazado'])]]);
        $permitido = ['Enviado' => ['Borrador'], 'Aceptado' => ['Enviado'], 'Rechazado' => ['Enviado']][$d['estado']];
        abort_unless(in_array($presupuesto->estado, $permitido, true), 422);
        $presupuesto->estado = $d['estado'];
        $presupuesto->save();
        if ($op = $presupuesto->oportunidad) {
            $op->etapa = ['Enviado' => 'Presupuesto enviado', 'Aceptado' => 'Ganado', 'Rechazado' => 'Perdido'][$d['estado']];
            if ($d['estado'] !== 'Enviado') {
                $op->fecha = now()->toDateString();
            }
            $op->save();
        }
        $msg = ['Enviado' => 'Presupuesto '.$presupuesto->num.' marcado como enviado.', 'Aceptado' => 'Presupuesto aceptado. La oportunidad pasa a ganada.', 'Rechazado' => 'Presupuesto rechazado.'][$d['estado']];
        if ($d['estado'] === 'Enviado') {
            $asunto = 'Presupuesto '.$presupuesto->num.' · '.$c->nombre;
            $cuerpo = 'Le adjuntamos el presupuesto '.$presupuesto->num.' por importe de '.Formato::eur($presupuesto->total).' (IVA incluido), válido hasta el '.Formato::fecha($presupuesto->vence()).'.';

            return redirect()->route('crm.index', ['tab' => 'pres'])->with('ok', $msg)->with('mailto', 'mailto:?subject='.rawurlencode($asunto).'&body='.rawurlencode($cuerpo));
        }

        return redirect()->route('crm.index', ['tab' => 'pres'])->with('ok', $msg);
    }

    public function documentoPresupuesto(Request $request, Presupuesto $presupuesto)
    {
        $c = $this->de($request, $presupuesto);

        return view('crm.presupuesto', ['c' => $c, 'p' => $presupuesto, 'cfg' => FacturacionConfig::de($c), 'imprimir' => $request->boolean('imprimir')]);
    }

    public function guardarActividad(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $d = $request->validate([
            'tipo' => ['required', Rule::in(Actividad::TIPOS)], 'texto' => ['required', 'string', 'max:255'], 'fecha' => ['required', 'date'],
            'hora' => ['nullable', 'date_format:H:i'], 'oportunidad_id' => ['nullable', 'integer'],
        ], ['texto.required' => 'Describe la actividad.']);
        $op = ! empty($d['oportunidad_id']) ? Oportunidad::where('cliente_id', $c->id)->find((int) $d['oportunidad_id']) : null;
        Actividad::create(['cliente_id' => $c->id, 'oportunidad_id' => $op?->id, 'tipo' => $d['tipo'], 'texto' => trim($d['texto']), 'fecha' => $d['fecha'], 'hora' => $d['hora'] ?? null, 'user_id' => $request->user()->id]);

        return redirect()->route('crm.index', ['tab' => 'agenda'])->with('ok', 'Actividad añadida a la agenda.');
    }

    public function hecha(Request $request, Actividad $actividad)
    {
        $this->de($request, $actividad);
        $actividad->hecha = ! $actividad->hecha;
        $actividad->save();

        return back();
    }
}
