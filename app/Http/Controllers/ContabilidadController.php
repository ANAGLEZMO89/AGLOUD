<?php

namespace App\Http\Controllers;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Ejercicio;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Contabilidad de la empresa elegida (PGC-PYMES). */
class ContabilidadController extends Controller
{
    public function __construct(protected Contabilidad $conta) {}

    protected function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    /** Empresa y ejercicio de trabajo (?ej=2026) */
    protected function base(Request $r, string $tab): array|RedirectResponse
    {
        $c = $this->ctx($r);
        if (! $c) {
            return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/contabilidad'])->with('aviso', 'Elige primero la empresa cuya contabilidad vas a llevar.');
        }
        $anio = (int) $r->query('ej', $r->session()->get('conta_ej.'.$c->id, Plazos::hoy()->year));
        $r->session()->put('conta_ej.'.$c->id, $anio);
        $ej = $this->conta->ejercicio($c, $anio);
        $ejercicios = Ejercicio::where('cliente_id', $c->id)->orderByDesc('anio')->get();

        return ['titulo' => 'Contabilidad', 'activo' => 'contabilidad', 'c' => $c, 'tab' => $tab, 'ej' => $ej, 'ejercicios' => $ejercicios,
            'nBandeja' => $this->pendientes($c, $ej)->count(), 'nAsientos' => Asiento::where('ejercicio_id', $ej->id)->count()];
    }

    protected function pendientes(Cliente $c, Ejercicio $ej): Collection
    {
        $em = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->whereNull('asiento_id')->whereYear('fecha', $ej->anio)->orderBy('fecha')->get()
            ->map(fn ($f) => ['k' => 'e'.$f->id, 'tipo' => 'Emitida', 'id' => $f->id, 'num' => $f->num, 'fecha' => $f->fecha, 'ter' => $f->receptor_nombre ?: 'Factura simplificada', 'nif' => $f->receptor_nif,
                'base' => (float) $f->base, 'iva' => (float) $f->cuota, 'ret' => (float) $f->retencion, 'total' => (float) $f->total, 'cuenta' => Contabilidad::cuentaPar('ventas'), 'url' => route('facturacion.show', $f)]);
        $re = FacturaRecibida::with('tercero')->where('cliente_id', $c->id)->whereNull('asiento_id')->whereYear('fecha', $ej->anio)->orderBy('fecha')->get()
            ->map(fn ($f) => ['k' => 'r'.$f->id, 'tipo' => $f->clase === 'compra' ? 'Compra' : 'Gasto', 'id' => $f->id, 'num' => $f->num, 'fecha' => $f->fecha, 'ter' => $f->tercero?->nombre, 'nif' => $f->tercero?->nif,
                'base' => (float) $f->base, 'iva' => (float) $f->cuota, 'ret' => (float) $f->retencion, 'total' => (float) $f->total, 'cuenta' => $this->conta->cuentaGasto($f), 'deducible' => $f->deducible,
                'url' => route($f->clase === 'compra' ? 'proveedores.facturas' : 'acreedores.facturas', ['editar' => $f->id])]);

        // Documentos subidos a la bandeja (pendientes de revisar con OCR)
        $docs = \App\Models\BandejaDocumento::with('documento')->where('cliente_id', $c->id)->get()
            ->map(fn ($d) => ['k' => 'd'.$d->id, 'tipo' => 'Documento', 'id' => $d->id, 'num' => '—', 'fecha' => $d->created_at->toImmutable()->startOfDay(), 'ter' => $d->documento?->nombre_original ?: 'Documento', 'nif' => '',
                'base' => 0.0, 'iva' => 0.0, 'ret' => 0.0, 'total' => 0.0, 'cuenta' => '', 'origen' => $d->origen, 'url' => route('contabilidad.bandeja', ['revisar' => 'd'.$d->id])]);

        return $em->concat($re)->sortBy(fn ($x) => $x['fecha']->toDateString())->values()->concat($docs);
    }

