<?php

namespace App\Http\Controllers;

use App\Models\Ajuste;
use App\Models\Cliente;
use App\Models\LaboralMes;
use App\Models\Trabajador;
use App\Services\Auditoria;
use App\Services\ConvenioIA;
use App\Services\Nomina;
use App\Support\Formato;
use App\Support\Plazos;
use App\Support\Validacion;
use Illuminate\Http\Request;
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

        return view('laboral.convenios', $this->base($request, $c, 'g-conv'));
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
                'base' => round(Formato::parseNum((string) ($x['base'] ?? 0)), 2), 'plus' => round(Formato::parseNum((string) ($x['plus'] ?? 0)), 2),
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
        $prevConv = Nomina::config($c)['conv'];
        $this->guardarConfig($c, ['conv' => array_replace($prevConv, ['extras' => $extras, 'antig' => $antig, 'pluses' => $pluses,
            'it' => ['cc' => $tramos($request->input('it_cc', [])), 'at' => $tramos($request->input('it_at', [])), 'art' => $prevConv['it']['art'] ?? ''], 'hx' => $hx,
            'nombre' => $s('conv_nombre', 200), 'codigo' => $s('conv_codigo', 40), 'ambito' => $s('conv_ambito'), 'vigencia' => $s('conv_vigencia', 60),
            'jornada' => $s('conv_jornada', 10), 'vacaciones' => $s('conv_vacaciones', 10), 'pagas' => $extras ? (string) (12 + count($extras)) : (in_array($request->input('conv_pagas'), ['12', '14', '15', '16'], true) ? $request->input('conv_pagas') : '14'), 'cats' => $cats])]);
        $cambios = $this->actualizarPlantilla($c, $cats, (bool) $extras);
        Auditoria::registrar('laboral.convenio', 'Convenio de '.$c->nombre.' guardado', null, $c->id);

        return redirect()->route('laboral.convenios')->with('ok', 'Convenio guardado y aplicado a las nóminas de '.$c->nombre.'.'.($cambios ? ' Salarios actualizados al convenio: '.implode(' · ', $cambios).'.' : ''));
    }

    /** Lee el PDF del convenio con IA y aplica sus tablas y reglas a la empresa y a su plantilla */
    public function leerConvenioIA(Request $request, ConvenioIA $ia)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate(['pdf' => ['required', 'file', 'mimes:pdf', 'max:30000']], ['pdf.mimes' => 'El convenio debe ser un PDF.', 'pdf.max' => 'El PDF no puede pasar de 30 MB.']);
        try {
            $r = $ia->leer((string) file_get_contents($request->file('pdf')->getRealPath()));
        } catch (\Throwable $e) {
            return redirect()->route('laboral.convenios')->withErrors(['conv' => 'No se ha podido leer el convenio: '.$e->getMessage()]);
        }

        return $this->aplicarConvenio($c, $r, $request->file('pdf')->getClientOriginalName());
    }

    /** Lectura gratuita: el convenio se lee en claude.ai con la suscripción del despacho y aquí se pega la respuesta */
    public function pegarConvenioIA(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate(['respuesta' => ['required', 'string', 'max:500000']], ['respuesta.required' => 'Pega la respuesta de Claude.']);
        try {
            $r = ConvenioIA::desdeTexto((string) $request->input('respuesta'));
        } catch (\Throwable $e) {
            return redirect()->route('laboral.convenios', ['ia' => 1])->withInput()->withErrors(['conv' => $e->getMessage()]);
        }

        return $this->aplicarConvenio($c, $r, 'Leído con Claude (claude.ai)');
    }

    private function aplicarConvenio(Cliente $c, array $r, string $archivo)
    {
        if (! $r['cats']) {
            return redirect()->route('laboral.convenios')->withErrors(['conv' => 'No se han encontrado tablas salariales. Comprueba que el PDF es el convenio completo (con sus anexos) y no solo la resolución de publicación.']);
        }
        $P = Nomina::config($c);
        $conv = array_replace($P['conv'], array_filter(['nombre' => $r['nombre'], 'codigo' => $r['codigo'], 'ambito' => $r['ambito'], 'vigencia' => $r['vigencia'],
            'jornada' => $r['jornada'], 'vacaciones' => $r['vacaciones']], fn ($v) => $v !== ''), [
                'cats' => $r['cats'], 'extras' => $r['extras'], 'antig' => $r['antig'], 'pluses' => $r['pluses'], 'it' => $r['it'], 'hx' => $r['hx'],
                'pagas' => (string) (12 + count($r['extras'])),
                'ia' => ['fecha' => now()->toDateTimeString(), 'archivo' => mb_substr($archivo, 0, 160), 'anioTablas' => $r['anioTablas'], 'avisos' => $r['avisos']],
            ]);
        $this->guardarConfig($c, ['conv' => $conv]);

        $cambios = $this->actualizarPlantilla($c, $r['cats'], (bool) $r['extras']);
        Auditoria::registrar('laboral.convenio.ia', 'Convenio leído con IA para '.$c->nombre.': '.count($r['cats']).' categorías, '.count($r['extras']).' pagas extra, '.count($r['pluses']).' pluses', null, $c->id);

        return redirect()->route('laboral.convenios')->with('ok', 'Convenio leído y aplicado: '.count($r['cats']).' categorías, '.count($r['extras']).' pagas extra'
            .($r['antig'] ? ', antigüedad' : '').', '.count($r['pluses']).' pluses.'.($cambios ? ' Salarios actualizados al convenio: '.implode(' · ', $cambios).'.' : ''));
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

    /** Plantilla: nadie cobra por debajo de su categoría del convenio (nunca se baja un salario). Devuelve los cambios. */
    private function actualizarPlantilla(Cliente $c, array $cats, bool $hayExtras): array
    {
        $cambios = [];
        $porCat = collect($cats)->keyBy('cat');
        foreach (Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->get() as $t) {
            $d = $t->datos ?? [];
            $ct = $porCat[$d['convCat'] ?? ''] ?? null;
            if (! $ct || Nomina::esAutonomo($d)) {
                continue;
            }
            $antes = Nomina::anual(Nomina::rellenar($t->ficha(), $c));
            $d['grupo'] = (int) $ct['grupo'];
            if (($ct['unidad'] ?? 'mes') === 'dia') {
                $d['salTipo'] = 'Diario';
                $d['salDia'] = max(Nomina::n($d['salDia'] ?? 0), (float) $ct['base']);
            } else {
                $d['salTipo'] = 'Mensual';
                $d['salBase'] = max(Nomina::n($d['salBase'] ?? 0), (float) $ct['base']);
            }
            $d['plus'] = max(Nomina::n($d['plus'] ?? 0), (float) $ct['plus']);
            $d['pagas'] = $hayExtras ? 14 : (int) ($d['pagas'] ?? 14);
            $t->update(['datos' => $d]);
            $d['sal'] = Nomina::anual(Nomina::rellenar($t->fresh()->ficha(), $c));
            $t->update(['datos' => $d]);
            if (abs($d['sal'] - $antes) > 0.005) {
                $cambios[] = ($d['nombre'] ?? 'Trabajador').': '.Formato::eur($antes).' → '.Formato::eur($d['sal']);
            }
        }

        return $cambios;
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
