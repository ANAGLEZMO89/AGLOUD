<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Factura;
use App\Models\FacturacionConfig;
use App\Models\Presupuesto;
use App\Models\RegistroFacturacion;
use App\Models\Tercero;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Services\Facturacion;
use App\Services\Facturae;
use App\Support\Formato;
use App\Support\Plazos;
use App\Support\Validacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facturación de la empresa elegida en «¿Con quién vas a trabajar?».
 * Modo No VERI*FACTU: registros encadenados y conservados, sin envío a la AEAT.
 */
class FacturaController extends Controller
{
    private const CHIPS = ['Todas', 'Pendientes de cobro', 'Cobradas', 'Rectificativas', 'Anuladas'];

    public const IVAS = ['21', '10', '5', '4', '0'];

    public const RETS = ['0', '1', '7', '15', '19'];

    public const DIAS = ['0' => 'Al contado', '15' => '15 días', '30' => '30 días', '45' => '45 días', '60' => '60 días', '90' => '90 días'];

    public function __construct(private Facturacion $fac) {}

    private function emisor(Request $request): ?Cliente
    {
        return $request->attributes->get('ctx');
    }

    private function sinEmisor()
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/facturacion'])->with('aviso', 'Elige primero la empresa que factura.');
    }

    private function propia(Request $request, Factura $f): Cliente
    {
        $c = $this->emisor($request);
        abort_unless($c && $f->cliente_id === $c->id, 404);

        return $c;
    }

    private function base(Cliente $c, string $tab): array
    {
        return ['titulo' => 'Facturación', 'activo' => 'facturacion', 'c' => $c, 'tab' => $tab, 'modoCfg' => FacturacionConfig::de($c)->modo ?: 'normal',
            'obligTxt' => $c->tipo === 'Sociedad' || $c->es_despacho ? 'Obligatorio desde el 1 de enero de 2027 (contribuyentes del Impuesto sobre Sociedades).' : 'Obligatorio desde el 1 de julio de 2027 (autónomos y resto de obligados).'];
    }

    // ------------------------------------------------------------------ lista
    public function index(Request $request)
    {
        $c = $this->emisor($request);
        if (! $c) {
            return $this->sinEmisor();
        }
        $chip = max(0, min(4, (int) $request->query('chip', 0)));
        $q = trim((string) $request->query('q', ''));
        $todas = Factura::where('cliente_id', $c->id)->orderByDesc('fecha')->orderByDesc('id')->get();
        $filtros = [
            fn ($f) => true,
            fn ($f) => $f->estado === 'Emitida' && ! $f->cobrada,
            fn ($f) => $f->cobrada && $f->estado === 'Emitida',
            fn ($f) => $f->tipo === 'R1',
            fn ($f) => $f->estado === 'Anulada',
        ];
        $ql = mb_strtolower($q);
        $lista = $todas->filter($filtros[$chip])->filter(fn ($f) => $ql === '' || str_contains(mb_strtolower($f->num.' '.$f->receptor_nombre.' '.$f->receptor_nif), $ql))->values();
        $vivas = $todas->where('estado', 'Emitida');
        $pend = $vivas->where('cobrada', false);
        $kpis = [
            ['Facturado (base)', Formato::eur($vivas->sum('base')), Formato::entero($vivas->count()).' facturas emitidas'],
            ['IVA repercutido', Formato::eur($vivas->sum('cuota')), 'Pasa al modelo 303'],
            ['Pendiente de cobro', Formato::eur($pend->sum('total')), Formato::entero($pend->count()).' facturas'],
            ['Registros de facturación', Formato::entero(RegistroFacturacion::where('cliente_id', $c->id)->count()), 'Encadenados y conservados'],
        ];
        if ($request->query('exportar') === 'csv') {
            return $this->csv('facturas-'.$c->nif.'.csv', ['Número', 'Fecha', 'Cliente', 'NIF', 'Tipo', 'Base', 'IVA', 'Retención', 'Suplidos', 'Total', 'Estado'],
                $lista->map(fn ($f) => [$f->num, Formato::fecha($f->fecha), $f->receptor_nombre, $f->receptor_nif, $f->tipo, Formato::num($f->base), Formato::num($f->cuota), Formato::num($f->retencion), Formato::num($f->suplidos), Formato::num($f->total), $f->estadoChip()[0]])->all());
        }

        return view('facturacion.index', $this->base($c, 'lista') + [
            'lista' => $lista, 'kpis' => $kpis, 'chip' => $chip, 'q' => $q,
            'chips' => collect(self::CHIPS)->map(fn ($l, $i) => ['l' => $l, 'i' => $i, 'on' => $i === $chip]),
        ]);
    }

    // ---------------------------------------------------------------- detalle
    public function show(Request $request, Factura $factura)
    {
        $c = $this->propia($request, $factura);

        return view('facturacion.show', $this->base($c, 'lista') + ['f' => $factura, 'regs' => $factura->registros, 'qrUrl' => Facturacion::urlQr($c->nif ?? '', $factura)]);
    }

    /** Factura en HTML imprimible (PDF desde el navegador) */
    public function documento(Request $request, Factura $factura)
    {
        $c = $this->propia($request, $factura);
        $nif = $factura->registros->first()?->nif_emisor ?? $c->nif;
        if ($request->boolean('imprimir')) {
            Auditoria::registrar('factura.descarga', 'Descarga de la factura '.$factura->num, $factura);
        }

        return view('facturacion.documento', ['c' => $c, 'f' => $factura, 'cfg' => FacturacionConfig::de($c), 'qr' => Facturacion::qrSvg(Facturacion::urlQr($nif, $factura)), 'draft' => false, 'imprimir' => $request->boolean('imprimir')]);
    }

    /** Factura electrónica Facturae 3.2.2 (XML sin firma) */
    public function facturae(Request $request, Factura $factura)
    {
        $c = $this->propia($request, $factura);
        try {
            $xml = Facturae::xml($c, $factura);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('factura.facturae', 'Descarga de la factura electrónica '.$factura->num, $factura);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$c->nif.'_'.$factura->num.'.xml"']);
    }

    public function cobro(Request $request, Factura $factura)
    {
        $this->propia($request, $factura);
        abort_if($factura->estado === 'Anulada', 422);
        if ($factura->cobro_asiento_id) {
            return back()->with('aviso', 'El cobro de '.$factura->num.' ya está contabilizado. Para deshacerlo, borra antes su asiento en Contabilidad › Libro diario.');
        }
        $factura->cobrada = ! $factura->cobrada;
        $factura->cobrada_at = $factura->cobrada ? now()->toDateString() : null;
        $factura->save();
        Auditoria::registrar('factura.cobro', $factura->num.($factura->cobrada ? ' cobrada' : ': cobro anulado'), $factura);

        return back()->with('ok', 'Factura '.$factura->num.($factura->cobrada ? ' marcada como cobrada.' : ': cobro anulado.'));
    }

    public function anular(Request $request, Factura $factura)
    {
        $this->propia($request, $factura);
        try {
            $this->fac->anular($factura);
        } catch (InvalidArgumentException $e) {
            return back()->with('aviso', $e->getMessage());
        }
        Auditoria::registrar('factura.anulacion', 'Registro de anulación de '.$factura->num, $factura);
        $contra = null;
        try {
            $contra = app(Contabilidad::class)->anularEmitida($factura->fresh(), $request->user()->id);
        } catch (InvalidArgumentException) {
            // ejercicio bloqueado o cerrado: se ajustará a mano en Contabilidad
        }
        if ($contra) {
            return back()->with('ok', 'Registro de anulación de '.$factura->num.' generado y encadenado. En Contabilidad se ha registrado el contraasiento nº '.$contra->numero.'.');
        }

        return back()->with('ok', 'Registro de anulación de '.$factura->num.' generado y encadenado. La factura se conserva marcada como anulada.');
    }

    // ------------------------------------------------------------------ nueva
    public function create(Request $request)
    {
        $c = $this->emisor($request);
        // La cuota del cliente la factura siempre el despacho: si se está trabajando con otra empresa, se cambia
        if ($request->query('cuota') && (! $c || ! $c->es_despacho)) {
            $desp = Cliente::despacho();
            if (! $desp) {
                return redirect()->route('escritorio')->with('aviso', 'Da de alta primero los datos del despacho para poder facturar las cuotas.');
            }
            $request->session()->put('cliente_activo', $desp->id);

            return redirect()->to($request->fullUrl())->with('aviso', 'Facturas como '.$desp->nombre.' (el despacho).');
        }
        if (! $c) {
            return $this->sinEmisor();
        }
        $cfg = FacturacionConfig::de($c);
        $hoy = Plazos::hoy()->toDateString();
        $dr = ['serie' => $cfg->series[0]['cod'] ?? 'A', 'fecha' => $hoy, 'receptor_nombre' => '', 'receptor_nif' => '', 'receptor_dir' => '', 'ret_pct' => '0', 'observaciones' => '',
            'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'iban' => '', 'iban_emisor' => '', 'rectifica_id' => '', 'presupuesto_id' => '',
            'fe_cp' => '', 'fe_municipio' => '', 'fe_provincia' => '', 'dir3_oc' => '', 'dir3_og' => '', 'dir3_ut' => '',
            'lineas' => [['d' => '', 'q' => '1', 'p' => '', 'iva' => '21', 'sup' => false]]];
        $rect = null;
        if ($request->query('rectifica')) {
            $rect = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->findOrFail((int) $request->query('rectifica'));
            $serR = collect($cfg->series)->firstWhere('tf', 'R1');
            if (! $serR) {
                return redirect()->route('facturacion.config')->with('aviso', 'Crea primero una serie de facturas rectificativas (tipo R1).');
            }
            $dr = array_merge($dr, ['serie' => $serR['cod'], 'receptor_nombre' => $rect->receptor_nombre, 'receptor_nif' => $rect->receptor_nif, 'receptor_dir' => $rect->receptor_dir,
                'ret_pct' => (string) (0 + $rect->ret_pct), 'forma_pago' => $rect->forma_pago,
                'fe_cp' => $rect->datos_fe['cp'] ?? '', 'fe_municipio' => $rect->datos_fe['municipio'] ?? '', 'fe_provincia' => $rect->datos_fe['provincia'] ?? '',
                'dir3_oc' => $rect->datos_fe['dir3_oc'] ?? '', 'dir3_og' => $rect->datos_fe['dir3_og'] ?? '', 'dir3_ut' => $rect->datos_fe['dir3_ut'] ?? '', 'pago_dias' => (string) $rect->pago_dias, 'rectifica_id' => $rect->id,
                'lineas' => array_map(fn ($l) => ['d' => 'Rectificación · '.$l['d'], 'q' => Formato::num(-(float) $l['q'], 2), 'p' => Formato::num($l['p']), 'iva' => (string) (0 + $l['iva']), 'sup' => ! empty($l['sup'])], $rect->lineas)]);
        }
        if ($request->query('presupuesto')) {
            $p = Presupuesto::where('cliente_id', $c->id)->findOrFail((int) $request->query('presupuesto'));
            $dr = array_merge($dr, ['receptor_nombre' => $p->receptor_nombre, 'receptor_nif' => $p->receptor_nif, 'observaciones' => 'Según presupuesto '.$p->num, 'presupuesto_id' => $p->id,
                'lineas' => array_map(fn ($l) => ['d' => $l['d'], 'q' => Formato::num($l['q']), 'p' => Formato::num($l['p']), 'iva' => (string) (0 + $l['iva']), 'sup' => false], $p->lineas)]);
            $cli = collect($this->clientesReceptores($c))->firstWhere('nombre', $p->receptor_nombre);
            if ($cli) {
                $dr['receptor_dir'] = $cli['dir'];
                $dr['fe_cp'] = $cli['cp'];
                $dr['fe_municipio'] = $cli['municipio'];
                $dr['fe_provincia'] = $cli['provincia'];
                $dr['receptor_nif'] = $dr['receptor_nif'] ?: $cli['nif'];
            }
        }
        if ($request->query('cuota') && $c->es_despacho) {
            $cli = Cliente::where('es_despacho', false)->findOrFail((int) $request->query('cuota'));
            $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('fecha')) && $request->query('fecha') <= $hoy ? (string) $request->query('fecha') : $hoy;
            $per = Cliente::periodoCuota((string) $cli->cuota_periodicidad, CarbonImmutable::parse($fecha));
            $obs = trim(($per ? 'Periodo facturado: '.$per.'.' : '').($cli->cuota_incluye ? ' Incluye: '.preg_replace('/\s*\R\s*/', ', ', trim($cli->cuota_incluye)) : ''));
            [$ibanCli, $ibanDesp] = self::cuentasCuota($cli, $c);
            $dr = array_merge($dr, ['fecha' => $fecha, 'receptor_nombre' => $cli->nombre, 'receptor_nif' => (string) $cli->nif,
                'receptor_dir' => trim(implode(', ', array_filter([$cli->domicilio, trim($cli->cp.' '.$cli->municipio), $cli->provincia])), ', '),
                'fe_cp' => (string) $cli->cp, 'fe_municipio' => (string) $cli->municipio, 'fe_provincia' => (string) $cli->provincia,
                'observaciones' => mb_substr($obs, 0, 500),
                'forma_pago' => in_array($cli->cuota_forma_cobro, Factura::FORMAS_PAGO, true) ? $cli->cuota_forma_cobro : 'Transferencia',
                'pago_dias' => $cli->cuota_forma_cobro === 'Domiciliación SEPA' ? '0' : $dr['pago_dias'],
                'iban' => $cli->cuota_forma_cobro === 'Domiciliación SEPA' ? $ibanCli : '',
                'iban_emisor' => $cli->cuota_forma_cobro === 'Transferencia' ? $ibanDesp : '',
                'lineas' => array_map(fn ($l) => ['d' => $l['d'], 'q' => Formato::num($l['q']), 'p' => Formato::num($l['p']), 'iva' => (string) (0 + $l['iva']), 'sup' => false], $cli->lineasCuota()) ?: $dr['lineas']]);
            if ($request->query('serie') && collect($cfg->series)->contains('cod', $request->query('serie'))) {
                $dr['serie'] = (string) $request->query('serie');
            }
        }
        $old = $request->old();
        if ($old) {
            $dr = array_merge($dr, array_intersect_key($old, $dr));
        }
        $emIbans = collect($c->seccion('bancos'))->pluck('iban')->map(fn ($x) => strtoupper(preg_replace('/\s/', '', (string) $x)))->filter()->unique()->values()->all();
        $dr['iban_emisor'] = $dr['iban_emisor'] ?: ($emIbans[0] ?? '');
        $proximos = collect($cfg->series)->mapWithKeys(fn ($s) => [$s['cod'] => Facturacion::numTexto($s['cod'], (int) substr($dr['fecha'], 0, 4), Facturacion::siguienteNumero($c, $s['cod'], (int) substr($dr['fecha'], 0, 4)))]);

        return view('facturacion.nueva', $this->base($c, 'nueva') + [
            'cfg' => $cfg, 'dr' => $dr, 'rect' => $rect, 'proximos' => $proximos, 'emIbans' => $emIbans,
            'receptores' => $this->clientesReceptores($c), 'hoy' => $hoy,
        ]);
    }

    /**
     * Cuentas de la cuota de un cliente del despacho: [IBAN del cliente para el cargo SEPA, IBAN del emisor para la transferencia].
     * La del cliente sale de su ficha; la de ingreso, de las cuentas del despacho (la elegida en la cuota, o la principal).
     */
    public static function cuentasCuota(Cliente $cli, Cliente $emisor): array
    {
        $bc = $cli->seccion('bancos');
        $cliB = $cli->cuota_cuenta !== null && $cli->cuota_cuenta !== '' && isset($bc[(int) $cli->cuota_cuenta]) ? $bc[(int) $cli->cuota_cuenta] : (collect($bc)->first(fn ($b) => ! empty($b['principal'])) ?? ($bc[0] ?? []));
        $be = $emisor->seccion('bancos');
        $empB = $cli->cuota_cuenta_despacho !== null && $cli->cuota_cuenta_despacho !== '' && isset($be[(int) $cli->cuota_cuenta_despacho]) ? $be[(int) $cli->cuota_cuenta_despacho] : (collect($be)->first(fn ($b) => ! empty($b['principal'])) ?? ($be[0] ?? []));
        $norm = fn ($b) => strtoupper(preg_replace('/\s/', '', (string) ($b['iban'] ?? '')));

        return [$norm($cliB), $norm($empB)];
    }

    /** Clientes a los que factura esta empresa: los del despacho (si factura el despacho) y los ya usados */
    private function clientesReceptores(Cliente $c): array
    {
        $out = [];
        if ($c->es_despacho) {
            foreach (Cliente::where('es_despacho', false)->orderBy('nombre')->get() as $x) {
                $out[mb_strtolower($x->nombre)] = ['nombre' => $x->nombre, 'nif' => (string) $x->nif, 'dir' => (string) $x->domicilio, 'cp' => (string) $x->cp, 'municipio' => (string) $x->municipio, 'provincia' => (string) $x->provincia,
                    'ibans' => collect($x->seccion('bancos'))->pluck('iban')->filter()->values()->all(),
                    'fp' => (string) $x->cuota_forma_cobro, 'ibanSepa' => self::cuentasCuota($x, $c)[0], 'ibanDesp' => self::cuentasCuota($x, $c)[1],
                    'cuota' => $x->lineasCuota() ? route('facturacion.create', ['cuota' => $x->id]) : null];
            }
        }
        // Clientes dados de alta en Ventas por la empresa elegida
        foreach (Tercero::where('cliente_id', $c->id)->where('clase', 'cliente')->orderBy('nombre')->get() as $t) {
            $out[mb_strtolower($t->nombre)] ??= ['nombre' => $t->nombre, 'nif' => (string) $t->nif, 'dir' => (string) $t->domicilio, 'cp' => (string) $t->cp, 'municipio' => (string) $t->municipio, 'provincia' => (string) $t->provincia,
                'ibans' => array_values(array_filter([$t->iban]))];
        }
        foreach (Factura::where('cliente_id', $c->id)->whereNotNull('receptor_nombre')->orderByDesc('id')->limit(500)->get() as $f) {
            $out[mb_strtolower($f->receptor_nombre)] ??= ['nombre' => $f->receptor_nombre, 'nif' => (string) $f->receptor_nif, 'dir' => (string) $f->receptor_dir, 'cp' => (string) ($f->datos_fe['cp'] ?? ''), 'municipio' => (string) ($f->datos_fe['municipio'] ?? ''), 'provincia' => (string) ($f->datos_fe['provincia'] ?? ''), 'ibans' => array_values(array_filter([$f->iban]))];
        }
        foreach (Presupuesto::where('cliente_id', $c->id)->orderByDesc('id')->limit(300)->get() as $p) {
            $out[mb_strtolower($p->receptor_nombre)] ??= ['nombre' => $p->receptor_nombre, 'nif' => (string) $p->receptor_nif, 'dir' => '', 'cp' => '', 'municipio' => '', 'provincia' => '', 'ibans' => []];
        }

        return array_values($out);
    }

    /** Lee y valida el formulario. Devuelve los datos listos para emitir. */
    private function datos(Request $request, Cliente $c, FacturacionConfig $cfg): array
    {
        $request->validate([
            'serie' => ['required', Rule::in(collect($cfg->series)->pluck('cod')->all())],
            'fecha' => ['required', 'date', 'before_or_equal:today'],
            'receptor_nombre' => ['nullable', 'string', 'max:255'],
            'receptor_nif' => ['nullable', 'string', 'max:20'],
            'receptor_dir' => ['nullable', 'string', 'max:255'],
            'ret_pct' => ['required', Rule::in(self::RETS)],
            'forma_pago' => ['required', Rule::in(Factura::FORMAS_PAGO)],
            'pago_dias' => ['nullable', Rule::in(array_keys(self::DIAS))],
            'iban' => ['nullable', 'string', 'max:40'],
            'iban_emisor' => ['nullable', 'string', 'max:40'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'lineas' => ['required', 'array', 'max:100'],
            'lineas.*.d' => ['nullable', 'string', 'max:255'],
            'lineas.*.q' => ['nullable', 'string', 'max:20'],
            'lineas.*.p' => ['nullable', 'string', 'max:20'],
            'lineas.*.iva' => ['nullable', Rule::in(self::IVAS)],
            'rectifica_id' => ['nullable', 'integer'],
            'presupuesto_id' => ['nullable', 'integer'],
            'fe_cp' => ['nullable', 'string', 'max:10'],
            'fe_municipio' => ['nullable', 'string', 'max:50'],
            'fe_provincia' => ['nullable', 'string', 'max:20'],
            'dir3_oc' => ['nullable', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'dir3_og' => ['nullable', 'regex:/^[A-Za-z0-9]{1,10}$/'],
            'dir3_ut' => ['nullable', 'regex:/^[A-Za-z0-9]{1,10}$/'],
        ], ['dir3_oc.regex' => 'Los códigos DIR3 tienen hasta 10 letras y números.', 'dir3_og.regex' => 'Los códigos DIR3 tienen hasta 10 letras y números.', 'dir3_ut.regex' => 'Los códigos DIR3 tienen hasta 10 letras y números.', 'fecha.before_or_equal' => 'No se pueden emitir facturas con fecha futura.']);

        $ser = $cfg->serie($request->input('serie'));
        $tf = $ser['tf'];
        $L = collect($request->input('lineas', []))
            ->map(fn ($l) => ['d' => trim((string) ($l['d'] ?? '')), 'q' => Formato::parseNum($l['q'] ?? '1'), 'p' => Formato::parseNum($l['p'] ?? ''), 'iva' => ! empty($l['sup']) ? 0 : (float) ($l['iva'] ?? 21), 'sup' => ! empty($l['sup'])])
            ->filter(fn ($l) => $l['d'] !== '' || $l['p'] != 0)->values()->all();
        $nif = strtoupper(preg_replace('/[\s.-]/', '', (string) $request->input('receptor_nif')));
        $iban = strtoupper(preg_replace('/\s/', '', (string) $request->input('iban')));
        $ibanEm = strtoupper(preg_replace('/\s/', '', (string) $request->input('iban_emisor')));
        $fp = $request->input('forma_pago');
        $dias = in_array($fp, ['Tarjeta', 'Bizum', 'Efectivo'], true) ? 0 : (int) $request->input('pago_dias', 0);
        $rect = null;
        if ($request->filled('rectifica_id')) {
            $rect = Factura::where('cliente_id', $c->id)->where('estado', 'Emitida')->find((int) $request->input('rectifica_id'));
        }
        $err = match (true) {
            $tf !== 'F2' && trim((string) $request->input('receptor_nombre')) === '' => 'Indica el cliente.',
            $tf !== 'F2' && ! preg_match('/^[A-Z0-9]{8,15}$/', $nif) => 'El NIF del cliente no es válido (8 a 15 letras y números, sin espacios).',
            ! count($L) => 'Añade al menos una línea.',
            collect($L)->contains(fn ($l) => $l['d'] === '') => 'Todas las líneas necesitan descripción.',
            collect($L)->contains(fn ($l) => ! $l['q'] || ! $l['p']) => 'Revisa cantidades y precios.',
            collect($L)->every(fn ($l) => $l['sup']) => 'Una factura no puede contener solo suplidos.',
            $tf === 'R1' && ! $rect => 'Las rectificativas se crean desde la factura original (botón «Rectificar»).',
            $tf !== 'R1' && $rect !== null => 'Para rectificar elige una serie de rectificativas.',
            $fp === 'Domiciliación SEPA' && ! Validacion::iban($iban) => 'Para domiciliar hace falta un IBAN válido del cliente.',
            $fp === 'Domiciliación SEPA' && $iban !== '' && ! Validacion::iban($iban) => 'El IBAN del cliente no es válido.',
            $fp === 'Transferencia' && count($c->seccion('bancos')) > 0 && ! Validacion::iban($ibanEm) => 'Para cobrar por transferencia indica la cuenta de la empresa en la que el cliente debe ingresar (añádela en Datos bancarios de la empresa).',
            $ibanEm !== '' && ! Validacion::iban($ibanEm) => 'El IBAN de la empresa no es válido.',
            default => null,
        };
        $electronica = $cfg->modo === 'electronica';
        $feCp = trim((string) $request->input('fe_cp'));
        $dir3 = array_filter([trim((string) $request->input('dir3_oc')), trim((string) $request->input('dir3_og')), trim((string) $request->input('dir3_ut'))]);
        if (! $err && $electronica && ($tf !== 'F2' || $nif !== '')) {
            $err = match (true) {
                ! preg_match('/^\d{5}$/', $feCp) || trim((string) $request->input('fe_municipio')) === '' || trim((string) $request->input('receptor_dir')) === '' => 'Factura electrónica: indica la dirección, el código postal (5 cifras) y el municipio del cliente.',
                count($dir3) > 0 && count($dir3) < 3 => 'Para facturar a una Administración Pública hacen falta los tres códigos DIR3 (oficina contable, órgano gestor y unidad tramitadora).',
                ! $c->domicilio || ! preg_match('/^\d{5}$/', (string) $c->cp) || ! $c->municipio => 'Factura electrónica: completa el domicilio, el código postal y el municipio de la empresa en su ficha.',
                default => null,
            };
        }
        if (! $err && $tf === 'F2' && Facturacion::totales($L, (float) $request->input('ret_pct'))['total'] > 3000) {
            $err = 'Una factura simplificada no puede superar 3.000 € (art. 4 RD 1619/2012). Usa la serie ordinaria.';
        }
        if ($err) {
            throw ValidationException::withMessages(['form' => $err]);
        }

        return [
            'serie' => $ser['cod'], 'tf' => $tf, 'fecha' => $request->input('fecha'),
            'receptor_nombre' => trim((string) $request->input('receptor_nombre')) ?: null, 'receptor_nif' => $nif ?: null, 'receptor_dir' => trim((string) $request->input('receptor_dir')) ?: null,
            'lineas' => $L, 'ret_pct' => (float) $request->input('ret_pct'), 'forma_pago' => $fp, 'pago_dias' => $dias,
            'iban' => $fp === 'Domiciliación SEPA' ? ($iban ?: null) : null, 'iban_emisor' => $fp === 'Transferencia' ? ($ibanEm ?: null) : null, 'observaciones' => trim((string) $request->input('observaciones')) ?: null,
            'modo' => $cfg->modoEfectivo(),
            'datos_fe' => $electronica ? ['cp' => $feCp, 'municipio' => trim((string) $request->input('fe_municipio')), 'provincia' => trim((string) $request->input('fe_provincia')) ?: Facturae::provinciaDeCp($feCp),
                'dir3_oc' => strtoupper(trim((string) $request->input('dir3_oc'))), 'dir3_og' => strtoupper(trim((string) $request->input('dir3_og'))), 'dir3_ut' => strtoupper(trim((string) $request->input('dir3_ut')))] : null,
            'rectifica_id' => $rect?->id, 'presupuesto_id' => $request->filled('presupuesto_id') ? Presupuesto::where('cliente_id', $c->id)->find((int) $request->input('presupuesto_id'))?->id : null,
        ];
    }

    public function store(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $cfg = FacturacionConfig::de($c);
        $d = $this->datos($request, $c, $cfg);
        try {
            $f = $this->fac->emitir($c, $d, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        if ($f->presupuesto_id) {
            Presupuesto::whereKey($f->presupuesto_id)->update(['estado' => 'Facturado', 'factura_id' => $f->id]);
        }
        Auditoria::registrar('factura.emision', 'Factura '.$f->num.' emitida · '.Formato::eur($f->total), $f);

        return redirect()->route('facturacion.show', $f)->with('ok', 'Factura '.$f->num.' emitida y registrada (registro encadenado y conservado; sin envío a la AEAT).');
    }

    /** Vista previa del borrador (se carga en un marco) */
    public function borrador(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $cfg = FacturacionConfig::de($c);
        $ser = $cfg->serie((string) $request->input('serie')) ?? ($cfg->series[0] ?? ['cod' => 'A', 'tf' => 'F1']);
        $L = collect($request->input('lineas', []))
            ->map(fn ($l) => ['d' => trim((string) ($l['d'] ?? '')), 'q' => Formato::parseNum($l['q'] ?? '1'), 'p' => Formato::parseNum($l['p'] ?? ''), 'iva' => ! empty($l['sup']) ? 0 : (float) ($l['iva'] ?? 21), 'sup' => ! empty($l['sup'])])
            ->filter(fn ($l) => $l['d'] !== '' || $l['p'] != 0)->values()->all();
        $t = Facturacion::totales($L, (float) $request->input('ret_pct', 0));
        $f = new Factura(['num' => '', 'tipo' => $ser['tf'], 'serie' => $ser['cod'], 'fecha' => $request->input('fecha') ?: now()->toDateString(), 'receptor_nombre' => $request->input('receptor_nombre'),
            'receptor_nif' => $request->input('receptor_nif'), 'receptor_dir' => $request->input('receptor_dir'), 'lineas' => $L, 'ivas' => $t['ivas'], 'ret_pct' => (float) $request->input('ret_pct', 0),
            'base' => $t['base'], 'cuota' => $t['cuota'], 'retencion' => $t['retencion'], 'suplidos' => $t['suplidos'], 'total' => $t['total'], 'forma_pago' => $request->input('forma_pago'),
            'pago_dias' => (int) $request->input('pago_dias', 0), 'iban' => $request->input('iban'), 'iban_emisor' => $request->input('forma_pago') === 'Transferencia' ? $request->input('iban_emisor') : null,
            'observaciones' => $request->input('observaciones'), 'estado' => 'Emitida']);
        if ($request->filled('rectifica_id')) {
            $f->setRelation('rectificada', Factura::where('cliente_id', $c->id)->find((int) $request->input('rectifica_id')));
        }

        return view('facturacion.documento', ['c' => $c, 'f' => $f, 'cfg' => $cfg, 'qr' => null, 'draft' => true, 'imprimir' => false]);
    }

    // --------------------------------------------------------------- registro
    public function registro(Request $request)
    {
        $c = $this->emisor($request);
        if (! $c) {
            return $this->sinEmisor();
        }
        $regs = RegistroFacturacion::where('cliente_id', $c->id)->orderByDesc('id')->limit(2000)->get();
        if ($request->query('exportar') === 'csv') {
            $n = $regs->count();

            return $this->csv('registros-facturacion-'.$c->nif.'.csv', ['Nº', 'Fecha y hora', 'Tipo', 'Factura', 'Tipo de factura', 'Cuota', 'Importe', 'Huella', 'Huella anterior', 'Estado'],
                $regs->values()->map(fn ($r, $i) => [$n - $i, $r->fecha_hora_huso, $r->tipo, $r->num, $r->tipo_factura, Formato::num($r->cuota), Formato::num($r->total), $r->huella, $r->huella_anterior ?? '', $r->estado])->all());
        }

        return view('facturacion.registro', $this->base($c, 'reg') + ['regs' => $regs, 'total' => RegistroFacturacion::where('cliente_id', $c->id)->count(), 'chk' => session('chk')]);
    }

    public function verificar(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $roto = $this->fac->verificar($c);
        Auditoria::registrar('factura.verificacion', $roto ? 'Cadena de huellas ROTA en '.$roto : 'Cadena de huellas verificada', null, $c->id);

        return redirect()->route('facturacion.registro')->with('chk', $roto ? ['ok' => false, 't' => 'La cadena se rompe en el registro de '.$roto.'. Avisa al administrador: algún dato se ha alterado fuera de AG Cloud.'] : ['ok' => true, 't' => 'Cadena íntegra: todas las huellas coinciden.']);
    }

    // ---------------------------------------------------------- configuración
    public function config(Request $request)
    {
        $c = $this->emisor($request);
        if (! $c) {
            return $this->sinEmisor();
        }
        $cfg = FacturacionConfig::de($c);
        $usos = Factura::where('cliente_id', $c->id)->selectRaw('serie, count(*) n')->groupBy('serie')->pluck('n', 'serie');

        return view('facturacion.config', $this->base($c, 'cfg') + ['cfg' => $cfg, 'est' => $cfg->estiloCompleto(), 'usos' => $usos]);
    }

    public function guardarConfig(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $cfg = FacturacionConfig::de($c);
        $request->validate([
            'modo' => ['required', Rule::in(array_keys(FacturacionConfig::MODOS))],
            'pie' => ['nullable', 'string', 'max:500'],
            'series' => ['required', 'array', 'min:1', 'max:20'],
            'series.*.cod' => ['required', 'regex:/^[A-Z0-9]{1,3}$/'],
            'series.*.nombre' => ['required', 'string', 'max:60'],
            'series.*.tf' => ['required', Rule::in(array_keys(Factura::TIPOS))],
            'nueva_serie' => ['nullable', 'regex:/^[A-Za-z0-9]{1,3}$/'],
            'cat' => ['nullable', 'array', 'max:200'],
            'cat.*.nombre' => ['nullable', 'string', 'max:120'],
            'cat.*.precio' => ['nullable', 'string', 'max:20'],
            'cat.*.iva' => ['nullable', Rule::in(self::IVAS)],
        ], ['series.*.cod.regex' => 'El código de serie debe tener de 1 a 3 letras o números.', 'nueva_serie.regex' => 'El código de serie debe tener de 1 a 3 letras o números.']);

        $antes = collect($cfg->series)->keyBy('cod');
        $usadas = Factura::where('cliente_id', $c->id)->distinct()->pluck('serie')->all();
        $series = collect($request->input('series'))->map(fn ($s) => ['cod' => $s['cod'], 'nombre' => trim($s['nombre']),
            // Una serie con facturas no cambia de tipo
            'tf' => in_array($s['cod'], $usadas, true) && $antes->has($s['cod']) ? $antes[$s['cod']]['tf'] : $s['tf']])->values();
        $faltan = array_diff($usadas, $series->pluck('cod')->all());
        if ($faltan) {
            return back()->withInput()->with('aviso', 'No se puede quitar una serie que ya tiene facturas ('.implode(', ', $faltan).').');
        }
        if ($request->filled('nueva_serie')) {
            $cod = strtoupper($request->input('nueva_serie'));
            if ($series->contains('cod', $cod)) {
                return back()->withInput()->with('aviso', 'Ya existe la serie '.$cod.'.');
            }
            $series->push(['cod' => $cod, 'nombre' => 'Nueva serie', 'tf' => 'F1']);
        }
        if ($series->pluck('cod')->duplicates()->count()) {
            return back()->withInput()->with('aviso', 'Hay códigos de serie repetidos.');
        }
        $cfg->fill([
            'modo' => $request->input('modo'),
            'pie' => trim((string) $request->input('pie')),
            'series' => $series->all(),
        ])->save();
        Auditoria::registrar('factura.configuracion', 'Configuración de facturación guardada', null, $c->id);

        return redirect()->route('facturacion.config')->with('ok', 'Configuración y diseño de las facturas guardados.');
    }

    // ------------------------------------------------------- diseño de la factura
    public function diseno(Request $request)
    {
        $c = $this->emisor($request);
        if (! $c) {
            return $this->sinEmisor();
        }
        $cfg = FacturacionConfig::de($c);

        return view('facturacion.diseno', $this->base($c, 'diseno') + ['cfg' => $cfg, 'est' => $cfg->estiloCompleto()]);
    }

    /** Valida y normaliza el estilo enviado desde el formulario de diseño */
    private function estiloDe(Request $request): array
    {
        $e = (array) $request->input('est', []);
        $in = fn ($k, $ops, $def) => in_array($e[$k] ?? null, $ops, true) ? $e[$k] : $def;

        return [
            'modelo' => $in('modelo', ['clasica', 'moderna', 'minimal', 'lateral'], 'clasica'),
            'color' => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($e['color'] ?? '')) ? strtoupper($e['color']) : '#0B0B0B',
            'forma' => $in('forma', ['recta', 'suave', 'redondeada'], 'suave'),
            'cabecera' => $in('cabecera', ['relleno', 'linea'], 'relleno'),
            'margen' => $in('margen', ['estrecho', 'normal', 'amplio'], 'normal'),
            'fuente' => $in('fuente', ['sans', 'serif'], 'sans'),
            'cuadros' => ! empty($e['cuadros']), 'logo' => ! empty($e['logo']), 'firma' => ! empty($e['firma']),
            'regShow' => ! empty($e['regShow']), 'reg' => mb_substr(trim((string) ($e['reg'] ?? '')), 0, 300),
        ];
    }

    public function guardarDiseno(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $cfg = FacturacionConfig::de($c);
        $cfg->estilo = $this->estiloDe($request);
        $cfg->save();
        Auditoria::registrar('factura.diseno', 'Diseño de la factura guardado', null, $c->id);

        return redirect()->route('facturacion.diseno')->with('ok', 'Diseño de la factura guardado. Se aplica a todas las facturas, también a las ya emitidas al descargarlas.');
    }

    /** Factura de muestra (vista previa del diseño; en POST, con el diseño aún sin guardar) */
    public function muestra(Request $request)
    {
        $c = $this->emisor($request);
        abort_unless($c, 403);
        $cfg = FacturacionConfig::de($c);
        if ($request->isMethod('post')) {
            $cfg->estilo = $this->estiloDe($request);
        }
        $L = [['d' => 'Servicio de asesoría mensual', 'q' => 1, 'p' => 120, 'iva' => 21, 'sup' => false], ['d' => 'Presentación de modelos trimestrales', 'q' => 2, 'p' => 45, 'iva' => 21, 'sup' => false], ['d' => 'Suplido · tasa registral', 'q' => 1, 'p' => 14.5, 'iva' => 0, 'sup' => true]];
        $t = Facturacion::totales($L, 0);
        $f = new Factura(['num' => 'A-'.now()->format('Y').'-0001', 'tipo' => 'F1', 'serie' => 'A', 'fecha' => now()->toDateString(), 'receptor_nombre' => 'Cliente de muestra SL', 'receptor_nif' => 'B00000000',
            'receptor_dir' => 'C/ Ejemplo 1, 21001 Huelva', 'lineas' => $L, 'ivas' => $t['ivas'], 'ret_pct' => 0, 'base' => $t['base'], 'cuota' => $t['cuota'], 'retencion' => 0, 'suplidos' => $t['suplidos'],
            'total' => $t['total'], 'forma_pago' => 'Transferencia', 'pago_dias' => 30, 'estado' => 'Emitida']);

        return view('facturacion.documento', ['c' => $c, 'f' => $f, 'cfg' => $cfg, 'qr' => Facturacion::qrSvg(Facturacion::urlQr($c->nif ?? '', $f)), 'draft' => false, 'imprimir' => false, 'muestra' => true]);
    }

    // ------------------------------------------------------------- utilidades
    private function csv(string $nombre, array $cab, array $filas): StreamedResponse
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
