<?php

namespace App\Http\Controllers;

use App\Models\Apunte;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Documento;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Services\Facturae;
use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use App\Support\Validacion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Gestión de la empresa elegida: proveedores y acreedores con sus facturas
 * recibidas, y facturas de clientes (las emitidas en Facturación).
 */
abstract class TerceroController extends Controller
{
    /** proveedor | acreedor (lo fija cada controlador hijo) */
    protected string $clase = 'proveedor';

    public const PAGOS = ['Transferencia', 'Domiciliación SEPA', 'Tarjeta', 'Bizum', 'Efectivo', 'Confirming', 'Pagaré'];

    public function __construct(private AlmacenDocumentos $almacen) {}

    private function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    private function sinCtx(string $volver)
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => $volver])->with('aviso', 'Elige primero la empresa con la que vas a trabajar.');
    }

    private function modulo(string $clase): string
    {
        return ['proveedor' => 'proveedores', 'acreedor' => 'acreedores', 'cliente' => 'ventas'][$clase];
    }

    /** Periodo: año y trimestre (Año, 1T…4T) */
    private function periodo(Request $r): array
    {
        $y = (int) $r->query('y', Plazos::hoy()->year);
        $per = in_array($r->query('per'), ['1T', '2T', '3T', '4T'], true) ? $r->query('per') : 'Año';

        return [$y, $per];
    }

    private function enPeriodo(Builder $q, int $y, string $per): Builder
    {
        $q->whereYear('fecha', $y);
        if ($per !== 'Año') {
            $t = (int) $per[0];
            $q->whereMonth('fecha', '>=', $t * 3 - 2)->whereMonth('fecha', '<=', $t * 3);
        }

        return $q;
    }

    // ============================================================ terceros
    public function index(Request $request)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        $mod = $this->modulo($clase);
        if (! $c) {
            return $this->sinCtx('/'.$mod);
        }
        $q = trim((string) $request->query('q', ''));
        $lista = Tercero::where('cliente_id', $c->id)->where('clase', $clase)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('nombre', 'like', "%$q%")->orWhere('nif', 'like', "%$q%")->orWhere('cuenta', 'like', "%$q%")->orWhere('municipio', 'like', "%$q%")))
            ->orderBy('nombre')->get();
        $y = Plazos::hoy()->year;
        if ($clase === 'cliente') {
            // Clientes de la empresa elegida: lo facturado sale de Facturación (por NIF)
            $fr = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->get();
            $delAno = $fr->filter(fn ($f) => $f->fecha->year === $y);
            $pend = $fr->where('cobrada', false);
            $porNif = $delAno->groupBy(fn ($f) => strtoupper((string) $f->receptor_nif))->map->sum('total');
            $porTercero = $lista->mapWithKeys(fn ($t) => [$t->id => $t->nif ? ($porNif[strtoupper($t->nif)] ?? 0) : 0]);
            $kpis = [['Clientes', Formato::entero($lista->count()), 'Subcuentas 430'], ['Ventas '.$y, Formato::eur($delAno->sum('total')), 'Facturado (total)'], ['Pendiente de cobro', Formato::eur($pend->sum('total')), Formato::entero($pend->count()).' facturas']];
        } else {
            $fr = FacturaRecibida::where('cliente_id', $c->id)->where('clase', Tercero::CLASES[$clase][3])->get();
            $delAno = $fr->filter(fn ($f) => $f->fecha->year === $y);
            $pend = $fr->whereNull('pagada_at');
            $porTercero = $delAno->groupBy('tercero_id')->map->sum('total');
        }
        if ($clase !== 'cliente') {
            $kpis = $clase === 'proveedor'
            ? [['Proveedores', Formato::entero($lista->count()), 'Subcuentas 400'], ['Compras '.$y, Formato::eur($delAno->sum('total')), 'Importe a pagar'], ['Pendiente de pago', Formato::eur($pend->sum('total')), Formato::entero($pend->count()).' facturas']]
            : [['Acreedores', Formato::entero($lista->count()), 'Subcuentas 410'], ['Gastos '.$y, Formato::eur($delAno->sum('total')), 'Importe a pagar'], ['Con retención', Formato::entero($lista->where('retencion', '>', 0)->count()), 'Profesionales y arrendadores']];
        }
        if ($request->query('exportar') === 'csv') {
            return $this->csv($mod.'-'.$c->nif.'.csv', ['Cuenta', 'Nombre', 'NIF', 'Email', 'Teléfono', 'Domicilio', 'CP', 'Municipio', 'Provincia', 'Tipo', 'Retención %', 'Forma de pago'],
                $lista->map(fn ($t) => [$t->cuenta, $t->nombre, $t->nif, $t->email, $t->telefono, $t->domicilio, $t->cp, $t->municipio, $t->provincia, $t->tipo, Formato::num($t->retencion), $t->forma_pago])->all());
        }
        $editar = $request->query('editar') ? $lista->firstWhere('id', (int) $request->query('editar')) : null;
        $par = $clase === 'cliente' ? ['forma_pago' => 'Transferencia', 'pago_dias' => 30] : Parametros::get($mod);

        return view('gestion.terceros', [
            'titulo' => $clase === 'cliente' ? 'Ventas' : Tercero::CLASES[$clase][0], 'activo' => $clase === 'cliente' ? 'clientes' : $mod, 'c' => $c, 'clase' => $clase, 'mod' => $mod, 'q' => $q, 'lista' => $lista, 'kpis' => $kpis,
            'comprasPor' => $porTercero,
            'form' => $request->boolean('nuevo') || $editar, 'editar' => $editar, 'par' => $par,
            'siguiente' => Tercero::siguienteCuenta($c->id, $clase), 'anio' => $y,
            'sinAlta' => $clase === 'cliente' ? count($this->receptoresSinAlta($c)) : 0,
        ]);
    }

    public function store(Request $request)
    {
        return $this->guardar($request, null);
    }

    public function update(Request $request, Tercero $tercero)
    {
        return $this->guardar($request, $tercero);
    }

    private function guardar(Request $request, ?Tercero $tercero)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        abort_unless($c, 403);
        if ($tercero) {
            abort_unless($tercero->cliente_id === $c->id && $tercero->clase === $clase, 404);
        }
        $pre = Tercero::CLASES[$clase][2];
        $d = $request->validate([
            'nombre' => ['required', 'string', 'max:255'], 'nif' => ['nullable', 'string', 'max:20'], 'email' => ['nullable', 'email', 'max:255'], 'telefono' => ['nullable', 'string', 'max:30'],
            'domicilio' => ['nullable', 'string', 'max:255'], 'cp' => ['nullable', 'regex:/^\d{5}$/'], 'municipio' => ['nullable', 'string', 'max:80'], 'provincia' => ['nullable', 'string', 'max:40'],
            'tipo' => ['nullable', Rule::in(Tercero::TIPOS_ACREEDOR)], 'retencion' => ['nullable', Rule::in(['0', '7', '15', '19'])],
            'forma_pago' => ['required', Rule::in(self::PAGOS)], 'pago_dias' => ['required', Rule::in(['0', '15', '30', '45', '60', '90'])],
            'iban' => ['nullable', 'string', 'max:40'], 'cuenta_modo' => ['required', Rule::in(['auto', 'manual'])], 'cuenta' => ['nullable', 'string', 'max:9'],
        ], ['cp.regex' => 'El código postal tiene 5 cifras.', 'nombre.required' => 'Indica el nombre o la razón social.']);
        $nif = strtoupper(preg_replace('/[\s.-]/', '', (string) ($d['nif'] ?? '')));
        $iban = strtoupper(preg_replace('/\s/', '', (string) ($d['iban'] ?? '')));
        $err = match (true) {
            $nif !== '' && preg_match('/^[0-9XYZKLMA-HJNPQRSUVW]/', $nif) && strlen($nif) === 9 && ! Validacion::nifOk($nif) => 'El NIF no es válido (revisa la letra de control).',
            $iban !== '' && ! Validacion::iban($iban) => 'El IBAN no es válido.',
            default => null,
        };
        if ($err) {
            throw ValidationException::withMessages(['form' => $err]);
        }
        if ($d['cuenta_modo'] === 'manual') {
            if (! preg_match('/^'.$pre.'\d{6}$/', (string) $d['cuenta'])) {
                throw ValidationException::withMessages(['form' => 'La cuenta contable tiene 9 cifras y empieza por '.$pre.'.']);
            }
            $cuenta = $d['cuenta'];
        } else {
            $cuenta = $tercero?->cuenta ?? ($clase === 'cliente' && $nif !== '' ? $this->cuenta430($c, $nif) : Tercero::siguienteCuenta($c->id, $clase));
        }
        if (Tercero::where('cliente_id', $c->id)->where('cuenta', $cuenta)->when($tercero, fn ($w) => $w->whereKeyNot($tercero->id))->exists()) {
            throw ValidationException::withMessages(['form' => 'La cuenta '.$cuenta.' ya está asignada a otro '.strtolower(Tercero::CLASES[$clase][1]).'.']);
        }
        $datos = ['cliente_id' => $c->id, 'clase' => $clase, 'cuenta' => $cuenta, 'nombre' => trim($d['nombre']), 'nif' => $nif ?: null, 'email' => $d['email'] ?? null, 'telefono' => $d['telefono'] ?? null,
            'domicilio' => $d['domicilio'] ?? null, 'cp' => $d['cp'] ?? null, 'municipio' => $d['municipio'] ?? null, 'provincia' => $d['provincia'] ?? (Facturae::provinciaDeCp($d['cp'] ?? '') ?: null),
            'tipo' => $clase === 'acreedor' ? ($d['tipo'] ?? 'Servicios') : null, 'retencion' => $clase === 'acreedor' ? (float) ($d['retencion'] ?? 0) : 0,
            'forma_pago' => $d['forma_pago'], 'pago_dias' => (int) $d['pago_dias'], 'iban' => $iban ?: null];
        // Cambio de subcuenta: sus apuntes contables pasan a la nueva (la contabilidad sigue conectada con el tercero)
        if ($tercero && $tercero->cuenta !== $cuenta) {
            $viejo = $tercero->cuenta;
            $cerrado = Apunte::where('apuntes.cliente_id', $c->id)->where('apuntes.cuenta', $viejo)->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->join('ejercicios', 'ejercicios.id', '=', 'asientos.ejercicio_id')->where('ejercicios.estado', 'Cerrado')->exists();
            if ($cerrado) {
                throw ValidationException::withMessages(['form' => 'La cuenta '.$viejo.' tiene apuntes en un ejercicio cerrado: no se puede cambiar.']);
            }
            if (Apunte::where('cliente_id', $c->id)->where('cuenta', $cuenta)->exists() || Cuenta::where('cliente_id', $c->id)->where('codigo', $cuenta)->where('nif', '!=', $nif)->exists()) {
                throw ValidationException::withMessages(['form' => 'La cuenta '.$cuenta.' ya se usa en la contabilidad.']);
            }
            Apunte::where('cliente_id', $c->id)->where('cuenta', $viejo)->update(['cuenta' => $cuenta]);
            Cuenta::where('cliente_id', $c->id)->where('codigo', $viejo)->update(['codigo' => $cuenta]);
        }
        $t = $tercero ? tap($tercero)->update($datos) : Tercero::create($datos);
        Auditoria::registrar('gestion.'.$clase, ($tercero ? 'Modificado: ' : 'Alta: ').$t->nombre.' ('.$t->cuenta.')', $t);

        return redirect()->route($clase === 'cliente' ? 'clientes.index' : $this->modulo($clase).'.index')->with('ok', Tercero::CLASES[$clase][1].' «'.$t->nombre.'» guardado con la cuenta '.$t->cuenta.'.');
    }

    /** Subcuenta 430 del cliente: la que ya creó la contabilidad para ese NIF o la siguiente libre */
    private function cuenta430(Cliente $c, string $nif): string
    {
        $ya = Cuenta::where('cliente_id', $c->id)->where('codigo', 'like', '430%')->where('nif', $nif)->value('codigo');

        return $ya && ! Tercero::where('cliente_id', $c->id)->where('cuenta', $ya)->exists() ? $ya : Tercero::siguienteCuenta($c->id, 'cliente');
    }

    /** Receptores de las facturas emitidas por la empresa que aún no están dados de alta en Ventas (por NIF) */
    private function receptoresSinAlta(Cliente $c): array
    {
        $ya = Tercero::where('cliente_id', $c->id)->where('clase', 'cliente')->whereNotNull('nif')->pluck('nif')->map(fn ($n) => strtoupper($n))->all();
        $out = [];
        foreach (Factura::where('cliente_id', $c->id)->whereNotNull('receptor_nif')->orderByDesc('fecha')->get() as $f) {
            $nif = strtoupper(preg_replace('/[\s.-]/', '', (string) $f->receptor_nif));
            if ($nif !== '' && ! in_array($nif, $ya, true) && ! isset($out[$nif])) {
                $out[$nif] = $f;
            }
        }

        return $out;
    }

    /** Da de alta en Ventas a los clientes que ya aparecen en sus facturas emitidas */
    public function importar(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c && $this->clase === 'cliente', 404);
        $n = 0;
        foreach ($this->receptoresSinAlta($c) as $nif => $f) {
            Tercero::create(['cliente_id' => $c->id, 'clase' => 'cliente', 'cuenta' => $this->cuenta430($c, $nif), 'nombre' => $f->receptor_nombre ?: $nif, 'nif' => $nif,
                'domicilio' => $f->receptor_dir, 'cp' => $f->datos_fe['cp'] ?? null, 'municipio' => $f->datos_fe['municipio'] ?? null, 'provincia' => $f->datos_fe['provincia'] ?? null,
                'forma_pago' => in_array($f->forma_pago, self::PAGOS, true) ? $f->forma_pago : 'Transferencia', 'pago_dias' => (int) ($f->pago_dias ?? 30), 'retencion' => 0]);
            $n++;
        }
        Auditoria::registrar('gestion.cliente', $n.' clientes dados de alta desde sus facturas', null, $c->id);

        return redirect()->route('clientes.index')->with('ok', $n.' '.($n === 1 ? 'cliente dado' : 'clientes dados').' de alta con su subcuenta 430.');
    }

    public function borrar(Request $request, Tercero $tercero)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        abort_unless($c && $tercero->cliente_id === $c->id && $tercero->clase === $clase, 404);
        if ($tercero->facturas()->exists() || ($clase === 'cliente' && $tercero->nif && Factura::where('cliente_id', $c->id)->where('receptor_nif', $tercero->nif)->exists())) {
            return back()->with('aviso', 'No se puede eliminar: tiene facturas registradas.');
        }
        Auditoria::registrar('gestion.'.$clase.'.baja', 'Eliminado: '.$tercero->nombre, $tercero);
        $tercero->delete();

        return redirect()->route($clase === 'cliente' ? 'clientes.index' : $this->modulo($clase).'.index')->with('ok', Tercero::CLASES[$clase][1].' eliminado.');
    }

    // ===================================================== facturas recibidas
    /** Gastos cuyo IVA no es deducible (pestaña propia en Gastos) */
    public function noDeducibles(Request $request)
    {
        return $this->facturas($request, true);
    }

    public function facturas(Request $request, bool $nd = false)
    {
        $clase = $this->clase;
        $nd = $nd && $clase === 'acreedor';
        $c = $this->ctx($request);
        $mod = $this->modulo($clase);
        if (! $c) {
            return $this->sinCtx('/'.$mod.'/facturas');
        }
        [$y, $per] = $this->periodo($request);
        $chip = $nd ? 2 : max(0, min(3, (int) $request->query('chip', 0)));
        $q = trim((string) $request->query('q', ''));
        $tipo = Tercero::CLASES[$clase][3];
        $base = $this->enPeriodo(FacturaRecibida::where('cliente_id', $c->id)->where('clase', $tipo), $y, $per)->with(['tercero', 'documento']);
        $todas = (clone $base)->orderByDesc('fecha')->orderByDesc('id')->get();
        $hoy = Plazos::hoy();
        $chips = $clase === 'proveedor'
            ? ['Todas' => fn ($f) => true, 'Pendientes' => fn ($f) => ! $f->pagada_at && ! $f->vencida(), 'Vencidas' => fn ($f) => $f->vencida(), 'Pagadas' => fn ($f) => (bool) $f->pagada_at]
            : ['Todas' => fn ($f) => true, 'IVA deducible' => fn ($f) => $f->deducible, 'IVA no deducible' => fn ($f) => ! $f->deducible, 'Pendientes de pago' => fn ($f) => ! $f->pagada_at];
        $fn = array_values($chips)[$chip];
        $ql = mb_strtolower($q);
        $lista = $todas->filter($fn)->filter(fn ($f) => $ql === '' || str_contains(mb_strtolower($f->num.' '.$f->tercero?->nombre.' '.$f->tercero?->nif.' '.$f->concepto), $ql))->values();
        $kpis = $clase === 'proveedor'
            ? [['Compras (base)', Formato::eur($todas->sum('base')), Formato::entero($todas->count()).' facturas'], ['IVA soportado', Formato::eur($todas->where('deducible', true)->sum('cuota')), 'Deducible en el 303'], ['Pendiente de pago', Formato::eur($todas->whereNull('pagada_at')->sum('total')), Formato::entero($todas->filter->vencida()->count()).' vencidas']]
            : [['Gastos (base)', Formato::eur($todas->sum('base')), Formato::entero($todas->count()).' facturas'], ['IVA soportado deducible', Formato::eur($todas->where('deducible', true)->sum('cuota')), 'Entra en el 303'], ['Retenciones practicadas', Formato::eur($todas->sum('retencion')), 'Modelos 111 y 115']];
        if ($request->query('exportar') === 'csv') {
            return $this->csv('facturas-'.$mod.'-'.$c->nif.'-'.$y.'.csv', ['Nº', 'Fecha', Tercero::CLASES[$clase][1], 'NIF', 'Concepto', 'Base', 'IVA %', 'Cuota IVA', 'Retención', 'A pagar', 'IVA deducible', 'Vencimiento', 'Pagada'],
                $lista->map(fn ($f) => [$f->num, Formato::fecha($f->fecha), $f->tercero?->nombre, $f->tercero?->nif, $f->concepto, Formato::num($f->base), Formato::num($f->iva_pct), Formato::num($f->cuota), Formato::num($f->retencion), Formato::num($f->total), $f->deducible ? 'Sí' : 'No', Formato::fecha($f->vencimiento), $f->pagada_at ? Formato::fecha($f->pagada_at) : ''])->all());
        }
        $editar = $request->query('editar') ? FacturaRecibida::where('cliente_id', $c->id)->where('clase', $tipo)->find((int) $request->query('editar')) : null;
        $terceros = Tercero::where('cliente_id', $c->id)->where('clase', $clase)->orderBy('nombre')->get();

        return view('gestion.recibidas', [
            'titulo' => 'Facturas de '.mb_strtolower(Tercero::CLASES[$clase][0]), 'activo' => $mod, 'c' => $c, 'clase' => $clase, 'mod' => $mod, 'y' => $y, 'per' => $per, 'chip' => $chip,
            'chips' => array_keys($chips), 'q' => $q, 'lista' => $lista, 'kpis' => $kpis, 'terceros' => $terceros, 'editar' => $editar,
            'form' => $request->boolean('nuevo') || $editar, 'hoy' => $hoy, 'anios' => range($hoy->year, $hoy->year - 5), 'nd' => $nd,
            'url' => fn (array $x = []) => route($nd ? 'acreedores.nodeducibles' : $mod.'.facturas', array_filter(array_merge(['y' => $y === $hoy->year ? null : $y, 'per' => $per === 'Año' ? null : $per, 'chip' => $nd || ! $chip ? null : $chip, 'q' => $q ?: null], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    public function storeFactura(Request $request)
    {
        return $this->guardarFactura($request, null);
    }

    public function updateFactura(Request $request, FacturaRecibida $factura)
    {
        return $this->guardarFactura($request, $factura);
    }

    private function guardarFactura(Request $request, ?FacturaRecibida $factura)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $tipo = Tercero::CLASES[$clase][3];
        if ($factura) {
            abort_unless($factura->cliente_id === $c->id && $factura->clase === $tipo, 404);
            if ($factura->asiento_id) {
                throw ValidationException::withMessages(['form' => 'Esta factura ya está contabilizada. Para cambiarla, borra antes su asiento en Contabilidad › Libro diario (vuelve a la bandeja).']);
            }
        }
        $d = $request->validate([
            'tercero_id' => ['required', 'integer'], 'num' => ['required', 'string', 'max:60'], 'fecha' => ['required', 'date', 'before_or_equal:today'],
            'concepto' => ['required', 'string', 'max:255'], 'base' => ['required', 'string', 'max:20'], 'iva_pct' => ['required', Rule::in(['21', '10', '5', '4', '0'])],
            'ret_pct' => ['nullable', Rule::in(['0', '1', '7', '15', '19'])], 'deducible' => ['nullable', 'boolean'], 'forma_pago' => ['required', Rule::in(self::PAGOS)],
            'vencimiento' => ['nullable', 'date'], 'archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('agcloud.max_upload_kb')],
        ], ['num.required' => 'Indica el número de la factura.', 'concepto.required' => 'Escribe el concepto.', 'fecha.before_or_equal' => 'La fecha de la factura no puede ser futura.']);
        $t = Tercero::where('cliente_id', $c->id)->where('clase', $clase)->find((int) $d['tercero_id']);
        if (! $t) {
            throw ValidationException::withMessages(['form' => 'Elige el '.strtolower(Tercero::CLASES[$clase][1]).'.']);
        }
        $base = Formato::parseNum($d['base']);
        if ($base == 0) {
            throw ValidationException::withMessages(['form' => 'La base imponible no puede ser cero.']);
        }
        $num = trim($d['num']);
        if (FacturaRecibida::where('cliente_id', $c->id)->where('tercero_id', $t->id)->where('num', $num)->when($factura, fn ($w) => $w->whereKeyNot($factura->id))->exists()) {
            throw ValidationException::withMessages(['form' => 'Ya está registrada la factura '.$num.' de '.$t->nombre.'.']);
        }
        $ret = $clase === 'acreedor' ? (float) ($d['ret_pct'] ?? 0) : 0;
        $imp = FacturaRecibida::importes($base, (float) $d['iva_pct'], $ret);
        $venc = $d['vencimiento'] ?? CarbonImmutable::parse($d['fecha'])->addDays((int) $t->pago_dias)->toDateString();
        $datos = ['cliente_id' => $c->id, 'tercero_id' => $t->id, 'clase' => $tipo, 'num' => $num, 'fecha' => $d['fecha'], 'concepto' => trim($d['concepto']), 'base' => $base,
            'iva_pct' => (float) $d['iva_pct'], 'ret_pct' => $ret, 'deducible' => $clase === 'proveedor' ? true : $request->boolean('deducible'), 'forma_pago' => $d['forma_pago'], 'vencimiento' => $venc] + $imp;
        $f = $factura ? tap($factura)->update($datos) : FacturaRecibida::create($datos + ['created_by' => $request->user()->id]);
        if ($request->hasFile('archivo')) {
            $file = $request->file('archivo');
            $g = $this->almacen->guardar($file, $c->id);
            $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
            $doc = Documento::create([
                'cliente_id' => $c->id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => 'contable/recibidas', 'tipo' => 'fra',
                'nombre' => ClasificadorDocumentos::nombreSeguro($f->fecha->format('Ymd').'_fra_'.$num.'_'.$t->nombre.'.'.$ext), 'nombre_original' => ClasificadorDocumentos::nombreSeguro($file->getClientOriginalName()),
                'fecha' => $f->fecha->toDateString(), 'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'], 'origen' => 'Despacho', 'subido_por' => $request->user()->id,
            ]);
            $f->update(['documento_id' => $doc->id]);
        }
        Auditoria::registrar('gestion.factura_recibida', ($factura ? 'Modificada' : 'Registrada').' la factura '.$num.' de '.$t->nombre.' · '.Formato::eur($f->total), $f);

        $volver = $request->input('volver') === 'nd' && $clase === 'acreedor' ? 'acreedores.nodeducibles' : $this->modulo($clase).'.facturas';

        return redirect()->route($volver)->with('ok', 'Factura '.$num.' de '.$t->nombre.' guardada'.($f->deducible ? '.' : ' · IVA no deducible: se suma al gasto.'));
    }

    public function pagar(Request $request, FacturaRecibida $factura)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        abort_unless($c && $factura->cliente_id === $c->id && $factura->clase === Tercero::CLASES[$clase][3], 404);
        if ($factura->pago_asiento_id) {
            return back()->with('aviso', 'El pago de '.$factura->num.' ya está contabilizado. Para deshacerlo, borra antes su asiento en Contabilidad › Libro diario.');
        }
        $factura->update(['pagada_at' => $factura->pagada_at ? null : now()->toDateString()]);

        return back()->with('ok', 'Factura '.$factura->num.($factura->pagada_at ? ' marcada como pagada.' : ': pago anulado.'));
    }

    public function borrarFactura(Request $request, FacturaRecibida $factura)
    {
        $clase = $this->clase;
        $c = $this->ctx($request);
        abort_unless($c && $factura->cliente_id === $c->id && $factura->clase === Tercero::CLASES[$clase][3], 404);
        if ($factura->asiento_id) {
            return back()->with('aviso', 'Esta factura ya está contabilizada. Borra antes su asiento en Contabilidad › Libro diario.');
        }
        Auditoria::registrar('gestion.factura_recibida.baja', 'Eliminada la factura '.$factura->num.' de '.$factura->tercero?->nombre, $factura);
        $factura->delete();

        return redirect()->route($this->modulo($clase).'.facturas')->with('ok', 'Factura eliminada. Su PDF, si lo tenía, sigue en Documentos.');
    }

    // ==================================================== facturas de clientes
    public function facturasClientes(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinCtx('/clientes/facturas');
        }
        [$y, $per] = $this->periodo($request);
        $chip = max(0, min(3, (int) $request->query('chip', 0)));
        $q = trim((string) $request->query('q', ''));
        $hoy = Plazos::hoy();
        $todas = $this->enPeriodo(Factura::where('cliente_id', $c->id)->where('estado', 'Emitida'), $y, $per)->orderByDesc('fecha')->orderByDesc('id')->get();
        $venc = fn ($f) => $f->vencimiento() ?? $f->fecha;
        $chips = ['Todas' => fn ($f) => true, 'Pendientes' => fn ($f) => ! $f->cobrada && $venc($f)->gte($hoy), 'Vencidas' => fn ($f) => ! $f->cobrada && $venc($f)->lt($hoy), 'Cobradas' => fn ($f) => $f->cobrada];
        $ql = mb_strtolower($q);
        $lista = $todas->filter(array_values($chips)[$chip])->filter(fn ($f) => $ql === '' || str_contains(mb_strtolower($f->num.' '.$f->receptor_nombre.' '.$f->receptor_nif), $ql))->values();
        $pend = $todas->where('cobrada', false);
        $kpis = [['Base facturada', Formato::eur($todas->sum('base')), Formato::entero($todas->count()).' facturas'], ['IVA repercutido', Formato::eur($todas->sum('cuota')), 'Del periodo elegido'],
            ['Pendiente de cobro', Formato::eur($pend->sum('total')), Formato::entero($pend->filter(fn ($f) => $venc($f)->lt($hoy))->count()).' vencidas']];
        if ($request->query('exportar') === 'csv') {
            return $this->csv('facturas-clientes-'.$c->nif.'-'.$y.'.csv', ['Nº', 'Fecha', 'Cliente', 'NIF', 'Base', 'IVA', 'Retención', 'Total', 'Vencimiento', 'Estado'],
                $lista->map(fn ($f) => [$f->num, Formato::fecha($f->fecha), $f->receptor_nombre, $f->receptor_nif, Formato::num($f->base), Formato::num($f->cuota), Formato::num($f->retencion), Formato::num($f->total), Formato::fecha($venc($f)), $f->cobrada ? 'Cobrada' : ($venc($f)->lt($hoy) ? 'Vencida' : 'Pendiente')])->all());
        }

        return view('gestion.facturas-clientes', [
            'titulo' => 'Facturas de clientes', 'activo' => 'clientes', 'c' => $c, 'y' => $y, 'per' => $per, 'chip' => $chip, 'chips' => array_keys($chips), 'q' => $q,
            'lista' => $lista, 'kpis' => $kpis, 'hoy' => $hoy, 'venc' => $venc, 'anios' => range($hoy->year, $hoy->year - 5),
            'url' => fn (array $x = []) => route('clientes.facturas', array_filter(array_merge(['y' => $y === $hoy->year ? null : $y, 'per' => $per === 'Año' ? null : $per, 'chip' => $chip ?: null, 'q' => $q ?: null], $x), fn ($v) => $v !== null && $v !== '')),
        ]);
    }

    private function csv(string $nombre, array $cab, array $filas)
    {
        return response()->streamDownload(function () use ($cab, $filas) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, $cab, ';');
            foreach ($filas as $f) {
                fputcsv($o, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $f), ';');
            }
            fclose($o);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
