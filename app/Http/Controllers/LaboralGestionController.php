<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioDocumento;
use App\Models\ConvenioVersion;
use App\Models\Documento;
use App\Models\EmpresaConvenio;
use App\Models\LaboralMes;
use App\Models\Trabajador;
use App\Services\AlmacenDocumentos;
use App\Services\Auditoria;
use App\Services\ConvenioIA;
use App\Services\Convenios;
use App\Services\Nomina;
use App\Support\ClasificadorDocumentos;
use App\Support\Formato;
use App\Support\PdfInfo;
use App\Support\Plazos;
use App\Support\Validacion;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Laboral y SS · desplegables Seguridad Social (CRA, AFI agrario, AFI planes de pensiones),
 * Gestión (remesa de nóminas, CCC, Comunic@, convenios, cálculo masivo), Modelos tributarios (145, 111, 190)
 * y Parámetros de contrato. Nada se envía a la TGSS, al SEPE ni a la AEAT.
 */
class LaboralGestionController extends LaboralController
{
    private function mesRow(Cliente $c, int $anio, int $mes): LaboralMes
    {
        return LaboralMes::firstOrNew(['cliente_id' => $c->id, 'anio' => $anio, 'mes' => $mes]);
    }

    private function noms(Cliente $c, int $anio, int $mes, array $P): array
    {
        return app(Nomina::class)->mes($c, $anio, $mes, null, null, (float) $P['at']);
    }

