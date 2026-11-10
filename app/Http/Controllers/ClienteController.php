<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\User;
use App\Services\AccesosPortal;
use App\Services\Auditoria;
use App\Support\Formato;
use App\Support\SeccionesAlta;
use App\Support\Validacion;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClienteController extends Controller
{
    // ---------------------------------------------------------------- listado
    public function index(Request $request)
    {
        return view('clientes.index', $this->datosListado($request) + ['titulo' => 'Clientes', 'activo' => 'clientes', 'nRevisar' => EscritorioController::nRevisar()]);
    }

    private function datosListado(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));
        $tipo = (string) $request->query('tipo', 'Todos');
        $asesor = (string) $request->query('asesor', 'Todos');
        $estado = (string) $request->query('estado', 'Todos');
        $tipoVal = config('agcloud.filtros_tipo')[$tipo] ?? null;

        $lista = $this->consulta($q, $tipoVal, $asesor, $estado)
            ->with('asesor')
            ->withCount(['documentos as por_revisar' => fn ($w) => $w->gestor()->vivos()->where('por_revisar', true)])
            ->orderBy('nombre')->paginate(25)->withQueryString();

        $cli = Cliente::clientes();
        $resumen = [
            ['Clientes activos', (clone $cli)->where('estado', 'Activo')->count()],
            ['Altas en curso', (clone $cli)->where('estado', 'Alta en curso')->count()],
            ['Con documentación pendiente', (clone $cli)->whereHas('documentos', fn ($w) => $w->gestor()->vivos()->where('por_revisar', true))->count()],
            ['Sin acceso al portal', (clone $cli)->where('estado', '!=', 'Baja')->whereDoesntHave('usuarios', fn ($w) => $w->where('activo', true))->count()],
        ];

        return compact('lista', 'resumen', 'q', 'tipo', 'asesor', 'estado') + ['asesores' => $this->asesores()];
    }

    private function consulta(string $q, ?string $tipo, string $asesor, string $estado)
    {
        return Cliente::clientes()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', "%$q%")->orWhere('nif', 'like', "%$q%")->orWhere('actividad', 'like', "%$q%")->orWhere('comercial', 'like', "%$q%")))
            ->when($tipo, fn ($w) => $w->where('tipo', $tipo))
            ->when($asesor !== 'Todos', fn ($w) => $w->where('asesor_id', (int) $asesor))
            ->when($estado !== 'Todos', fn ($w) => $w->where('estado', $estado));
    }

    private function asesores()
    {
        return User::whereIn('role', User::ROLES_DESPACHO)->where('activo', true)->orderBy('name')->get(['id', 'name']);
    }

    /** Exportar (CSV compatible con Excel: UTF-8 con BOM y punto y coma) */
    public function exportar(Request $request): StreamedResponse
    {
        $tipoVal = config('agcloud.filtros_tipo')[$request->query('tipo', 'Todos')] ?? null;
        $rows = $this->consulta(trim((string) $request->query('q', '')), $tipoVal, (string) $request->query('asesor', 'Todos'), (string) $request->query('estado', 'Todos'))
            ->with('asesor')->orderBy('nombre')->get();
        Auditoria::registrar('clientes.exportar', 'Exporta el listado de clientes ('.$rows->count().' filas)');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Nombre o razón social', 'NIF', 'Tipo', 'Actividad', 'Asesor', 'Estado', 'Email', 'Teléfono', 'Domicilio', 'CP', 'Municipio', 'Provincia', 'Fecha de alta', 'Cuota (€)', 'Periodicidad'], ';');
            foreach ($rows as $c) {
                fputcsv($out, [$c->nombre, $c->nif, $c->tipo, $c->actividadPrincipal(), $c->asesor?->name, $c->estado, $c->email, $c->telefono, $c->domicilio, $c->cp, $c->municipio, $c->provincia, Formato::fecha($c->fecha_alta), $c->cuota_importe ? Formato::num($c->cuota_importe) : '', $c->cuota_periodicidad], ';');
            }
            fclose($out);
        }, 'clientes-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------------------------------------------------------------- ficha
    public function show(Request $request, Cliente $cliente)
    {
        return view('clientes.show', $this->datosFicha($request, $cliente));
    }

    private function datosFicha(Request $request, Cliente $c): array
    {
        $tab = (string) $request->query('tab', 'resumen');
        $docs = $c->documentos()->gestor()->vivos()->orderByDesc('fecha')->orderBy('nombre');
        $nDocs = (clone $docs)->count();
        $tabs = [['resumen', 'Resumen', null], ['datos', 'Datos fiscales', null], ['obl', 'Obligaciones', null], ['trab', 'Trabajadores', null], ['docs', 'Documentos', $nDocs ?: null], ['exp', 'Expedientes', $c->expedientes()->where('estado', '!=', 'Cerrado')->count() ?: null], ['alta', 'Datos de alta', null], ['accesos', 'Accesos al portal', $c->usuarios()->count() ?: null]];

        $actividad = AuditLog::where('cliente_id', $c->id)->with('user')->latest('created_at')->limit(6)->get();

        return [
            'titulo' => $c->nombre, 'activo' => 'clientes', 'c' => $c, 'tab' => $tab, 'tabs' => $tabs,
            'docs' => $tab === 'docs' ? $docs->limit(200)->get() : collect(),
            'porRevisar' => $c->documentos()->gestor()->vivos()->where('por_revisar', true)->orderByDesc('created_at')->limit(5)->get(),
            'actividad' => $actividad,
            'expedientes' => $tab === 'exp' ? $c->expedientes()->with('responsable')->orderByRaw("estado = 'Cerrado'")->orderByDesc('id')->get() : collect(),
            'usuarios' => $tab === 'accesos' ? $c->usuarios()->orderBy('name')->get() : collect(),
            'nRevisar' => EscritorioController::nRevisar(),
        ];
    }

    public static function estadoCert(?string $fCad): array
    {
        return SeccionesAlta::estadoCert($fCad);
    }

    // ---------------------------------------------------------------- alta / edición
    public function create(Request $request)
    {
        $nuevo = new Cliente([
            'tipo' => in_array($request->query('tipo'), config('agcloud.tipos_cliente'), true) ? $request->query('tipo') : 'Autónomo',
            'provincia' => config('agcloud.despacho.provincia'), 'fecha_alta' => now(), 'asesor_id' => $request->user()->id,
            'cuota_periodicidad' => 'Mensual', 'cuota_iva' => 21, 'cuota_forma_cobro' => 'Domiciliación SEPA', 'cuota_concepto' => 'Honorarios de asesoría',
        ]);

        return view('clientes.index', $this->datosListado($request) + [
            'titulo' => 'Alta de cliente', 'activo' => 'clientes', 'nRevisar' => EscritorioController::nRevisar(),
            'drawer' => $this->datosDrawer($nuevo, 'general', null),
        ]);
    }

    public function edit(Request $request, Cliente $cliente)
    {
        $secs = SeccionesAlta::secciones($cliente->tipo);
        $sec = array_key_exists($request->query('sec'), $secs) ? $request->query('sec') : 'general';
        $item = $request->query('item');

        return view('clientes.show', $this->datosFicha($request->merge(['tab' => $request->query('tab', 'alta')]), $cliente) + [
            'drawer' => $this->datosDrawer($cliente, $sec, $item),
        ]);
    }

    private function datosDrawer(Cliente $c, string $sec, $item): array
    {
        $secs = SeccionesAlta::secciones($c->tipo);
        $keys = array_keys($secs);
        $spec = SeccionesAlta::spec($sec, $c->tipo);
        $lista = $spec ? $c->seccion($sec) : [];
        $sub = null;
        if ($spec && $item !== null) {
            if ($item === 'nuevo') {
                $sub = ['idx' => null, 'd' => SeccionesAlta::blank($sec, $c, $lista)];
            } elseif (isset($lista[(int) $item])) {
                $sub = ['idx' => (int) $item, 'd' => array_merge(SeccionesAlta::blank($sec, $c, $lista), $lista[(int) $item])];
            }
        }
        // si viene de un error de validación, recuperar lo escrito
        if ($sub && session()->hasOldInput('d')) {
            $sub['d'] = array_merge($sub['d'], (array) old('d'));
        }

        return [
            'c' => $c, 'nuevo' => ! $c->exists, 'sec' => $sec, 'secs' => $secs, 'pos' => array_search($sec, $keys, true),
            'prev' => $keys[array_search($sec, $keys, true) - 1] ?? null, 'next' => $keys[array_search($sec, $keys, true) + 1] ?? null,
            'spec' => $spec, 'lista' => $lista, 'sub' => $sub, 'asesores' => $this->asesores(),
        ];
    }

    private function reglasGeneral(?Cliente $c): array
    {
        return [
            'tipo' => ['required', Rule::in(config('agcloud.tipos_cliente'))],
            'nombre' => ['required', 'string', 'max:200'],
            'nif' => ['required', 'string', 'max:15'],
            'comercial' => ['nullable', 'string', 'max:200'],
            'fecha_alta' => ['nullable', 'date'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'asesor_id' => ['nullable', Rule::exists('users', 'id')->whereIn('role', User::ROLES_DESPACHO)],
            'domicilio' => ['nullable', 'string', 'max:200'],
            'cp' => ['nullable', 'string', 'max:10'],
            'municipio' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', Rule::in(config('agcloud.provincias'))],
            'estado' => ['nullable', Rule::in(array_keys(config('agcloud.estados_cliente')))],
            'portal' => ['nullable', 'boolean'],
            'portal_pass' => ['nullable', 'string', 'min:8', 'max:100'],
        ];
    }

    /** Comprobaciones del prototipo (saveAlta) con sus mensajes */
    private function validarGeneral(Request $request, ?Cliente $c): array
    {
        $v = $request->validate($this->reglasGeneral($c), [], ['nombre' => 'el nombre o la razón social', 'portal_pass' => 'la contraseña del portal']);
        if (trim($v['nombre']) === '') {
            $this->fallo('Indica el nombre o la razón social.');
        }
        $nif = Validacion::normalizaNif($v['nif']);
        $chk = Validacion::nif($nif);
        if (! $chk['ok']) {
            $this->fallo($chk['empty'] ?? false ? 'Indica el NIF del cliente.' : 'El NIF no es correcto. Corrígelo para poder continuar.');
        }
        $dup = Cliente::withTrashed()->where('nif', $nif)->when($c, fn ($w) => $w->where('id', '!=', $c->id))->first();
        if ($dup) {
            $this->fallo('Ya existe un cliente con ese NIF: '.$dup->nombre.($dup->trashed() ? ' (eliminado)' : '').'.');
        }
        if ($request->boolean('portal') && ! filter_var(trim((string) ($v['email'] ?? '')), FILTER_VALIDATE_EMAIL)) {
            $this->fallo('Para dar acceso al portal indica un correo válido: será el usuario del cliente.');
        }
        if ($request->filled('portal_pass') && ! Validacion::contrasenaOk((string) $request->input('portal_pass'))) {
            $this->fallo('La contraseña del portal debe tener al menos 8 caracteres, con letras y números.');
        }
        $v['nif'] = $nif;
        $v['nombre'] = trim($v['nombre']);
        $v['email'] = isset($v['email']) ? mb_strtolower(trim($v['email'])) : null;

        return $v;
    }

    private function fallo(string $msg): never
    {
        throw new HttpResponseException(back()->withInput()->withErrors(['form' => $msg]));
    }

    public function store(Request $request, AccesosPortal $accesos)
    {
        $v = $this->validarGeneral($request, null);
        $portal = $request->boolean('portal');
        $pass = $request->input('portal_pass');

        $c = DB::transaction(function () use ($v) {
            $c = new Cliente(collect($v)->except(['portal', 'portal_pass'])->all());
            $c->estado = 'Alta en curso';
            $c->provincia = $c->provincia ?: config('agcloud.despacho.provincia');
            $c->save();

            return $c;
        });
        Auditoria::registrar('cliente.alta', 'Alta del cliente '.$c->nombre.' ('.$c->nif.')', $c, $c->id);

        $msg = 'Cliente creado. Completa ahora el resto de la ficha.';
        if ($portal) {
            $msg .= ' '.$accesos->invitar($c, $c->email, $c->nombre, User::CLIENTE_ADMIN, $pass);
        }

        if ($request->input('ir') === 'cerrar') {
            return redirect()->route('clientes.show', $c)->with('ok', $msg);
        }

        return redirect()->route('clientes.edit', [$c, 'sec' => 'bancos'])->with('ok', $msg);
    }

    public function update(Request $request, Cliente $cliente, AccesosPortal $accesos)
    {
        $sec = $request->input('sec', 'general');
        if ($sec === 'cuota') {
            $request->validate([
                'cuota_importe' => ['nullable', 'string', 'max:20'],
                'cuota_periodicidad' => ['required', Rule::in(config('agcloud.cuota.periodicidad'))],
                'cuota_iva' => ['required', Rule::in(array_keys(config('agcloud.cuota.iva')))],
                'cuota_inicio' => ['nullable', 'date'],
                'cuota_forma_cobro' => ['required', Rule::in(config('agcloud.cuota.forma_cobro'))],
                'cuota_cuenta' => ['nullable', 'string', 'max:40'],
                'cuota_concepto' => ['nullable', 'string', 'max:200'],
                'cuota_observaciones' => ['nullable', 'string', 'max:2000'],
            ]);
            $imp = Formato::parseNum($request->input('cuota_importe'));
            if ($imp < 0 || $imp > 9999999) {
                $this->fallo('El importe de la cuota no es válido.');
            }
            $cliente->fill($request->only(['cuota_periodicidad', 'cuota_iva', 'cuota_inicio', 'cuota_forma_cobro', 'cuota_cuenta', 'cuota_concepto', 'cuota_observaciones']));
            $cliente->cuota_importe = $imp ?: null;
            $cliente->save();
            Auditoria::registrar('cliente.cuota', 'Cuota actualizada de '.$cliente->nombre, $cliente, $cliente->id);

            return $this->siguiente($request, $cliente, 'cuota', 'Cuota guardada.');
        }

        $v = $this->validarGeneral($request, $cliente);
        $antesTipo = $cliente->tipo;
        $cliente->fill(collect($v)->except(['portal', 'portal_pass'])->all());
        $cliente->save();
        Auditoria::registrar('cliente.edicion', 'Datos generales actualizados de '.$cliente->nombre, $cliente, $cliente->id);

        $msg = 'Datos generales guardados.';
        if ($request->boolean('portal') && ! $cliente->usuarios()->where('email', $cliente->email)->exists()) {
            $msg .= ' '.$accesos->invitar($cliente, $cliente->email, $cliente->nombre, User::CLIENTE_ADMIN, $request->input('portal_pass'));
        }
        if ($antesTipo !== $cliente->tipo) {
            $msg .= ' Has cambiado el tipo de cliente: revisa las secciones de la ficha.';
        }

        return $this->siguiente($request, $cliente, 'general', $msg);
    }

    private function siguiente(Request $request, Cliente $c, string $sec, string $msg)
    {
        $ir = $request->input('ir');
        $secs = array_keys(SeccionesAlta::secciones($c->tipo));
        if ($ir === 'cerrar') {
            return redirect()->route('clientes.show', [$c, 'tab' => 'alta'])->with('ok', $msg);
        }
        if (in_array($ir, $secs, true)) {
            return redirect()->route('clientes.edit', [$c, 'sec' => $ir])->with('ok', $msg);
        }

        return redirect()->route('clientes.edit', [$c, 'sec' => $sec])->with('ok', $msg);
    }
}