    // ------------------------------------------------------------ resumen
    public function index(Request $request)
    {
        $b = $this->base($request, 'resumen');
        if (! is_array($b)) {
            return $b;
        }
        $ej = $b['ej'];
        $r = $this->conta->resultado($ej);
        $mes = Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('asientos.ejercicio_id', $ej->id)
            ->whereNotIn('asientos.tipo', ['Regularización', 'Cierre'])->where(fn ($q) => $q->where('apuntes.cuenta', 'like', '6%')->orWhere('apuntes.cuenta', 'like', '7%'))
            ->get(['asientos.fecha', 'apuntes.cuenta', 'apuntes.debe', 'apuntes.haber']);
        $grafica = collect(range(1, 12))->map(function ($m) use ($mes) {
            $x = $mes->filter(fn ($a) => (int) substr((string) $a->fecha, 5, 2) === $m);

            return ['ing' => $x->filter(fn ($a) => $a->cuenta[0] === '7')->sum(fn ($a) => $a->haber - $a->debe), 'gas' => $x->filter(fn ($a) => $a->cuenta[0] === '6')->sum(fn ($a) => $a->debe - $a->haber)];
        });
        $max = max(1, $grafica->max(fn ($g) => max($g['ing'], $g['gas'])));
        $sumas = $this->conta->sumas($ej);

        return view('contabilidad.resumen', $b + [
            'r' => $r, 'grafica' => $grafica, 'max' => $max,
            'cuadra' => abs($sumas->sum('d') - $sumas->sum('h')) < 0.01,
            'tesoreria' => $sumas->filter(fn ($v, $k) => str_starts_with((string) $k, '57'))->sum(fn ($v) => $v['d'] - $v['h']),
            'clientes' => $sumas->filter(fn ($v, $k) => str_starts_with((string) $k, '43'))->sum(fn ($v) => $v['d'] - $v['h']),
            'proveedores' => $sumas->filter(fn ($v, $k) => str_starts_with((string) $k, '40') || str_starts_with((string) $k, '41'))->sum(fn ($v) => $v['h'] - $v['d']),
        ]);
    }

    // ------------------------------------------------------------ bandeja
    public function bandeja(Request $request)
    {
        $b = $this->base($request, 'bandeja');
        if (! is_array($b)) {
            return $b;
        }

        return view('contabilidad.bandeja', $b + ['lista' => $this->pendientes($b['c'], $b['ej']), 'propias' => Contabilidad::propias($b['c'])]);
    }

    public function contabilizar(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $request->validate(['k' => ['required', 'array', 'max:500'], 'k.*' => ['regex:/^[er]\d+$/'], 'cta' => ['nullable', 'array'], 'cta.*' => ['nullable', 'regex:/^\d{3,9}$/']]);
        $ok = 0;
        $errores = [];
        foreach ($request->input('k') as $k) {
            try {
                if ($k[0] === 'e') {
                    $f = Factura::where('cliente_id', $c->id)->findOrFail((int) substr($k, 1));
                    $this->conta->contabilizarEmitida($f, $request->user()->id);
                } else {
                    $f = FacturaRecibida::with('tercero')->where('cliente_id', $c->id)->findOrFail((int) substr($k, 1));
                    $cta = $request->input('cta.'.$k);
                    if ($cta && ! in_array(Contabilidad::a9($cta)[0], ['6', '2'], true)) {
                        throw new InvalidArgumentException('La cuenta de gasto de la factura '.$f->num.' debe ser del grupo 6 (o 2 si es inmovilizado).');
                    }
                    $this->conta->contabilizarRecibida($f, $request->user()->id, $cta ?: null);
                }
                $ok++;
            } catch (InvalidArgumentException $e) {
                $errores[] = $e->getMessage();
            }
        }
        if ($ok) {
            Auditoria::registrar('contabilidad.bandeja', $ok.' facturas contabilizadas', null, $c->id);
        }

        return back()->with($errores ? 'aviso' : 'ok', ($ok ? $ok.' '.($ok === 1 ? 'factura contabilizada' : 'facturas contabilizadas').'. ' : '').implode(' ', $errores));
    }