    // ------------------------------------------------------------------ CRA
    public function cra(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'ss-cra');
        $noms = array_values(array_filter($this->noms($c, $b['anio'], $b['mes'], $b['P']), fn ($x) => ! Nomina::esAutonomo($x['w'])));
        $filas = [];
        foreach ($noms as $x) {
            foreach (Nomina::CRA as $k => $l) {
                $v = (float) ($x['n'][$k] ?? 0);
                if (abs($v) > 0.004) {
                    $filas[] = ['w' => $x['w'], 'k' => $k, 'l' => $l, 'cod' => $b['P']['cra'][$k] ?? '', 'v' => $v];
                }
            }
        }
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            return $this->csv($request->query('exportar'), 'cra-'.$b['anio'].'-'.str_pad((string) $b['mes'], 2, '0', STR_PAD_LEFT), 'CRA · Conceptos retributivos abonados · '.Nomina::MESES[$b['mes'] - 1].' '.$b['anio'],
                ['NAF', 'Trabajador', 'Código CRA', 'Concepto', 'Importe'], array_map(fn ($f) => [$f['w']['naf'], $f['w']['nombre'], $f['cod'], $f['l'], Formato::num($f['v'])], $filas));
        }

        return view('laboral.cra', $b + ['filas' => $filas, 'nTrab' => count($noms)]);
    }

    public function guardarCra(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $map = [];
        foreach (array_keys(Nomina::CRA) as $k) {
            $v = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $request->input('cra.'.$k, '')));
            if ($v !== '') {
                $map[$k] = substr($v, 0, 6);
            }
        }
        $this->guardarConfig($c, ['cra' => $map]);

        return back()->with('ok', 'Códigos CRA guardados.');
    }

    // ------------------------------------------------- AFI agrario y planes
    private function afi(Request $request, string $tipo)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, $tipo === 'agr' ? 'ss-agr' : 'ss-pp');
        $todos = Nomina::asalariados($c)->filter(fn ($w) => Nomina::activo($w, $b['anio'], $b['mes']))->values();
        $lista = $tipo === 'agr' ? $todos->filter(fn ($w) => ! empty($w['agrario']))->values() : $todos;
        $datos = $this->mesRow($c, $b['anio'], $b['mes'])->afi[$tipo] ?? [];
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            $tit = $tipo === 'agr' ? 'AFI · Jornadas reales del Sistema Especial Agrario' : 'AFI · Contribuciones empresariales a planes de pensiones';

            return $this->csv($request->query('exportar'), ($tipo === 'agr' ? 'afi-agrario-' : 'afi-planes-').$b['anio'].'-'.str_pad((string) $b['mes'], 2, '0', STR_PAD_LEFT), $tit.' · '.Nomina::MESES[$b['mes'] - 1].' '.$b['anio'],
                ['NAF', 'NIF', 'Trabajador', 'CCC', $tipo === 'agr' ? 'Jornadas reales' : 'Contribución empresarial'],
                $lista->map(fn ($w) => [$w['naf'], $w['nif'], $w['nombre'], $w['ccc'], $tipo === 'agr' ? (string) ($datos[$w['id']] ?? 0) : Formato::num($datos[$w['id']] ?? 0)])->all());
        }

        return view('laboral.afi', $b + ['tipo' => $tipo, 'lista' => $lista, 'datos' => $datos, 'nTodos' => $todos->count()]);
    }

    public function agrario(Request $request)
    {
        return $this->afi($request, 'agr');
    }

    public function planes(Request $request)
    {
        return $this->afi($request, 'pp');
    }

    public function guardarAfi(Request $request, string $tipo)
    {
        abort_unless(in_array($tipo, ['agr', 'pp'], true), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $ids = Trabajador::where('cliente_id', $c->id)->pluck('id')->all();
        $out = [];
        $dias = Carbon::create($anio, $mes, 1)->daysInMonth;
        foreach ((array) $request->input('v', []) as $id => $v) {
            if (! in_array((int) $id, $ids, true) || trim((string) $v) === '') {
                continue;
            }
            $n = $tipo === 'agr' ? (int) $v : round(Formato::parseNum((string) $v), 2);
            if ($n < 0 || ($tipo === 'agr' && $n > $dias)) {
                return back()->withErrors(['ficha' => $tipo === 'agr' ? 'Las jornadas reales van de 0 a '.$dias.' en '.mb_strtolower(Nomina::MESES[$mes - 1]).'.' : 'Las contribuciones no pueden ser negativas.']);
            }
            $out[(int) $id] = $n;
        }
        $m = $this->mesRow($c, $anio, $mes);
        $afi = $m->afi ?? [];
        $afi[$tipo] = $out;
        $m->afi = $afi;
        $m->save();

        return back()->with('ok', ($tipo === 'agr' ? 'Jornadas reales' : 'Contribuciones a planes de pensiones').' de '.mb_strtolower(Nomina::MESES[$mes - 1]).' guardadas.');
    }

    // -------------------------------------------------------------- remesa
    public function remesa(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'g-remesa');

        return view('laboral.remesa', $b + ['noms' => $this->noms($c, $b['anio'], $b['mes'], $b['P']), 'bancos' => $c->seccion('bancos'),
            'ultima' => LaboralMes::where('cliente_id', $c->id)->where('anio', $b['anio'])->where('mes', $b['mes'])->first()?->remesa_at]);
    }

    public function generarRemesa(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $P = Nomina::config($c);
        $bancos = $c->seccion('bancos');
        $cta = $bancos[(int) $request->input('cuenta', -1)] ?? null;
        $fecha = (string) $request->input('fecha');
        $concepto = mb_substr(trim((string) $request->input('concepto')) ?: 'Nómina '.mb_strtolower(Nomina::MESES[$mes - 1]).' '.$anio, 0, 140);
        $sel = array_map('intval', (array) $request->input('t', []));
        $iban = strtoupper(preg_replace('/\s/', '', (string) ($cta['iban'] ?? '')));
        $err = ! $cta || ! Validacion::iban($iban) ? 'Elige la cuenta de cargo de la empresa (con un IBAN válido en Clientes › Datos bancarios).'
            : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) ? 'Indica la fecha de ejecución.' : (! $sel ? 'Marca al menos un trabajador.' : ''));
        if ($err) {
            return back()->withInput()->withErrors(['ficha' => $err]);
        }
        $pagos = [];
        foreach ($this->noms($c, $anio, $mes, $P) as $x) {
            if (! in_array((int) $x['w']['id'], $sel, true)) {
                continue;
            }
            $wi = strtoupper(preg_replace('/\s/', '', (string) $x['w']['iban']));
            if (! Validacion::iban($wi)) {
                return back()->withInput()->withErrors(['ficha' => $x['w']['nombre'].' no tiene un IBAN válido en su ficha.']);
            }
            if (round($x['t']['liq'], 2) <= 0) {
                continue;
            }
            $pagos[] = ['nombre' => $x['w']['nombre'], 'iban' => $wi, 'bic' => Validacion::banco($wi)[1] ?? '', 'importe' => round($x['t']['liq'], 2), 'id' => $x['w']['id']];
        }
        if (! $pagos) {
            return back()->withInput()->withErrors(['ficha' => 'No hay líquidos a pagar en los trabajadores marcados.']);
        }
        $bic = strtoupper((string) ($cta['bic'] ?? '')) ?: (Validacion::banco($iban)[1] ?? '');
        $xml = $this->pain001($c, $iban, $bic, $fecha, $concepto, $pagos, $anio, $mes);
        $m = $this->mesRow($c, $anio, $mes);
        $m->remesa_at = now();
        $m->save();
        Auditoria::registrar('laboral.remesa', 'Remesa de nóminas de '.Nomina::MESES[$mes - 1].' '.$anio.' ('.count($pagos).' transferencias, '.Formato::eur(array_sum(array_column($pagos, 'importe'))).')', null, $c->id);

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="remesa-nominas-'.$anio.'-'.str_pad((string) $mes, 2, '0', STR_PAD_LEFT).'.xml"']);
    }

    /** Transferencias SEPA (ISO 20022 pain.001.001.03, categoría SALA) */
    private function pain001(Cliente $c, string $iban, string $bic, string $fecha, string $concepto, array $pagos, int $anio, int $mes): string
    {
        $x = fn ($v, $max = 70) => htmlspecialchars(mb_substr(Str::ascii((string) $v), 0, $max), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $id = 'NOM'.$anio.str_pad((string) $mes, 2, '0', STR_PAD_LEFT).'-'.strtoupper(Str::random(8));
        $total = number_format(array_sum(array_column($pagos, 'importe')), 2, '.', '');
        $agente = fn ($b) => $b !== '' ? '<FinInstnId><BIC>'.$x($b, 11).'</BIC></FinInstnId>' : '<FinInstnId><Othr><Id>NOTPROVIDED</Id></Othr></FinInstnId>';
        $tx = '';
        foreach ($pagos as $i => $p) {
            $tx .= '<CdtTrfTxInf><PmtId><EndToEndId>'.$x($id.'-'.($i + 1), 35).'</EndToEndId></PmtId><Amt><InstdAmt Ccy="EUR">'.number_format($p['importe'], 2, '.', '').'</InstdAmt></Amt>'
                .($p['bic'] !== '' ? '<CdtrAgt>'.$agente($p['bic']).'</CdtrAgt>' : '')
                .'<Cdtr><Nm>'.$x($p['nombre']).'</Nm></Cdtr><CdtrAcct><Id><IBAN>'.$p['iban'].'</IBAN></Id></CdtrAcct><Purp><Cd>SALA</Cd></Purp><RmtInf><Ustrd>'.$x($concepto, 140).'</Ustrd></RmtInf></CdtTrfTxInf>';
        }
        $nif = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $c->nif));

        return '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><CstmrCdtTrfInitn>'
            .'<GrpHdr><MsgId>'.$x($id, 35).'</MsgId><CreDtTm>'.now()->format('Y-m-d\TH:i:s').'</CreDtTm><NbOfTxs>'.count($pagos).'</NbOfTxs><CtrlSum>'.$total.'</CtrlSum><InitgPty><Nm>'.$x($c->nombre).'</Nm>'
            .($nif ? '<Id><OrgId><Othr><Id>'.$x($nif, 35).'</Id></Othr></OrgId></Id>' : '').'</InitgPty></GrpHdr>'
            .'<PmtInf><PmtInfId>'.$x($id, 35).'</PmtInfId><PmtMtd>TRF</PmtMtd><BtchBookg>true</BtchBookg><NbOfTxs>'.count($pagos).'</NbOfTxs><CtrlSum>'.$total.'</CtrlSum>'
            .'<PmtTpInf><SvcLvl><Cd>SEPA</Cd></SvcLvl><CtgyPurp><Cd>SALA</Cd></CtgyPurp></PmtTpInf><ReqdExctnDt>'.$fecha.'</ReqdExctnDt>'
            .'<Dbtr><Nm>'.$x($c->nombre).'</Nm></Dbtr><DbtrAcct><Id><IBAN>'.$iban.'</IBAN></Id><Ccy>EUR</Ccy></DbtrAcct><DbtrAgt>'.$agente($bic).'</DbtrAgt><ChrgBr>SLEV</ChrgBr>'
            .$tx.'</PmtInf></CstmrCdtTrfInitn></Document>';
    }

    // ----------------------------------------------------------------- CCC
    public function ccc(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'g-ccc');
        $P = $b['P'];
        $filas = $P['cccsDet'] ?: array_map(fn ($x) => ['ccc' => $x, 'denom' => '', 'regimen' => 'General', 'cnae' => '', 'at' => ''], $P['cccs']);
        $cuenta = Nomina::plantilla($c)->filter(fn ($w) => $w['estado'] === 'Alta')->countBy(fn ($w) => preg_replace('/\D/', '', (string) $w['ccc']))->all();

        return view('laboral.ccc', $b + ['filas' => $filas, 'cuenta' => $cuenta]);
    }

    public function guardarCcc(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $out = [];
        foreach ((array) $request->input('f', []) as $f) {
            if (! is_array($f)) {
                continue;
            }
            $ccc = preg_replace('/\D/', '', (string) ($f['ccc'] ?? ''));
            if ($ccc === '') {
                continue;
            }
            if (strlen($ccc) !== 11) {
                return back()->withInput()->withErrors(['ficha' => 'El CCC '.$ccc.' debe tener 11 dígitos (2 de provincia, 7 de número y 2 de control).']);
            }
            $out[] = ['ccc' => $ccc, 'denom' => mb_substr(trim((string) ($f['denom'] ?? '')), 0, 120), 'regimen' => in_array($f['regimen'] ?? '', ['General', 'Sistema Especial Agrario', 'Sistema Especial de Empleados de Hogar', 'Otro'], true) ? $f['regimen'] : 'General',
                'cnae' => mb_substr(preg_replace('/[^0-9.]/', '', (string) ($f['cnae'] ?? '')), 0, 6), 'at' => mb_substr(trim((string) ($f['at'] ?? '')), 0, 8)];
        }
        $this->guardarConfig($c, ['cccsDet' => array_values(collect($out)->unique('ccc')->all()), 'cccs' => array_values(array_unique(array_column($out, 'ccc')))]);
        Auditoria::registrar('laboral.ccc', 'Códigos de cuenta de cotización de '.$c->nombre.' guardados', null, $c->id);

        return redirect()->route('laboral.ccc')->with('ok', count($out).' códigos de cuenta de cotización guardados.');
    }

    // ------------------------------------------------------------- Comunic@
    public function comunica(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'g-comunica');
        $hoy = Plazos::hoy();
        $filas = Nomina::asalariados($c)->map(function ($w) use ($hoy) {
            $com = collect($w['docs'] ?? [])->firstWhere('k', 'contrata');
            $plazo = Carbon::parse($w['alta'])->addDays(10);

            return ['w' => $w, 'com' => $com, 'plazo' => $plazo, 'vencido' => ! $com && $plazo->lt($hoy)];
        })->sortBy(fn ($f) => ($f['com'] ? 1 : 0).$f['plazo']->format('Ymd'))->values();

        return view('laboral.comunica', $b + ['filas' => $filas]);
    }

    // ------------------------------------------------------------ convenios
    public function convenios(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }

        $cv = Convenios::asignacion($c)?->convenio;
        $ver = $cv ? Convenios::editable($cv, (int) $request->query('version')) : null;
        // Simulador: calcula sin guardar nada
        $sim = null;
        $simIn = (array) $request->query('sim', []);
        if ($cv && $ver && $simIn) {
            $vv = validator($simIn, ['cat' => ['required', 'string', 'max:150'], 'mes' => ['required', 'date_format:Y-m'], 'jor' => ['required', 'numeric', 'min:1', 'max:100'],
                'alta' => ['nullable', 'date_format:Y-m-d'], 'baja' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:alta'], 'antig' => ['nullable', 'date_format:Y-m-d']],
                ['cat.required' => 'Elige una categoría.', 'mes.*' => 'Indica el mes.', 'jor.*' => 'La jornada va del 1 al 100 %.', 'baja.after_or_equal' => 'La baja no puede ser anterior al alta.'], []);
            $sim = $vv->fails() ? ['error' => implode(' ', $vv->errors()->all())] : Convenios::simular($cv, $ver, $vv->validated() + ['prorr' => ! empty($simIn['prorr'])]);
        }

        return view('laboral.convenios', $this->base($request, $c, 'g-conv') + ['sim' => $sim, 'simIn' => $simIn,
            'cv' => $cv, 'ver' => $ver, 'convEd' => $cv ? Convenios::forma($cv, $ver) : null,
            'versiones' => $cv ? ConvenioVersion::withoutGlobalScopes()->withCount(['categorias' => fn ($q) => $q->withoutGlobalScopes()])->where('convenio_id', $cv->id)->orderByRaw('efectos_desde is null')->orderBy('efectos_desde')->orderBy('id')->get() : collect(),
            'validacion' => $cv ? Convenios::validar($cv) : [],
            'nEmpresas' => $cv ? EmpresaConvenio::withoutGlobalScopes()->where('convenio_id', $cv->id)->distinct('cliente_id')->count('cliente_id') : 0,
            'todos' => Convenio::orderBy('nombre')->get(['id', 'nombre', 'codigo', 'estado']),
            'docs' => $cv ? ConvenioDocumento::with('documento')->where('convenio_id', $cv->id)->orderByDesc('id')->get() : collect(),
        ]);
    }

    /** Asigna a la empresa un convenio del despacho (o uno nuevo) desde el primer día del mes */
    public function asignarConvenio(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $sel = (string) $request->input('convenio_id');
        $actual = Convenios::asignacion($c);
        if ($sel === 'nuevo') {
            $nombre = mb_substr(trim((string) $request->input('nombre')), 0, 250);
            if ($nombre === '') {
                return back()->withErrors(['conv' => 'Escribe el nombre del convenio nuevo.']);
            }
            $cv = Convenio::create(['nombre' => $nombre, 'estado' => 'borrador', 'created_by' => $request->user()->id]);
        } else {
            $cv = Convenio::findOrFail((int) $sel);
        }
        if ($actual && $actual->convenio_id === $cv->id) {
            return back()->with('ok', 'La empresa ya tiene asignado ese convenio.');
        }
        $desde = now()->startOfMonth();
        if ($actual) {
            // El convenio anterior queda para los meses ya pasados
            $actual->hasta = $actual->desde && $actual->desde->gte($desde) ? $actual->desde : $desde->copy()->subDay();
            $actual->save();
        }
        EmpresaConvenio::create(['cliente_id' => $c->id, 'convenio_id' => $cv->id, 'desde' => $actual ? $desde->toDateString() : null]);
        Nomina::olvidarReglas();
        Auditoria::registrar('laboral.convenio.asignar', 'Convenio «'.$cv->nombre.'» asignado a '.$c->nombre, null, $c->id);

        return redirect()->route('laboral.convenios')->with('ok', 'Convenio «'.$cv->nombre.'» asignado a '.$c->nombre.($actual ? ' desde el '.$desde->format('d/m/Y').'.' : '.'));
    }

    /** Nueva versión (copia de la seleccionada) con su fecha de efectos; opcionalmente cierra la anterior el día antes */
    public function nuevaVersion(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $cv = Convenios::asignacion($c)?->convenio ?? abort(404);
        $data = $request->validate(['base_id' => ['required', 'integer'], 'nombre' => ['required', 'string', 'max:160'], 'efectos_desde' => ['required', 'date']],
            ['efectos_desde.required' => 'Indica desde cuándo tiene efectos económicos la nueva versión.']);
        $base = ConvenioVersion::withoutGlobalScopes()->where('convenio_id', $cv->id)->findOrFail($data['base_id']);
        $n = Convenios::nuevaVersion($base, ['nombre' => $data['nombre'], 'efectos_desde' => $data['efectos_desde']]);
        if ($request->boolean('cerrar') && (! $base->efectos_hasta || $base->efectos_hasta->toDateString() >= $data['efectos_desde'])) {
            $base->efectos_hasta = Carbon::parse($data['efectos_desde'])->subDay();
            $base->save();
        }
        Auditoria::registrar('laboral.convenio.version', 'Nueva versión «'.$n->nombre.'» del convenio «'.$cv->nombre.'»', null, $c->id);

        return redirect()->route('laboral.convenios', ['version' => $n->id])->with('ok', 'Versión «'.$n->nombre.'» creada en borrador con los datos de «'.$base->nombre.'». Revisa las tablas y apruébala.');
    }

    /** PDF del convenio (texto, anexo, tablas o revisión) en el gestor documental del despacho */
    public function documentoConvenio(Request $request, AlmacenDocumentos $almacen)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $cv = Convenios::asignacion($c)?->convenio ?? abort(404);
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:30000'], 'tipo' => ['required', 'in:'.implode(',', array_keys(ConvenioDocumento::TIPOS))]],
            ['pdf.mimes' => 'Sube un PDF.', 'pdf.max' => 'El PDF no puede pasar de 30 MB.']);
        $doc = self::guardarPdf($almacen, $request->file('pdf'), $request->user()->id);
        ConvenioDocumento::create(['convenio_id' => $cv->id, 'version_id' => $request->integer('version_id') ?: null, 'documento_id' => $doc->id, 'tipo' => $request->input('tipo'),
            'descripcion' => mb_substr((string) $request->input('descripcion'), 0, 250) ?: null]);
        Auditoria::registrar('laboral.convenio.documento', 'PDF añadido al convenio «'.$cv->nombre.'»', null, $c->id);

        return back()->with('ok', 'Documento guardado (cifrado) en el convenio.');
    }

    /** Los PDF de convenios se guardan en los documentos del despacho: son públicos y sirven para varias empresas */
    public static function guardarPdf(AlmacenDocumentos $almacen, UploadedFile $f, int $userId): Documento
    {
        $desp = Cliente::where('es_despacho', true)->first();
        abort_unless($desp, 422, 'Falta la ficha del despacho.');
        $g = $almacen->guardar($f, $desp->id);

        return Documento::create(['cliente_id' => $desp->id, 'ambito' => 'gestor', 'borrador' => false, 'carpeta' => 'otros/varios', 'tipo' => 'docu',
            'nombre' => ClasificadorDocumentos::nombreSeguro('Convenio - '.$f->getClientOriginalName()), 'nombre_original' => ClasificadorDocumentos::nombreSeguro($f->getClientOriginalName()),
            'fecha' => now()->toDateString(), 'mime' => $g['mime'], 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'], 'origen' => 'Despacho', 'subido_por' => $userId]);
    }

    public function guardarConvenios(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $s = fn ($k, $max = 160) => mb_substr(trim((string) $request->input($k, '')), 0, $max);
        $cats = [];
        foreach ((array) $request->input('cats', []) as $x) {
            if (! is_array($x) || trim((string) ($x['cat'] ?? '')) === '') {
                continue;
            }
            $cats[] = ['cat' => mb_substr(trim($x['cat']), 0, 120), 'grupo' => (string) max(1, min(11, (int) ($x['grupo'] ?? 7))),
                'grupo_profesional' => mb_substr(trim((string) ($x['grupo_profesional'] ?? '')), 0, 80), 'nivel' => mb_substr(trim((string) ($x['nivel'] ?? '')), 0, 40),
                // Sin importe = no localizado (nunca cero)
                'base' => trim((string) ($x['base'] ?? '')) === '' ? null : round(Formato::parseNum((string) $x['base']), 4), 'plus' => round(Formato::parseNum((string) ($x['plus'] ?? 0)), 4),
                'unidad' => ($x['unidad'] ?? 'mes') === 'dia' ? 'dia' : 'mes', 'art' => mb_substr((string) ($x['art'] ?? ''), 0, 80)];
        }
        $num = fn ($v) => trim((string) $v) === '' ? null : round(Formato::parseNum((string) $v), 2);
        $extras = [];
        foreach (array_values((array) $request->input('extras', [])) as $i => $x) {
            if (! is_array($x)) {
                continue;
            }
            $calc = in_array($x['calculo'] ?? '', ['mensualidad', 'dias', 'importe'], true) ? $x['calculo'] : 'mensualidad';
            if (($calc === 'dias' && ! $num($x['dias'] ?? '')) || ($calc === 'importe' && ! $num($x['importe'] ?? ''))) {
                return back()->withInput()->withErrors(['conv' => 'En la paga «'.trim((string) ($x['nombre'] ?? '')).'» falta '.($calc === 'dias' ? 'el número de días.' : 'el importe.')]);
            }
            $extras[] = ['nombre' => mb_substr(trim((string) ($x['nombre'] ?? '')) ?: 'Paga extraordinaria '.($i + 1), 0, 80), 'mes' => max(1, min(12, (int) ($x['mes'] ?? 12))), 'calculo' => $calc,
                'dias' => $num($x['dias'] ?? ''), 'importe' => $num($x['importe'] ?? ''), 'plus' => ! empty($x['plus']), 'antig' => ! empty($x['antig']), 'art' => ''];
        }
        $antig = $request->boolean('ant_si') && $num($request->input('ant_valor'))
            ? ['periodo' => max(1, min(10, (int) $request->input('ant_periodo', 3))), 'tipo' => $request->input('ant_tipo') === 'porcentaje' ? 'porcentaje' : 'importe',
                'valor' => $num($request->input('ant_valor')), 'tope' => (int) $request->input('ant_tope') ?: null, 'art' => '']
            : null;
        $pluses = [];
        foreach ((array) $request->input('pluses', []) as $p) {
            if (! is_array($p) || trim((string) ($p['nombre'] ?? '')) === '' || ! $num($p['valor'] ?? '')) {
                continue;
            }
            $pluses[] = ['nombre' => mb_substr(trim($p['nombre']), 0, 80), 'unidad' => in_array($p['unidad'] ?? '', ['mes', 'dia_trabajado', 'dia_natural', 'hora'], true) ? $p['unidad'] : 'mes',
                'tipo' => ($p['tipo'] ?? '') === 'porcentaje_base' ? 'porcentaje_base' : 'importe', 'valor' => $num($p['valor']), 'auto' => ! empty($p['auto']),
                'cotiza' => ! empty($p['cotiza']), 'tributa' => ! empty($p['tributa']), 'art' => mb_substr((string) ($p['art'] ?? ''), 0, 80)];
        }
        $tramos = fn ($l) => array_values(array_map(fn ($t) => ['desde' => max(1, (int) ($t['desde'] ?? 1)), 'hasta' => trim((string) ($t['hasta'] ?? '')) === '' ? null : (int) $t['hasta'],
            'pct' => max(0, min(100, (float) Formato::parseNum((string) ($t['pct'] ?? 0))))], array_filter((array) $l, fn ($t) => is_array($t) && trim((string) ($t['pct'] ?? '')) !== '')));
        $hx = in_array($request->input('hx_tipo'), ['importe', 'recargo'], true) && $num($request->input('hx_valor'))
            ? ['tipo' => $request->input('hx_tipo'), 'valor' => $num($request->input('hx_valor')), 'art' => ''] : null;
        $fechas = [];
        foreach (['ver_efectos_desde', 'ver_efectos_hasta', 'ver_vigencia_desde', 'ver_vigencia_hasta', 'conv_fecha_pub'] as $k) {
            $x = trim((string) $request->input($k, ''));
            if ($x !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $x)) {
                return back()->withInput()->withErrors(['conv' => 'Revisa las fechas del convenio.']);
            }
            $fechas[$k] = $x !== '' ? $x : null;
        }
        if ($fechas['ver_efectos_desde'] && $fechas['ver_efectos_hasta'] && $fechas['ver_efectos_hasta'] < $fechas['ver_efectos_desde']) {
            return back()->withInput()->withErrors(['conv' => 'La fecha final de efectos no puede ser anterior a la inicial.']);
        }
        $cv = Convenios::deEmpresa($c, $request->user()->id);
        $ver = Convenios::editable($cv, $request->integer('version_id') ?: null);
        if ($request->has('conv_estado')) {
            $cv->fill(['sector' => $s('conv_sector') ?: null, 'ambito_funcional' => $s('conv_ambito_funcional', 500) ?: null, 'ambito_personal' => $s('conv_ambito_personal', 500) ?: null,
                'publicacion' => $s('conv_publicacion', 250) ?: null, 'url' => $s('conv_url', 500) ?: null, 'fecha_publicacion' => $fechas['conv_fecha_pub'],
                'estado' => array_key_exists($request->input('conv_estado'), Convenio::ESTADOS) ? $request->input('conv_estado') : $cv->estado,
                'observaciones' => mb_substr((string) $request->input('conv_observaciones'), 0, 5000) ?: null, 'no_soportado' => mb_substr((string) $request->input('conv_no_soportado'), 0, 5000) ?: null])->save();
        }
        if ($request->has('ver_nombre')) {
            $ver->fill(['nombre' => $s('ver_nombre') ?: $ver->nombre, 'efectos_desde' => $fechas['ver_efectos_desde'], 'efectos_hasta' => $fechas['ver_efectos_hasta'],
                'vigencia_desde' => $fechas['ver_vigencia_desde'], 'vigencia_hasta' => $fechas['ver_vigencia_hasta']]);
            $est = (string) $request->input('ver_estado', $ver->estado);
            if (array_key_exists($est, Convenio::ESTADOS) && $est !== $ver->estado) {
                $ver->estado = $est;
                if ($est === 'aprobado') {
                    $ver->aprobada_por = $request->user()->id;
                    $ver->aprobada_at = now();
                }
            }
            $ver->save();
        }
        $prevConv = Convenios::forma($cv, $ver);
        Convenios::guardar($ver, array_replace($prevConv, ['extras' => $extras, 'antig' => $antig, 'pluses' => $pluses,
            'it' => ['cc' => $tramos($request->input('it_cc', [])), 'at' => $tramos($request->input('it_at', [])), 'art' => $prevConv['it']['art'] ?? ''], 'hx' => $hx,
            'nombre' => $s('conv_nombre', 200), 'codigo' => $s('conv_codigo', 40), 'ambito' => $s('conv_ambito'), 'vigencia' => $s('conv_vigencia', 60),
            'jornada' => $s('conv_jornada', 10), 'vacaciones' => $s('conv_vacaciones', 10), 'pagas' => $extras ? (string) (12 + count($extras)) : (in_array($request->input('conv_pagas'), ['12', '14', '15', '16'], true) ? $request->input('conv_pagas') : '14'), 'cats' => $cats]));
        $bajo = self::bajoConvenio($c, $cats);
        Auditoria::registrar('laboral.convenio', 'Convenio de '.$c->nombre.' guardado', null, $c->id);

        return redirect()->route('laboral.convenios', ['version' => $ver->id])->with('ok', 'Versión «'.$ver->nombre.'» guardada'.($ver->estado === 'aprobado' ? ' y aplicada a las nóminas de '.$c->nombre.' en su periodo de efectos' : ' en '.mb_strtolower(Convenio::ESTADOS[$ver->estado]).': las nóminas solo la usan si no hay una versión aprobada para ese mes').'.'.($bajo ? ' Hay '.count($bajo).' trabajadores por debajo del convenio: revísalos (no se ha cambiado ningún salario).' : ''));
    }

    /** Lee el PDF del convenio con IA y aplica sus tablas y reglas a la empresa y a su plantilla */
    public function leerConvenioIA(Request $request, ConvenioIA $ia)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:30000']], ['pdf.mimes' => 'El convenio debe ser un PDF.', 'pdf.max' => 'El PDF no puede pasar de 30 MB.']);
        $bytes = (string) file_get_contents($request->file('pdf')->getRealPath());
        try {
            $r = $ia->leer($bytes);
        } catch (\Throwable $e) {
            return redirect()->route('laboral.convenios')->withErrors(['conv' => 'No se ha podido leer el convenio: '.$e->getMessage()]);
        }

        $doc = Cliente::where('es_despacho', true)->exists() ? self::guardarPdf(app(AlmacenDocumentos::class), $request->file('pdf'), $request->user()->id) : null;

        return $this->aplicarConvenio($c, $r, $request->file('pdf')->getClientOriginalName(), $doc?->id, PdfInfo::analizar($bytes));
    }

    /** Lectura gratuita: el convenio se lee en claude.ai con la suscripción del despacho y aquí se pega la respuesta */
    public function pegarConvenioIA(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate(['respuesta' => ['required', 'string', 'max:500000'], 'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:30000']],
            ['respuesta.required' => 'Pega la respuesta de Claude.', 'pdf.mimes' => 'Adjunta el PDF del convenio.', 'pdf.max' => 'El PDF no puede pasar de 30 MB.']);
        try {
            $r = ConvenioIA::desdeTexto((string) $request->input('respuesta'));
        } catch (\Throwable $e) {
            return redirect()->route('laboral.convenios', ['ia' => 1])->withInput()->withErrors(['conv' => $e->getMessage()]);
        }
        // El PDF que se leyó en claude.ai queda como documento del convenio (procedencia de la propuesta)
        $doc = null;
        $info = null;
        if ($f = $request->file('pdf')) {
            $info = PdfInfo::analizar((string) file_get_contents($f->getRealPath()));
            $doc = Cliente::where('es_despacho', true)->exists() ? self::guardarPdf(app(AlmacenDocumentos::class), $f, $request->user()->id) : null;
        }

        return $this->aplicarConvenio($c, $r, 'Leído con Claude (claude.ai)'.($f ? ' · '.$f->getClientOriginalName() : ''), $doc?->id, $info);
    }

    /** La lectura del PDF (con IA o pegada desde claude.ai) queda como PROPUESTA: no toca el convenio ni la plantilla hasta que se aprueba */
    private function aplicarConvenio(Cliente $c, array $r, string $archivo, ?int $docId = null, ?array $pdf = null)
    {
        if (! $r['cats']) {
            return redirect()->route('laboral.convenios')->withErrors(['conv' => 'No se han encontrado tablas salariales. Comprueba que el PDF es el convenio completo (con sus anexos) y no solo la resolución de publicación. No se ha guardado nada.']);
        }
        if ($pdf && $pdf['escaneado']) {
            $r['revision'][] = ['ambiguo', 'El PDF parece escaneado (solo imágenes, sin texto seleccionable): la lectura puede tener errores de reconocimiento. Comprueba las cifras una a una con el PDF.'];
        } elseif ($pdf && $pdf['cifrado']) {
            $r['revision'][] = ['ambiguo', 'El PDF está protegido: no se ha podido comprobar si tiene texto. Revisa las cifras con el PDF.'];
        }
        $this->guardarConfig($c, ['convPropuesta' => $r + ['_meta' => ['fecha' => now()->toDateTimeString(), 'archivo' => mb_substr($archivo, 0, 160), 'usuario' => auth()->id(), 'documento_id' => $docId, 'pdf' => $pdf]]]);
        Auditoria::registrar('laboral.convenio.propuesta', 'Propuesta de convenio leída ('.$archivo.') para '.$c->nombre.': '.count($r['cats']).' categorías', null, $c->id);

        return redirect()->route('laboral.convenios', ['revisar' => 1])->with('ok', 'Propuesta lista para revisar: todavía no se ha aplicado nada al convenio ni a las nóminas.');
    }

    /** Aprobar o descartar la propuesta leída del PDF */
    public function propuestaConvenio(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $P = Nomina::config($c);
        $r = $P['convPropuesta'] ?? null;
        if (! $r) {
            return redirect()->route('laboral.convenios')->withErrors(['conv' => 'No hay ninguna propuesta pendiente.']);
        }
        if ($request->input('accion') !== 'aplicar') {
            $this->guardarConfig($c, ['convPropuesta' => null]);
            Auditoria::registrar('laboral.convenio.propuesta', 'Propuesta de convenio descartada para '.$c->nombre, null, $c->id);

            return redirect()->route('laboral.convenios')->with('ok', 'Propuesta descartada. El convenio no ha cambiado.');
        }
        $m = $r['_meta'] ?? [];
        // Lo que se aplica: los apartados marcados y las categorías tal como se han revisado (corregidas a mano si hacía falta)
        $sec = array_flip((array) $request->input('apartados', array_keys(ConvenioIA::APARTADOS)));
        $cats = [];
        $errs = [];
        foreach ((array) $request->input('cats', []) as $i => $x) {
            if (! is_array($x) || empty($x['incluir'])) {
                continue;
            }
            $nom = mb_substr(trim((string) ($x['cat'] ?? '')), 0, 120);
            $base = trim((string) ($x['base'] ?? '')) === '' ? null : Formato::parseNum((string) $x['base']);
            $g = (int) ($x['grupo'] ?? 0);
            if ($nom === '') {
                $errs["cats.$i.cat"] = 'Falta el nombre de la categoría.';
            } elseif (isset($cats[$nom])) {
                $errs["cats.$i.cat"] = 'Categoría repetida: «'.$nom.'».';
            }
            if ($base === null || $base <= 0) {
                $errs["cats.$i.base"] = 'Salario de «'.$nom.'» vacío o no válido (no se pone a cero).';
            }
            if ($g < 1 || $g > 11) {
                $errs["cats.$i.grupo"] = 'Grupo de cotización de «'.$nom.'» del 1 al 11.';
            }
            $orig = collect($r['cats'])->firstWhere('cat', (string) ($x['orig'] ?? '')) ?? [];
            $cats[$nom] = ['cat' => $nom, 'grupo' => (string) $g, 'base' => round((float) $base, 4), 'plus' => round(Formato::parseNum((string) ($x['plus'] ?? '0')), 4),
                'unidad' => ($x['unidad'] ?? 'mes') === 'dia' ? 'dia' : 'mes', 'art' => $orig['art'] ?? '', 'pag' => $orig['pag'] ?? null];
        }
        if (! $request->has('cats')) {
            // Sin revisión de la tabla (aprobación directa): la propuesta tal cual
            $cats = collect($r['cats'])->keyBy('cat')->all();
        }
        if (isset($sec['tablas']) && ! $cats) {
            $errs['cats'] = 'No hay ninguna categoría marcada para aplicar.';
        }
        $cv = Convenios::deEmpresa($c, $request->user()->id);
        $destino = (string) $request->input('destino', 'actual');
        $verDestino = null;
        if ($destino === 'nueva') {
            if (trim((string) $request->input('nueva_nombre')) === '') {
                $errs['nueva_nombre'] = 'Pon nombre a la versión nueva.';
            }
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('nueva_efectos'))) {
                $errs['nueva_efectos'] = 'Indica desde cuándo tiene efectos la versión nueva.';
            }
        } else {
            $verDestino = Convenios::editable($cv, $request->integer('version_id') ?: null);
            if ($verDestino->estado === 'aprobado' && $request->has('destino')) {
                $errs['destino'] = 'La versión «'.$verDestino->nombre.'» está aprobada: la lectura se guarda en una versión nueva o en una que esté en borrador.';
            }
        }
        if ($errs) {
            return redirect()->route('laboral.convenios', ['revisar' => 1])->withInput()->withErrors($errs + ['conv' => 'No se ha aplicado nada: revisa los datos marcados.']);
        }
        if ($destino === 'nueva') {
            $base = Convenios::version($cv);
            $verDestino = $base ? Convenios::nuevaVersion($base, ['nombre' => mb_substr(trim((string) $request->input('nueva_nombre')), 0, 120), 'efectos_desde' => $request->input('nueva_efectos')])
                : ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => mb_substr(trim((string) $request->input('nueva_nombre')), 0, 120), 'efectos_desde' => $request->input('nueva_efectos'), 'estado' => 'borrador', 'reglas' => []]);
        }
        $ver = $verDestino;
        $actual = Convenios::forma($cv, $ver);
        $conv = ['ia' => ['fecha' => $m['fecha'] ?? now()->toDateTimeString(), 'archivo' => $m['archivo'] ?? '', 'anioTablas' => $r['anioTablas'], 'avisos' => $r['avisos'],
            'aprobado' => now()->toDateTimeString(), 'aprobadoPor' => $request->user()->id, 'revision' => $r['revision'] ?? [], 'estados' => $r['estados'] ?? []]];
        if (isset($sec['identificacion'])) {
            $conv += array_filter(['nombre' => $r['nombre'], 'codigo' => $r['codigo'], 'ambito' => $r['ambito'], 'vigencia' => $r['vigencia']], fn ($v) => $v !== '');
        }
        if (isset($sec['jornada'])) {
            $conv += array_filter(['jornada' => $r['jornada'], 'vacaciones' => $r['vacaciones']], fn ($v) => $v !== '');
        }
        if (isset($sec['tablas'])) {
            // Las categorías que ya estaban y no vienen en la lectura se conservan salvo que se marquen para quitar
            $quitar = array_flip((array) $request->input('quitar', []));
            $conv['cats'] = array_values(array_merge(collect($actual['cats'])->reject(fn ($x) => isset($quitar[$x['cat']]) || isset($cats[$x['cat']]))->all(), $cats));
        }
        if (isset($sec['pagas_extra'])) {
            $conv['extras'] = $r['extras'];
            $conv['pagas'] = $r['extras'] ? (string) (12 + count($r['extras'])) : ($actual['pagas'] ?? '14');
        }
        if (isset($sec['antiguedad'])) {
            $conv['antig'] = $r['antig'];
        }
        if (isset($sec['it'])) {
            $conv['it'] = array_replace($actual['it'] ?? [], $r['it']);
        }
        if (isset($sec['horas_extra'])) {
            $conv['hx'] = $r['hx'];
        }
        if (isset($sec['pluses'])) {
            // Los pluses leídos se añaden o actualizan por nombre; los conceptos creados a mano no se borran
            $conv['pluses'] = $r['pluses'];
        }
        Convenios::guardar($ver, $conv, conservarConceptos: true);
        $ver->fuente = 'ia';
        // Procedencia: documento, fecha, usuario y artículo/página de cada dato
        $ver->referencias = array_replace($ver->referencias ?? [], ['ia' => ['documento_id' => $m['documento_id'] ?? null, 'archivo' => $m['archivo'] ?? '', 'fecha' => $m['fecha'] ?? null,
            'aprobado_por' => $request->user()->name, 'pdf' => $m['pdf'] ?? null, 'apartados' => array_keys($sec),
            'cats' => collect($cats)->map(fn ($x) => ['art' => $x['art'], 'pag' => $x['pag']])->all(),
            'pagas' => collect($r['extras'])->mapWithKeys(fn ($x) => [$x['nombre'] => ['art' => $x['art'], 'pag' => $x['pag'] ?? null]])->all(),
            'pluses' => collect($r['pluses'])->mapWithKeys(fn ($x) => [$x['nombre'] => ['art' => $x['art'], 'pag' => $x['pag'] ?? null]])->all()] + ($r['refs'] ?? [])]);
        $ver->save();
        if (! empty($m['documento_id'])) {
            ConvenioDocumento::firstOrCreate(['convenio_id' => $cv->id, 'documento_id' => $m['documento_id']], ['version_id' => $ver->id, 'tipo' => 'texto', 'descripcion' => 'PDF leído con IA']);
        }
        $this->guardarConfig($c, ['convPropuesta' => null]);
        Auditoria::registrar('laboral.convenio.ia', 'Propuesta aplicada a la versión «'.$ver->nombre.'» ('.implode(', ', array_keys($sec)).'). Aprobada por '.$request->user()->name.' para '.$c->nombre.': '.count($cats).' categorías', null, $c->id);
        $bajo = self::bajoConvenio($c, $conv['cats'] ?? $actual['cats']);

        return redirect()->route('laboral.convenios', ['version' => $ver->id])->with('ok', 'Lectura guardada en la versión «'.$ver->nombre.'» ('.(Convenio::ESTADOS[$ver->estado] ?? $ver->estado).').'
            .($ver->estado !== 'aprobado' ? ' Revísala y apruébala en «Versión y vigencia» para que la usen las nóminas con versión aprobada.' : '').' Los salarios de la plantilla no se han modificado'.($bajo ? ': revisa los '.count($bajo).' trabajadores por debajo del convenio.' : '.'));
    }

    /** Trabajadores en alta cuyo salario queda por debajo de su categoría (solo informa; nunca modifica fichas) */
    public static function bajoConvenio(Cliente $c, array $cats): array
    {
        $porCat = collect($cats)->keyBy('cat');
        $out = [];
        foreach (Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->get() as $t) {
            $d = $t->datos ?? [];
            $ct = $porCat[$d['convCat'] ?? ''] ?? null;
            if (! $ct || Nomina::esAutonomo($d)) {
                continue;
            }
            $dia = ($ct['unidad'] ?? 'mes') === 'dia';
            $mio = $dia ? (Nomina::diario($d) ? Nomina::n($d['salDia'] ?? 0) : Nomina::n($d['salBase'] ?? 0) / 30) : (Nomina::diario($d) ? Nomina::n($d['salDia'] ?? 0) * 30 : Nomina::n($d['salBase'] ?? 0));
            $min = (float) $ct['base'] * ($dia ? 1 : (Nomina::n($d['jor'] ?? 100) ?: 100) / 100);
            if ($mio + 0.001 < $min) {
                $out[] = ['id' => $t->id, 'nombre' => $d['nombre'] ?? 'Trabajador', 'cat' => $ct['cat'], 'tiene' => round($mio, 2), 'min' => round($min, 2), 'unidad' => $dia ? 'día' : 'mes'];
            }
        }

        return $out;
    }

    /** Clave de la API de Anthropic para leer convenios (solo la administración del despacho) */
    public function claveIA(Request $request)
    {
        abort_unless($request->user()->esAdmin(), 403);
        if ($request->boolean('quitar')) {
            Ajuste::where('clave', ConvenioIA::CLAVE)->delete();

            return back()->with('ok', 'Clave eliminada.');
        }
        $k = trim((string) $request->input('clave'));
        if (! preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $k)) {
            return back()->withErrors(['conv' => 'La clave no tiene el formato de una clave de la API de Anthropic (empieza por sk-ant-).']);
        }
        Ajuste::put(ConvenioIA::CLAVE, $k);
        Auditoria::registrar('laboral.ia.clave', 'Clave de la API de Anthropic configurada');

        return back()->with('ok', 'Clave guardada (cifrada). Ya puedes leer convenios con IA.');
    }

    // ---------------------------------------------------- cálculo de nóminas
    private function filtrar(Request $r, array $noms): array
    {
        $f = ['q' => mb_strtolower(trim((string) $r->query('q', ''))), 'ccc' => (string) $r->query('ccc', ''), 'tipo' => (string) $r->query('tipo', ''), 'cat' => (string) $r->query('cat', ''), 'grupo' => (string) $r->query('grupo', ''), 'contrato' => (string) $r->query('contrato', '')];

        return [array_values(array_filter($noms, function ($x) use ($f) {
            $w = $x['w'];
            $cod = Nomina::codigo($w['contrato']);

            return ($f['q'] === '' || str_contains(mb_strtolower($w['nombre'].' '.$w['nif'].' '.$w['naf']), $f['q']))
                && ($f['ccc'] === '' || preg_replace('/\D/', '', (string) $w['ccc']) === $f['ccc'])
                && ($f['tipo'] === '' || ($f['tipo'] === 'indefinido' && ! Nomina::temporal($w)) || ($f['tipo'] === 'temporal' && Nomina::temporal($w)) || ($f['tipo'] === 'parcial' && Nomina::parcial($w)) || ($f['tipo'] === 'completa' && ! Nomina::parcial($w)))
                && ($f['contrato'] === '' || $cod === $f['contrato'])
                && ($f['cat'] === '' || ($w['convCat'] ?: $w['cat']) === $f['cat'])
                && ($f['grupo'] === '' || (string) $w['grupo'] === $f['grupo']);
        })), $f];
    }

    public function calculo(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'g-calc');
        $todas = $this->noms($c, $b['anio'], $b['mes'], $b['P']);
        [$noms, $f] = $this->filtrar($request, $todas);
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            return $this->csv($request->query('exportar'), 'calculo-nominas-'.$b['anio'].'-'.str_pad((string) $b['mes'], 2, '0', STR_PAD_LEFT), 'Cálculo de nóminas · '.Nomina::MESES[$b['mes'] - 1].' '.$b['anio'],
                ['Trabajador', 'NIF', 'CCC', 'Contrato', 'Categoría', 'Grupo', 'Devengado', 'Base CC', 'SS trabajador', 'IRPF %', 'IRPF', 'Líquido', 'SS empresa', 'Coste empresa'],
                array_map(fn ($x) => [$x['w']['nombre'], $x['w']['nif'], $x['w']['ccc'], Nomina::codigo($x['w']['contrato']), $x['w']['convCat'] ?: $x['w']['cat'], $x['w']['grupo'], Formato::num($x['t']['dev']), Formato::num($x['t']['base']),
                    Formato::num($x['t']['ssT']), Formato::num($x['t']['ip']), Formato::num($x['t']['irpf']), Formato::num($x['t']['liq']), Formato::num($x['t']['ssE']), Formato::num($x['t']['dev'] + $x['t']['ssE'])], $noms));
        }
        $plant = collect($todas)->pluck('w');

        return view('laboral.calculo', $b + ['noms' => $noms, 'f' => $f, 'total' => count($todas),
            'opc' => ['ccc' => $plant->pluck('ccc')->map(fn ($x) => preg_replace('/\D/', '', (string) $x))->filter()->unique()->sort()->values()->all(),
                'cat' => $plant->map(fn ($w) => $w['convCat'] ?: $w['cat'])->filter()->unique()->sort()->values()->all(),
                'contrato' => $plant->map(fn ($w) => $w['contrato'])->unique()->sort()->values()->all(),
                'grupo' => $plant->pluck('grupo')->map(fn ($x) => (string) $x)->unique()->sort()->values()->all()]]);
    }

    /** Todas las nóminas filtradas en un solo documento para imprimir o guardar en PDF */
    public function recibos(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $mes] = $this->periodo($request);
        $P = Nomina::config($c);
        [$noms] = $this->filtrar($request, $this->noms($c, $anio, $mes, $P));
        abort_unless($noms, 404);
        $ult = Carbon::create($anio, $mes, 1)->endOfMonth()->day;
        $mm = str_pad((string) $mes, 2, '0', STR_PAD_LEFT);

        return view('laboral.recibos', ['c' => $c, 'P' => $P, 'noms' => $noms, 'modelo' => (int) $P['modelo'], 'mesL' => Nomina::MESES[$mes - 1].' '.$anio,
            'per' => '01/'.$mm.'/'.$anio.' a '.$ult.'/'.$mm.'/'.$anio, 'at' => (float) $P['at']]);
    }

    // ------------------------------------------------- modelos tributarios
    public function m145(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }

        return view('laboral.m145', $this->base($request, $c, 'm-145') + ['plantilla' => Nomina::asalariados($c)->sortBy(fn ($w) => ($w['estado'] === 'Alta' ? 0 : 1).$w['nombre'])->values()]);
    }

    /** Percepciones y retenciones del trabajo de un año (o un trimestre) a partir de las nóminas */
    private function anual(Cliente $c, int $anio, array $meses, array $P): array
    {
        $svc = app(Nomina::class);
        $plant = Nomina::plantilla($c); // también los del RETA: sus retribuciones llevan retención
        $inc = Nomina::incidenciasAnio($c, $anio);
        $porMes = [];
        $porTrab = [];
        foreach ($meses as $m) {
            $porMes[$m] = ['perc' => 0, 'ret' => 0, 'n' => 0];
            foreach ($plant as $w) {
                if (! Nomina::activo($w, $anio, $m)) {
                    continue;
                }
                $n = $svc->recibosMes($w, $anio, $m, $inc, (float) $P['at'])['t'];
                $porMes[$m]['perc'] += $n['baseIrpf'];
                $porMes[$m]['ret'] += $n['irpf'];
                $porMes[$m]['n']++;
                $t = &$porTrab[$w['id']];
                $t ??= ['w' => $w, 'perc' => 0, 'ret' => 0, 'dietas' => 0, 'ss' => 0, 'meses' => 0];
                $t['perc'] += $n['baseIrpf'];
                $t['ret'] += $n['irpf'];
                $t['dietas'] += $n['die'] + $n['km'];
                $t['ss'] += $n['ssT'];
                $t['meses']++;
                unset($t);
            }
        }

        return [$porMes, array_values($porTrab)];
    }

    public function m111(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'm-111');
        $t = max(1, min(4, (int) $request->query('t', (int) ceil($b['mes'] / 3))));
        $meses = range($t * 3 - 2, $t * 3);
        [$porMes, $porTrab] = $this->anual($c, $b['anio'], $meses, $b['P']);

        return view('laboral.m111', $b + ['t' => $t, 'porMes' => $porMes, 'porTrab' => $porTrab]);
    }

    public function m190(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'm-190');
        [, $porTrab] = $this->anual($c, $b['anio'], range(1, 12), $b['P']);
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            return $this->csv($request->query('exportar'), 'modelo190-'.$b['anio'], 'Modelo 190 · resumen anual de retenciones del trabajo · '.$b['anio'],
                ['NIF', 'Apellidos y nombre', 'Provincia', 'Clave', 'Percepción íntegra', 'Retenciones', 'Dietas exceptuadas', 'Gastos deducibles (SS)', 'Año de nacimiento', 'Situación familiar', 'NIF del cónyuge', 'Discapacidad', 'Contrato', 'Movilidad geográfica', 'Descendientes', 'Ascendientes'],
                array_map(fn ($x) => [$x['w']['nif'], $x['w']['nombre'], $x['w']['prov'], 'A', Formato::num($x['perc']), Formato::num($x['ret']), Formato::num($x['dietas']), Formato::num($x['ss']),
                    substr((string) $x['w']['fnac'], 0, 4), $x['w']['m145']['sit'] ?? '3', $x['w']['m145']['nifCony'] ?? '', $x['w']['disc'], Nomina::temporal($x['w']) ? 'Temporal' : 'Indefinido',
                    ! empty($x['w']['m145']['movil']) ? 'Sí' : 'No', count($x['w']['m145']['hijos'] ?? []), count($x['w']['m145']['asc'] ?? [])], $porTrab));
        }

        return view('laboral.m190', $b + ['porTrab' => $porTrab]);
    }

    // -------------------------------------------------- parámetros de contrato
    public function contratos(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'parc');
        $cod = (string) $request->query('c', $b['P']['hab'][0] ?? '100');
        if (! in_array($cod, array_map(fn ($x) => Nomina::codigo($x), Nomina::CONTRATOS), true)) {
            $cod = '100';
        }

        return view('laboral.contratos', $b + ['cod' => $cod, 'texto' => Nomina::plantillaDe($b['P'], $cod), 'propio' => trim((string) ($b['P']['contratos'][$cod]['texto'] ?? '')) !== '',
            'clausCod' => Nomina::clausulasDe($b['P'], $cod), 'todas' => Nomina::clausulas($b['P'])]);
    }

    public function guardarContratos(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $P = Nomina::config($c);
        $codigos = array_map(fn ($x) => Nomina::codigo($x), Nomina::CONTRATOS);
        $cod = (string) $request->input('c');
        abort_unless(in_array($cod, $codigos, true), 422);
        // Cláusulas propias del despacho para esta empresa
        $extra = [];
        foreach ((array) $request->input('extra', []) as $x) {
            if (! is_array($x) || trim((string) ($x['l'] ?? '')) === '' || trim((string) ($x['txt'] ?? '')) === '') {
                continue;
            }
            $k = preg_match('/^x[a-z0-9]{6,12}$/', (string) ($x['k'] ?? '')) ? $x['k'] : 'x'.strtolower(Str::random(8));
            $extra[] = ['k' => $k, 'l' => mb_substr(trim($x['l']), 0, 120), 'txt' => mb_substr(trim($x['txt']), 0, 3000)];
        }
        $validas = array_merge(array_keys(Nomina::CLAUSULAS), array_column($extra, 'k'));
        $contratos = $P['contratos'];
        $texto = str_replace("\r\n", "\n", trim((string) $request->input('texto', '')));
        if (mb_strlen($texto) > 20000) {
            return back()->withInput()->withErrors(['ficha' => 'El texto del contrato es demasiado largo (máximo 20.000 caracteres).']);
        }
        $contratos[$cod] = ['claus' => array_values(array_intersect($validas, (array) $request->input('claus', []))),
            'texto' => $request->boolean('restablecer') || $texto === Nomina::PLANTILLA_CONTRATO ? '' : $texto];
        // Limpia de las modalidades las cláusulas propias eliminadas
        foreach ($contratos as $k => $v) {
            $contratos[$k]['claus'] = array_values(array_intersect($validas, $v['claus'] ?? []));
        }
        $this->guardarConfig($c, ['contratos' => $contratos, 'clausExtra' => $extra,
            'hab' => array_values(array_intersect($codigos, (array) $request->input('hab', []))),
            'claus' => array_values(array_intersect($validas, (array) $request->input('clausDef', $P['claus'])))]);
        Auditoria::registrar('laboral.contratos', 'Parámetros de contrato ('.$cod.') de '.$c->nombre.' guardados', null, $c->id);

        return redirect()->route('laboral.contratos', ['c' => $cod])->with('ok', 'Parámetros del contrato '.Nomina::contratoCompleto($cod).' guardados.');
    }

    /** Texto del contrato de un trabajador: parte de la plantilla de su modalidad y se puede cambiar */
    public function textoContrato(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        $b = $this->base($request, $c, 'trab');
        $w = Nomina::rellenar($trabajador->ficha(), $c);
        $propio = trim((string) ($w['contratoTexto'] ?? '')) !== '';

        return view('laboral.texto', $b + ['t' => $trabajador, 'w' => $w, 'propio' => $propio,
            'texto' => $propio ? $w['contratoTexto'] : Nomina::plantillaDe($b['P'], Nomina::codigo($w['contrato']))]);
    }

    public function guardarTextoContrato(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        $d = $trabajador->datos;
        $texto = str_replace("\r\n", "\n", trim((string) $request->input('texto', '')));
        if (mb_strlen($texto) > 20000) {
            return back()->withInput()->withErrors(['ficha' => 'El texto del contrato es demasiado largo (máximo 20.000 caracteres).']);
        }
        $P = Nomina::config($c);
        $d['contratoTexto'] = $request->boolean('restablecer') || $texto === Nomina::plantillaDe($P, Nomina::codigo((string) $d['contrato'])) ? '' : $texto;
        $trabajador->update(['datos' => $d]);
        Auditoria::registrar('laboral.contrato', 'Texto del contrato de '.($d['nombre'] ?? '').' '.($d['contratoTexto'] ? 'modificado' : 'restablecido'), $trabajador, $c->id);

        return redirect()->route('laboral.contrato.texto', $trabajador->id)->with('ok', $d['contratoTexto'] ? 'Texto del contrato de '.($d['nombre'] ?? '').' guardado.' : 'Se usa de nuevo la plantilla de su modalidad.');
    }
}
