<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\SeccionesAlta;
use Illuminate\Http\Request;

class EscritorioController extends Controller
{
    /** ESCRITORIO · SELECCIÓN («¿Con quién vas a trabajar?») y panel del entorno */
    public function index(Request $request)
    {
        $ctx = $request->attributes->get('ctx');
        $elegir = ! $ctx || $request->boolean('cambiar');

        $tab = $request->query('tab', 'act');
        $q = trim((string) $request->query('q', ''));

        $base = Cliente::query();
        $n = [
            'act' => (clone $base)->clientes()->where('estado', 'Activo')->count(),
            'baja' => (clone $base)->clientes()->where('estado', 'Baja')->count(),
            'desp' => (clone $base)->where('es_despacho', true)->count(),
        ];

        $lista = Cliente::query()->with('asesor')
            ->withCount(['documentos as por_revisar' => fn ($w) => $w->gestor()->vivos()->where('por_revisar', true)])
            ->when($tab === 'act', fn ($w) => $w->clientes()->where('estado', 'Activo'))
            ->when($tab === 'baja', fn ($w) => $w->clientes()->where('estado', 'Baja'))
            ->when($tab === 'desp', fn ($w) => $w->where('es_despacho', true))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', "%$q%")->orWhere('nif', 'like', "%$q%")))
            ->orderBy('nombre')->limit(100)->get();

        $facts = $ctx ? $this->facts($ctx) : [];

        return view('escritorio.index', [
            'titulo' => 'Escritorio', 'activo' => 'escritorio',
            'elegir' => $elegir, 'lista' => $lista, 'tab' => $tab, 'q' => $q, 'n' => $n, 'facts' => $facts,
            'hoy' => now(), 'nRevisar' => $this->nRevisar(),
        ]);
    }

    public function seleccionar(Request $request, Cliente $cliente)
    {
        $request->session()->put('cliente_activo', $cliente->id);
        Auditoria::registrar('entorno.seleccion', 'Trabaja con '.$cliente->nombre, $cliente, $cliente->id);
        $volver = $request->input('volver');
        // Solo rutas internas (evita redirecciones abiertas: //dominio, /\dominio, esquemas…)
        if (is_string($volver) && preg_match('#^/(?![/\\\\])[A-Za-z0-9/_\-?=&.%]*$#', $volver)) {
            return redirect($volver);
        }

        return redirect()->route('escritorio');
    }

    public function cambiar(Request $request)
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => $request->input('volver')]);
    }

    public function preparacion(string $modulo)
    {
        $nombre = collect(config('agcloud.menu'))->flatMap(fn ($g) => $g['items'])->firstWhere(0, $modulo)[1] ?? abort(404);

        return view('preparacion', ['titulo' => $nombre, 'nombre' => $nombre, 'activo' => $modulo, 'portal' => false, 'nRevisar' => $this->nRevisar()]);
    }

    public static function nRevisar(): int
    {
        return Documento::gestor()->vivos()->where('por_revisar', true)->count();
    }

    private function facts(Cliente $c): array
    {
        $blanco = '#F4F4F0';
        if ($c->es_despacho) {
            $u = User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->pluck('name');

            return [
                ['Clientes activos', Formato::entero(Cliente::clientes()->where('estado', 'Activo')->count()), $blanco],
                ['Asesores', $u->implode(' · ') ?: '—', $blanco],
                ['Documentos internos', Formato::entero($c->documentos()->gestor()->vivos()->count()), $blanco],
                ['Certificado del despacho', ...$this->cert($c)],
            ];
        }
        $act = collect($c->seccion('acts'))->first(fn ($a) => ! empty($a['principal']) && empty($a['fFin'])) ?? ($c->seccion('acts')[0] ?? null);
        $per = ['Mensual' => 'al mes', 'Trimestral' => 'al trimestre', 'Semestral' => 'al semestre', 'Anual' => 'al año', 'Por trámite' => 'por trámite'];

        return [
            ['Actividad · CNAE', $act ? (($act['cnae'] ?? '') ?: ($act['desc'] ?? '')) : ($c->actividad ?: 'Sin indicar'), $blanco],
            ['IAE', $act && ! empty($act['iae']) ? $act['iae'] : 'Sin indicar', $blanco],
            ['IVA', $act ? ($act['iva'] ?? '').(! empty($act['recEq']) ? ' + R. equivalencia' : '').(! empty($act['caja']) ? ' + criterio de caja' : '') : 'No aplica', $blanco],
            ['IRPF / IS', $act ? ($act['irpf'] ?? '') : 'No aplica', $blanco],
            ['Cuota', $c->cuota_importe ? Formato::eur($c->cuota_importe).' '.($per[$c->cuota_periodicidad] ?? 'por trámite') : 'Sin definir', $blanco],
            ['Certificado', ...$this->cert($c)],
            ['Asesor', $c->asesor?->name ?? 'Sin asignar', $blanco],
        ];
    }

    /** [texto, color] del certificado que caduca antes */
    private function cert(Cliente $c): array
    {
        $cert = collect($c->seccion('certs'))->filter(fn ($x) => ! empty($x['fCad']))->sortBy('fCad')->first();
        if (! $cert) {
            return ['Sin certificado', '#F4F4F0'];
        }
        $st = SeccionesAlta::estadoCert($cert['fCad']);
        $col = $st[0] === 'Vigente' ? '#C8F031' : ($st[0] === 'Caducado' ? '#FF9B93' : '#F5A524');

        return [$st[0].' · '.Formato::fecha($cert['fCad']), $col];
    }
}