    // ------------------------------------------------------------ diario
    public function diario(Request $request)
    {
        $b = $this->base($request, 'diario');
        if (! is_array($b)) {
            return $b;
        }
        $q = trim((string) $request->query('q', ''));
        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $lista = Asiento::with('apuntes')->where('ejercicio_id', $b['ej']->id)
            ->when($desde, fn ($w) => $w->where('fecha', '>=', $desde))->when($hasta, fn ($w) => $w->where('fecha', '<=', $hasta))
            ->when($q !== '', function ($w) use ($q) {
                if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $q, $m)) {
                    return $w->whereBetween('numero', [(int) $m[1], (int) $m[2]]);
                }
                $w->where(fn ($x) => $x->where('concepto', 'like', "%$q%")->orWhere('numero', ctype_digit($q) ? (int) $q : -1)
                    ->orWhereHas('apuntes', fn ($a) => $a->where('cuenta', 'like', preg_replace('/\D/', '', $q).'%')->whereRaw('? <> ""', [preg_replace('/\D/', '', $q)])));
            })
            ->orderBy('numero')->limit(1000)->get();
        $propias = Contabilidad::propias($b['c']);
        if ($request->query('exportar') === 'csv') {
            return $this->csv('libro-diario-'.$b['c']->nif.'-'.$b['ej']->anio.'.csv', ['Asiento', 'Fecha', 'Concepto', 'Cuenta', 'Nombre', 'Debe', 'Haber'],
                $lista->flatMap(fn ($a) => $a->apuntes->map(fn ($p) => [$a->numero, Formato::fecha($a->fecha), $p->concepto ?: $a->concepto, $p->cuenta, Contabilidad::nombre($p->cuenta, $propias), Formato::num($p->debe), Formato::num($p->haber)]))->all());
        }
        $todos = Apunte::whereIn('asiento_id', Asiento::where('ejercicio_id', $b['ej']->id)->select('id'));

        return view('contabilidad.diario', $b + ['lista' => $lista, 'q' => $q, 'desde' => $desde, 'hasta' => $hasta, 'propias' => $propias,
            'sumD' => (clone $todos)->sum('debe'), 'sumH' => (clone $todos)->sum('haber')]);
    }

    public function borrar(Request $request, Asiento $asiento)
    {
        $c = $this->ctx($request);
        abort_unless($c && $asiento->cliente_id === $c->id, 404);
        try {
            $this->conta->borrar($asiento);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('contabilidad.asiento.baja', 'Asiento '.$asiento->numero.' eliminado: '.$asiento->concepto, null, $c->id);

        return back()->with('ok', 'Asiento '.$asiento->numero.' eliminado.'.($asiento->origen ? ' La factura vuelve a la bandeja.' : ''));
    }

    // ------------------------------------------------------- mantenimiento de apuntes
    /** Plantillas de asiento más usadas: [nombre, cuentas] (prototipo + habituales en gestoría) */
    public const PLANTILLAS = [
        ['Ingreso (factura emitida)', ['430000000', '705000000', '477000000']], ['Gasto (factura de acreedor)', ['629000000', '472000000', '410000000']],
        ['Compra (factura de proveedor)', ['600000000', '472000000', '400000000']], ['Cobro de cliente', ['572000000', '430000000']],
        ['Pago a proveedor', ['400000000', '572000000']], ['Pago a acreedor', ['410000000', '572000000']], ['Gasto bancario', ['626000000', '572000000']],
        ['Liquidación de IVA', ['477000000', '472000000', '475000000']], ['Pago del IVA (303)', ['475000000', '572000000']], ['Liquidación de IRPF (111/115)', ['475100000', '572000000']],
        ['Pago de nóminas', ['465000000', '572000000']], ['Pago de seguros sociales', ['476000000', '572000000']], ['Cuota de autónomos (RETA)', ['642000000', '572000000']],
        ['Alquiler del local', ['621000000', '472000000', '475100000', '410000000']], ['Suministros', ['628000000', '472000000', '410000000']],
        ['Aportación de capital', ['572000000', '100000000']], ['Préstamo recibido', ['572000000', '520000000']], ['Cuota de préstamo', ['520000000', '662000000', '572000000']],
        ['Amortización del inmovilizado', ['681000000', '281000000']], ['Ingreso de efectivo en el banco', ['572000000', '570000000']], ['Retirada de efectivo', ['570000000', '572000000']],
    ];

    /** Partidas del asiento de nómina: [clave, nombre, parámetro de cuenta, lado] */
    public const NOMINA = [
        ['mon', 'Remuneraciones monetarias', 'sueldos', 'd'], ['esp', 'Remuneraciones en especie', 'especie', 'd'], ['diet', 'Dietas', 'dietas', 'd'], ['ind', 'Indemnizaciones', 'indemn', 'd'],
        ['ssE', 'Seguridad Social empresa', 'ss_emp', 'd'], ['ant', 'Anticipo', 'anticipos', 'h'], ['emb', 'Embargo', 'embargos', 'h'], ['dedE', 'Deducciones en especie', 'ded_esp', 'h'],
        ['otr', 'Otras deducciones', 'otras_ded', 'h'], ['irpf', 'IRPF', 'acr_irpf_nom', 'h'], ['irpfE', 'IRPF en especie', 'acr_irpf_esp', 'h'], ['ssT', 'Seguridad Social empleado', 'ss_acr', 'h'],
        ['neto', 'Salario neto', 'rem_pend', 'h'],
    ];

    public function apuntes(Request $request)
    {
        $b = $this->base($request, 'apuntes');
        if (! is_array($b)) {
            return $b;
        }
        $a = $request->query('editar') ? Asiento::with('apuntes')->where('cliente_id', $b['c']->id)->find((int) $request->query('editar')) : null;
        $lineas = old('l', $a ? $a->apuntes->map(fn ($p) => ['c' => $p->cuenta, 'd' => (float) $p->debe ? Formato::num($p->debe) : '', 'h' => (float) $p->haber ? Formato::num($p->haber) : '', 't' => $p->concepto, 'doc' => $p->documento])->all() : [['c' => '', 'd' => '', 'h' => '', 't' => '', 'doc' => ''], ['c' => '', 'd' => '', 'h' => '', 't' => '', 'doc' => '']]);
        $par = Parametros::get('contabilidad');
        $a9 = fn ($k) => ($par[$k] ?? '') !== '' ? Contabilidad::a9((string) $par[$k]) : '';
        $guardado = $request->query('guardado') ? Asiento::with('apuntes')->where('cliente_id', $b['c']->id)->find((int) $request->query('guardado')) : null;

        return view('contabilidad.apuntes', $b + ['a' => $a, 'lineas' => array_values($lineas), 'catalogo' => $this->catalogo($b['c']), 'guardado' => $guardado,
            'ultimos' => Asiento::with('apuntes')->where('ejercicio_id', $b['ej']->id)->orderByDesc('numero')->limit(8)->get(),
            'cfg' => ['ventas' => $a9('ventas'), 'compras' => $a9('compras'), 'gasto' => $a9('gasto_servicios'), 'ivaRep' => $a9('iva_repercutido'), 'ivaSop' => $a9('iva_soportado'),
                'retSop' => $a9('ret_soportada'), 'retPrac' => $a9('ret_practicada'), 'nif' => (string) $b['c']->nif, 'empresa' => (string) $b['c']->nombre,
                'nomina' => collect(self::NOMINA)->mapWithKeys(fn ($r) => [$r[0] => $a9($r[2])])->all()],
            'tipo' => old('tipo', $request->query('tipo', 'Manual')),
            // NIF → subcuenta (proveedores, acreedores y clientes) para que el OCR proponga el tercero
            'terceros' => \App\Models\Tercero::where('cliente_id', $b['c']->id)->whereNotNull('nif')->pluck('cuenta', 'nif')->merge(Cuenta::where('cliente_id', $b['c']->id)->whereNotNull('nif')->pluck('codigo', 'nif'))->all()]);
    }

    /** Cuentas para el autocompletado: PGC (a 9 dígitos) y subcuentas propias */
    protected function catalogo(Cliente $c): array
    {
        Contabilidad::sincronizarBancos($c);
        $out = [];
        foreach (config('pgc') as $k => $n) {
            $out[Contabilidad::a9((string) $k)] = $n;
        }
        foreach (Contabilidad::propias($c) as $k => $n) {
            $out[(string) $k] = $n;
        }
        foreach (Contabilidad::ocultas($c) as $k) {
            unset($out[$k]);
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    public function guardarAsiento(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $request->validate(['fecha' => ['required', 'date'], 'concepto' => ['required', 'string', 'max:255'], 'l' => ['required', 'array', 'min:2', 'max:200'],
            'l.*.c' => ['nullable', 'string', 'max:12'], 'l.*.d' => ['nullable', 'string', 'max:20'], 'l.*.h' => ['nullable', 'string', 'max:20'], 'l.*.t' => ['nullable', 'string', 'max:255'],
            'l.*.doc' => ['nullable', 'string', 'max:40'], 'editar' => ['nullable', 'integer'], 'tipo' => ['nullable', 'in:Manual,Factura,Nómina'],
            'archivo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('agcloud.max_upload_kb')]],
            ['concepto.required' => 'Escribe el concepto del asiento.']);
        $ls = collect($request->input('l'))->filter(fn ($l) => trim((string) ($l['c'] ?? '')) !== '' || trim((string) ($l['d'] ?? '')) !== '' || trim((string) ($l['h'] ?? '')) !== '')
            ->map(fn ($l) => [trim((string) ($l['c'] ?? '')), Formato::parseNum($l['d'] ?? ''), Formato::parseNum($l['h'] ?? ''), trim((string) ($l['t'] ?? '')) ?: null, trim((string) ($l['doc'] ?? ''))])->values()->all();
        foreach ($ls as $l) {
            if (! preg_match('/^\d{3,9}$/', $l[0])) {
                throw ValidationException::withMessages(['form' => 'Cada apunte necesita una cuenta (de 3 a 9 cifras).']);
            }
        }
        $sust = $request->filled('editar') ? Asiento::where('cliente_id', $c->id)->findOrFail((int) $request->input('editar')) : null;
        if ($sust && $sust->origen) {
            throw ValidationException::withMessages(['form' => 'Este asiento viene de una factura: para cambiarlo, bórralo (la factura vuelve a la bandeja) y contabilízala de nuevo.']);
        }
        if ($sust && $sust->fecha->year !== (int) substr($request->input('fecha'), 0, 4)) {
            throw ValidationException::withMessages(['form' => 'No se puede mover un asiento a otro ejercicio.']);
        }
        $tipo = $sust?->tipo ?? (string) ($request->input('tipo') ?: 'Manual');
        try {
            $a = $this->conta->asentar($c, $request->input('fecha'), trim($request->input('concepto')), $ls, $tipo, null, null, $request->user()->id, $sust);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        // Justificante (factura o nómina) al gestor documental
        if ($request->hasFile('archivo')) {
            $file = $request->file('archivo');
            $g = app(\App\Services\AlmacenDocumentos::class)->guardar($file, $c->id);
            $nom = $tipo === 'Nómina' ? ['laboral/nominas', 'nom'] : ['contable/recibidas', 'fra'];
            $ext = strtolower($file->getClientOriginalExtension() ?: 'pdf');
            $doc = \App\Models\Documento::create([
                'cliente_id' => $c->id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => $nom[0], 'tipo' => $nom[1],
                'nombre' => \App\Support\ClasificadorDocumentos::nombreSeguro($a->fecha->format('Ymd').'_'.$nom[1].'_asiento-'.$a->numero.'.'.$ext),
                'nombre_original' => \App\Support\ClasificadorDocumentos::nombreSeguro($file->getClientOriginalName()), 'fecha' => $a->fecha->toDateString(),
                'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'], 'origen' => 'Despacho', 'subido_por' => $request->user()->id,
            ]);
            $a->update(['documento_id' => $doc->id]);
        }
        Auditoria::registrar('contabilidad.asiento', ($sust ? 'Modificado' : 'Creado').' el asiento '.$a->numero.' ('.$tipo.'): '.$a->concepto, null, $c->id);

        return redirect()->route('contabilidad.apuntes', ['ej' => $a->fecha->year, 'guardado' => $a->id, 'tipo' => $sust ? null : $tipo])
            ->with('ok', 'Asiento '.$a->numero.' '.($sust ? 'modificado' : 'registrado').'.');
    }

    // ------------------------------------------------------------ mayor
    public function mayor(Request $request)
    {
        $b = $this->base($request, 'extracto');
        if (! is_array($b)) {
            return $b;
        }
        $cta = preg_replace('/\D/', '', (string) $request->query('c', ''));
        $c9 = $cta !== '' ? Contabilidad::a9($cta) : null;
        $movs = collect();
        if ($cta !== '') {
            $movs = Apunte::query()->join('asientos', 'asientos.id', '=', 'apuntes.asiento_id')->where('asientos.ejercicio_id', $b['ej']->id)
                ->where('apuntes.cuenta', 'like', (strlen($cta) >= 9 ? $c9 : $cta).'%')
                ->orderBy('asientos.fecha')->orderBy('asientos.numero')->orderBy('apuntes.orden')
                ->get(['asientos.numero', 'asientos.fecha', 'asientos.concepto as aconcepto', 'apuntes.concepto', 'apuntes.cuenta', 'apuntes.debe', 'apuntes.haber']);
        }
        $saldo = 0;
        $movs = $movs->map(function ($m) use (&$saldo) {
            $saldo = round($saldo + $m->debe - $m->haber, 2);
            $m->saldo = $saldo;

            return $m;
        });
        $propias = Contabilidad::propias($b['c']);
        if ($request->query('exportar') === 'csv' && $cta !== '') {
            return $this->csv('mayor-'.$cta.'-'.$b['ej']->anio.'.csv', ['Asiento', 'Fecha', 'Cuenta', 'Concepto', 'Debe', 'Haber', 'Saldo'],
                $movs->map(fn ($m) => [$m->numero, Formato::fecha(substr((string) $m->fecha, 0, 10)), $m->cuenta, $m->concepto ?: $m->aconcepto, Formato::num($m->debe), Formato::num($m->haber), Formato::num($m->saldo)])->all());
        }

        return view('contabilidad.mayor', $b + ['cta' => $cta, 'nombreCta' => $cta !== '' ? (Contabilidad::nombre($c9, $propias) ?? '') : '', 'movs' => $movs, 'propias' => $propias, 'catalogo' => $this->catalogo($b['c'])]);
    }

    // --------------------------------------------------------- sumas y saldos
    public function sumas(Request $request)
    {
        $b = $this->base($request, 'sumas');
        if (! is_array($b)) {
            return $b;
        }
        $nivel = in_array($request->query('nivel'), ['3', '9'], true) ? (int) $request->query('nivel') : 9;
        $hasta = $request->query('hasta');
        $s = $this->conta->sumas($b['ej'], $hasta);
        $propias = Contabilidad::propias($b['c']);
        $filas = $s->groupBy(fn ($v, $k) => substr((string) $k, 0, $nivel), true)->map(function ($g, $k) use ($nivel, $propias) {
            $d = $g->sum('d');
            $h = $g->sum('h');

            return ['cuenta' => (string) $k, 'nombre' => $nivel === 3 ? (config('pgc')[$k] ?? '') : (Contabilidad::nombre((string) $k, $propias) ?? ''), 'd' => $d, 'h' => $h, 'sd' => max(0, $d - $h), 'sa' => max(0, $h - $d)];
        })->sortKeys()->values();
        if ($request->query('exportar') === 'csv') {
            return $this->csv('sumas-y-saldos-'.$b['c']->nif.'-'.$b['ej']->anio.'.csv', ['Cuenta', 'Nombre', 'Sumas debe', 'Sumas haber', 'Saldo deudor', 'Saldo acreedor'],
                $filas->map(fn ($f) => [$f['cuenta'], $f['nombre'], Formato::num($f['d']), Formato::num($f['h']), Formato::num($f['sd']), Formato::num($f['sa'])])->all());
        }

        return view('contabilidad.sumas', $b + ['filas' => $filas, 'nivel' => $nivel, 'hasta' => $hasta]);
    }

    // ------------------------------------------------- pérdidas y ganancias y balance
    public function cuentasAnuales(Request $request)
    {
        $b = $this->base($request, 'anuales');
        if (! is_array($b)) {
            return $b;
        }
        $s = $this->conta->sumas($b['ej'], null, ['Regularización', 'Cierre']);
        $saldo = fn (array $pre, bool $acreedor = false) => round($s->filter(fn ($v, $k) => collect($pre)->contains(fn ($p) => str_starts_with((string) $k, $p)))->sum(fn ($v) => $acreedor ? $v['h'] - $v['d'] : $v['d'] - $v['h']), 2);
        $p = [
            ['1. Importe neto de la cifra de negocios', $saldo(['70'], true), false],
            ['2. Variación de existencias de productos terminados y en curso', $saldo(['71'], true), false],
            ['3. Trabajos realizados por la empresa para su activo', $saldo(['73'], true), false],
            ['4. Aprovisionamientos', -$saldo(['60', '61']), false],
            ['5. Otros ingresos de explotación', $saldo(['74', '75'], true), false],
            ['6. Gastos de personal', -$saldo(['64']), false],
            ['7. Otros gastos de explotación', -$saldo(['62', '631', '634', '636', '639', '65', '694', '695', '794', '795']), false],
            ['8. Amortización del inmovilizado', -$saldo(['68']), false],
            ['9-11. Subvenciones de capital, deterioro y resultados por enajenaciones del inmovilizado', $saldo(['746', '77', '790', '791', '793'], true) - $saldo(['67', '690', '691', '693']), false],
        ];
        $explot = array_sum(array_column($p, 1));
        $p[] = ['A.1) RESULTADO DE EXPLOTACIÓN', $explot, true];
        $fin = $saldo(['76'], true) - $saldo(['66']);
        $p[] = ['12. Ingresos financieros', $saldo(['76'], true), false];
        $p[] = ['13. Gastos financieros', -$saldo(['66']), false];
        $p[] = ['A.2) RESULTADO FINANCIERO', $fin, true];
        $p[] = ['A.3) RESULTADO ANTES DE IMPUESTOS', $explot + $fin, true];
        $imp = $saldo(['630', '633', '638']);
        $p[] = ['17. Impuestos sobre beneficios', -$imp, false];
        $p[] = ['A.4) RESULTADO DEL EJERCICIO', $explot + $fin - $imp, true];
        $resultado = $this->conta->resultado($b['ej'])['resultado'];

        // Balance (saldo de cada cuenta, en el lado que corresponde por su signo)
        $sb = $this->conta->sumas($b['ej'], null, ['Cierre']);
        $porCuenta = fn (string $pre) => $sb->filter(fn ($v, $k) => str_starts_with((string) $k, $pre))->map(fn ($v) => round($v['d'] - $v['h'], 2));
        $deud = fn (array $pres) => round(collect($pres)->sum(fn ($pr) => $porCuenta($pr)->filter(fn ($x) => $x > 0)->sum()), 2);
        $acre = fn (array $pres) => round(collect($pres)->sum(fn ($pr) => -$porCuenta($pr)->filter(fn ($x) => $x < 0)->sum()), 2);
        $inmovInt = $saldo(['20']) - $acre(['280', '290']);
        $inmovMat = $saldo(['21', '23']) - $acre(['281', '291']);
        $invInm = $saldo(['22']) - $acre(['282']);
        $invFinLp = $saldo(['24', '25', '26']);
        $existencias = $saldo(['3']);
        $deudores = $deud(['43', '44', '46', '470', '471', '472', '473', '474', '48', '55']);
        $invFinCp = $saldo(['53', '54']);
        $tes = $saldo(['57']);
        $A = [['A) ACTIVO NO CORRIENTE', $inmovInt + $inmovMat + $invInm + $invFinLp, true], ['I. Inmovilizado intangible', $inmovInt, false], ['II. Inmovilizado material', $inmovMat, false], ['III. Inversiones inmobiliarias', $invInm, false], ['V. Inversiones financieras a largo plazo', $invFinLp, false],
            ['B) ACTIVO CORRIENTE', $existencias + $deudores + $invFinCp + $tes, true], ['II. Existencias', $existencias, false], ['III. Deudores comerciales y otras cuentas a cobrar', $deudores, false], ['V. Inversiones financieras a corto plazo', $invFinCp, false], ['VII. Efectivo y otros activos líquidos', $tes, false]];
        $capital = $saldo(['10'], true);
        $reservas = $saldo(['11', '12'], true) - ($b['ej']->cerrado() ? $saldo(['129'], true) : 0);
        $resEj = $b['ej']->cerrado() ? $saldo(['129'], true) : $resultado + $saldo(['129'], true);
        $subv = $saldo(['13'], true);
        $deudaLp = $saldo(['14', '15', '16', '17', '18'], true);
        $acreedores = $acre(['40', '41', '43', '44', '46', '475', '476', '477', '479', '485', '55']);
        $deudaCp = $saldo(['50', '51', '52', '56'], true);
        $P = [['A) PATRIMONIO NETO', $capital + $reservas + $resEj + $subv, true], ['I. Capital', $capital, false], ['III. Reservas y resultados de ejercicios anteriores', $reservas, false], ['VII. Resultado del ejercicio', $resEj, false], ['A-3) Subvenciones, donaciones y legados recibidos', $subv, false],
            ['B) PASIVO NO CORRIENTE', $deudaLp, true], ['II. Deudas a largo plazo', $deudaLp, false],
            ['C) PASIVO CORRIENTE', $deudaCp + $acreedores, true], ['II. Deudas a corto plazo', $deudaCp, false], ['IV. Acreedores comerciales y otras cuentas a pagar', $acreedores, false]];
        $totA = $A[0][1] + $A[5][1];
        $totP = $P[0][1] + $P[5][1] + $P[7][1];

        return view('contabilidad.anuales', $b + ['pyg' => $p, 'balActivo' => $A, 'balPasivo' => $P, 'totA' => $totA, 'totP' => $totP]);
    }

    // ------------------------------------------------------------ ejercicios
    public function ejercicios(Request $request)
    {
        $b = $this->base($request, 'ejercicios');
        if (! is_array($b)) {
            return $b;
        }
        $res = $b['ejercicios']->mapWithKeys(fn ($e) => [$e->id => ['n' => Asiento::where('ejercicio_id', $e->id)->count()] + $this->conta->resultado($e)]);

        return view('contabilidad.ejercicios', $b + ['res' => $res, 'pendientesEj' => $b['ejercicios']->mapWithKeys(fn ($e) => [$e->id => $this->pendientes($b['c'], $e)->count()])]);
    }

    public function abrirEjercicio(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $d = $request->validate(['anio' => ['required', 'integer', 'min:2000', 'max:2100']]);
        $e = $this->conta->ejercicio($c, (int) $d['anio']);

        return redirect()->route('contabilidad.ejercicios', ['ej' => $e->anio])->with('ok', 'Ejercicio '.$e->anio.' abierto.');
    }

    public function cerrarEjercicio(Request $request, Ejercicio $ejercicio)
    {
        $c = $this->ctx($request);
        abort_unless($c && $ejercicio->cliente_id === $c->id, 404);
        abort_unless($request->user()->esAdmin(), 403, 'Solo la administración cierra ejercicios.');
        if ($this->pendientes($c, $ejercicio)->count()) {
            return back()->with('aviso', 'Hay facturas de '.$ejercicio->anio.' sin contabilizar en la bandeja. Contabilízalas antes de cerrar.');
        }
        try {
            $this->conta->cerrar($ejercicio, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('contabilidad.cierre', 'Ejercicio '.$ejercicio->anio.' cerrado (regularización, cierre y apertura de '.($ejercicio->anio + 1).')', null, $c->id);

        return redirect()->route('contabilidad.ejercicios', ['ej' => $ejercicio->anio])->with('ok', 'Ejercicio '.$ejercicio->anio.' cerrado. Se han generado la regularización, el cierre y la apertura de '.($ejercicio->anio + 1).'.');
    }

    public function reabrirEjercicio(Request $request, Ejercicio $ejercicio)
    {
        $c = $this->ctx($request);
        abort_unless($c && $ejercicio->cliente_id === $c->id, 404);
        abort_unless($request->user()->esAdmin(), 403);
        $this->conta->reabrir($ejercicio);
        Auditoria::registrar('contabilidad.reapertura', 'Ejercicio '.$ejercicio->anio.' reabierto', null, $c->id);

        return back()->with('ok', 'Ejercicio '.$ejercicio->anio.' reabierto: se han quitado la regularización, el cierre y la apertura del siguiente.');
    }

    public function bloqueo(Request $request, Ejercicio $ejercicio)
    {
        $c = $this->ctx($request);
        abort_unless($c && $ejercicio->cliente_id === $c->id, 404);
        $d = $request->validate(['bloqueo' => ['nullable', 'date'], 'observaciones' => ['nullable', 'string', 'max:2000']]);
        if (! empty($d['bloqueo']) && (int) substr($d['bloqueo'], 0, 4) !== $ejercicio->anio) {
            return back()->with('aviso', 'La fecha de bloqueo debe ser del ejercicio '.$ejercicio->anio.'.');
        }
        $ejercicio->update(['bloqueo' => $d['bloqueo'] ?? null, 'observaciones' => $d['observaciones'] ?? null]);

        return back()->with('ok', 'Ejercicio '.$ejercicio->anio.' actualizado.');
    }

    // ------------------------------------------------------------ subcuentas
    public function guardarCuenta(Request $request)
    {
        $c = $this->ctx($request);
        abort_unless($c, 403);
        $d = $request->validate(['codigo' => ['required', 'regex:/^\d{9}$/'], 'nombre' => ['required', 'string', 'max:255']], ['codigo.regex' => 'La subcuenta tiene 9 cifras.']);
        if (! Contabilidad::existeEnPgc($d['codigo'])) {
            return back()->with('aviso', 'La subcuenta debe empezar por una cuenta del plan (por ejemplo 572 para un banco).');
        }
        Cuenta::updateOrCreate(['cliente_id' => $c->id, 'codigo' => $d['codigo']], ['nombre' => trim($d['nombre'])]);

        return back()->with('ok', 'Subcuenta '.$d['codigo'].' guardada.');
    }

    protected function csv(string $nombre, array $cab, array $filas)
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
