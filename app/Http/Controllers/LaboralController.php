<?php

namespace App\Http\Controllers;

use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\LaboralConfig;
use App\Models\LaboralMes;
use App\Models\LaboralMovimiento;
use App\Models\Trabajador;
use App\Services\Auditoria;
use App\Services\Contabilidad;
use App\Services\Nomina;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Laboral y SS (prototipo): plantilla y ficha del trabajador, integral de nóminas,
 * seguros sociales, altas y bajas y parámetros de laboral de la empresa elegida.
 * No envía ningún fichero a la TGSS ni al SEPE: abre la sede oficial y registra lo hecho.
 */
class LaboralController extends Controller
{
    public function __construct(private Nomina $nomina) {}

    protected function ctx(Request $r): ?Cliente
    {
        return $r->attributes->get('ctx');
    }

    protected function sinEmpresa(): RedirectResponse
    {
        return redirect()->route('escritorio', ['cambiar' => 1, 'volver' => '/laboral'])->with('aviso', 'Elige primero la empresa cuyas nóminas y seguros sociales vas a gestionar.');
    }

    protected function periodo(Request $r): array
    {
        $hoy = Plazos::hoy();
        $anio = (int) $r->query('anio', $hoy->year);
        $mes = (int) $r->query('mes', $anio === $hoy->year ? $hoy->month : 12);

        return [max(2000, min(2100, $anio)), max(1, min(12, $mes))];
    }

    /** Datos comunes de todas las pestañas */
    protected function base(Request $r, Cliente $c, string $tab): array
    {
        [$anio, $mes] = $this->periodo($r);
        $P = Nomina::config($c);

        return ['titulo' => 'Laboral y SS', 'activo' => 'laboral', 'c' => $c, 'P' => $P, 'tab' => $tab, 'anio' => $anio, 'mes' => $mes, 'redOk' => Nomina::redOk($P), 'sepeOk' => Nomina::sepeOk($P),
            'con' => in_array($r->query('con'), ['red', 'sepe'], true) ? $r->query('con') : null,
            'pendMov' => LaboralMovimiento::where('cliente_id', $c->id)->where('estado', 'Pendiente')->count()];
    }

    protected function trabajador(Cliente $c, int $id): Trabajador
    {
        return Trabajador::where('cliente_id', $c->id)->findOrFail($id);
    }

    // ------------------------------------------------------------ trabajadores
    public function index(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'trab');

        return view('laboral.trabajadores', $b + ['plantilla' => Nomina::plantilla($c)]);
    }

    public function ficha(Request $request, string $id)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'trab');
        $P = $b['P'];
        $hoy = Plazos::hoy()->toDateString();
        if ($id === 'nuevo') {
            $ct = $P['conv']['cats'][0] ?? null;
            $hab0 = $P['hab'][0] ?? '100';
            $d = Nomina::rellenar(['alta' => $hoy, 'altaSS' => $hoy, 'ccc' => $P['cccs'][0] ?? '', 'contrato' => Nomina::contratoCompleto($hab0), 'claus' => Nomina::clausulasDe($P, $hab0), 'vac' => $P['conv']['vacaciones'] ?: '30',
                'pagas' => (int) ($P['conv']['pagas'] ?: 14) === 12 ? 12 : 14,
                'salBase' => $ct ? (float) $ct['base'] : 0, 'plus' => $ct ? (float) ($ct['plus'] ?? 0) : 0, 'grupo' => $ct ? (int) $ct['grupo'] : 7, 'convCat' => $ct['cat'] ?? ''], $c);
            $t = null;
        } else {
            $t = $this->trabajador($c, (int) $id);
            $d = Nomina::rellenar($t->ficha(), $c);
        }
        if ($old = $request->old('_ficha')) {
            $d = array_replace($d, $this->leerFicha($request->old(), $d, $P));
        }
        $n = Trabajador::where('cliente_id', $c->id)->count();
        $incAnio = Nomina::incidenciasAnio($c, (int) substr($hoy, 0, 4));

        return view('laboral.ficha', $b + ['t' => $t, 'd' => $d, 'nuevo' => ! $t, 'sec' => (string) $request->query('sec', 'personal'),
            'legal' => Nomina::comprobar($d, $P, $n, $hoy), 'ir' => ($d['irpfModo'] ?? 'auto') === 'fijo' ? null : Nomina::previsionIrpf($d, (int) substr($hoy, 0, 4), $incAnio),
            'centros' => collect($c->seccion('centros'))->map(fn ($x) => trim(($x['denom'] ?? '').' · '.($x['mun'] ?? ''), ' ·'))->filter()->values()->all(),
            'docs' => collect($d['docs'] ?? [])->reverse()->values()->all()]);
    }

    /** Campos de la ficha enviados en el formulario */
    private function leerFicha(array $in, array $d, array $P): array
    {
        $txt = ['nom1', 'ape1', 'ape2', 'tipoDoc', 'nif', 'fnac', 'sexo', 'nac', 'ecivil', 'dom', 'cp', 'mun', 'prov', 'tel', 'email', 'nivForm', 'iban', 'disc',
            'naf', 'ccc', 'altaSS', 'cno', 'epig', 'relEsp', 'contrato', 'alta', 'fin', 'causa', 'sustituido', 'horas', 'horario', 'centro', 'convCat', 'prueba', 'vac', 'irpfModo'];
        $o = [];
        foreach ($txt as $k) {
            if (array_key_exists($k, $in)) {
                $o[$k] = mb_substr(trim((string) $in[$k]), 0, $k === 'causa' ? 600 : 160);
            }
        }
        foreach (['jor', 'salBase', 'plus', 'irpf'] as $k) {
            if (array_key_exists($k, $in)) {
                $o[$k] = round(Formato::parseNum((string) $in[$k]), 2);
            }
        }
        $o['grupo'] = max(1, min(7, (int) ($in['grupo'] ?? $d['grupo'])));
        $o['pagas'] = (int) ($in['pagas'] ?? 14) === 12 ? 12 : 14;
        $o['prorr'] = ! empty($in['prorr']);
        $o['contrato'] = Nomina::contratoCompleto(Nomina::codigo($o['contrato'] ?? $d['contrato']));
        $o['claus'] = array_values(array_intersect(array_keys(Nomina::clausulas($P)), (array) ($in['claus'] ?? [])));
        $o['agrario'] = ! empty($in['agrario']);
        $m = (array) ($in['m145'] ?? []);
        $disc = ['No', '33 % a 64 %', '65 % o más'];
        $o['m145'] = ['sit' => in_array((string) ($m['sit'] ?? '3'), ['1', '2', '3'], true) ? (string) ($m['sit'] ?? '3') : '3', 'nifCony' => mb_substr(strtoupper(trim((string) ($m['nifCony'] ?? ''))), 0, 12),
            'movil' => ! empty($m['movil']), 'vivienda' => ! empty($m['vivienda']), 'pension' => mb_substr((string) ($m['pension'] ?? ''), 0, 20), 'alimentos' => mb_substr((string) ($m['alimentos'] ?? ''), 0, 20),
            'fecha' => (string) ($m['fecha'] ?? ''),
            'hijos' => collect((array) ($m['hijos'] ?? []))->filter(fn ($h) => is_array($h) && trim((string) ($h['anio'] ?? '')) !== '')
                ->map(fn ($h) => ['anio' => substr(preg_replace('/\D/', '', (string) $h['anio']), 0, 4), 'entero' => ! empty($h['entero']), 'disc' => in_array($h['disc'] ?? 'No', $disc, true) ? $h['disc'] : 'No'])->values()->all(),
            'asc' => collect((array) ($m['asc'] ?? []))->filter(fn ($h) => is_array($h) && trim((string) ($h['anio'] ?? '')) !== '')
                ->map(fn ($h) => ['anio' => substr(preg_replace('/\D/', '', (string) $h['anio']), 0, 4), 'conv' => max(1, (int) ($h['conv'] ?? 1)), 'disc' => in_array($h['disc'] ?? 'No', $disc, true) ? $h['disc'] : 'No'])->values()->all()];

        return $o;
    }

    public function guardar(Request $request, string $id)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $t = $id === 'nuevo' ? null : $this->trabajador($c, (int) $id);
        $prev = $t ? Nomina::rellenar($t->ficha(), $c) : Nomina::rellenar([], $c);
        $P = Nomina::config($c);
        $d = array_replace($prev, $this->leerFicha($request->all(), $prev, $P));
        $d['nif'] = strtoupper(preg_replace('/\s/', '', (string) $d['nif']));
        $d['naf'] = preg_replace('/\D/', '', (string) $d['naf']);
        $d['iban'] = strtoupper(preg_replace('/\s/', '', (string) $d['iban']));
        $nombre = trim(implode(' ', array_filter([trim($d['nom1']), trim($d['ape1']), trim($d['ape2'])])));
        $anual = Nomina::anual($d);
        $temp = Nomina::temporal($d);
        $err = $nombre === '' ? 'Indica el nombre y los apellidos.'
            : (! Nomina::dni($d['nif'], $d['tipoDoc']) ? 'El documento de identidad no es válido (revisa la letra).'
            : (! preg_match('/^\d{12}$/', $d['naf']) ? 'El número de afiliación debe tener 12 dígitos.'
            : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['alta']) ? 'Indica la fecha de inicio del contrato.'
            : ($anual <= 0 ? 'Indica el salario.'
            : ($temp && ! $d['fin'] ? 'Los contratos temporales necesitan fecha de fin.'
            : ($temp && mb_strlen(trim($d['causa'])) < 20 ? 'Describe la causa de la temporalidad (art. 15 ET).'
            : ($d['irpfModo'] === 'fijo' && ($d['irpf'] < 0 || $d['irpf'] > 47) ? 'Revisa el tipo fijo de IRPF.'
            : ($d['iban'] !== '' && ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $d['iban']) ? 'Revisa el IBAN para la nómina.' : ''))))))));
        if ($err) {
            return back()->withInput()->withErrors(['ficha' => $err]);
        }
        if (! $temp) {
            $d['fin'] = '';
        }
        if ($d['irpfModo'] !== 'fijo') {
            $d['irpfModo'] = 'auto';
        }
        $d['nombre'] = $nombre;
        $d['cat'] = $d['convCat'] ?: $d['cat'];
        $d['sal'] = $anual;
        unset($d['id'], $d['estado'], $d['baja']);
        $cols = ['alta' => $d['alta'], 'contrato' => Nomina::codigo($d['contrato'])];
        if (! $t) {
            $d['docs'] = [];
            $t = Trabajador::create(['cliente_id' => $c->id, 'estado' => 'Alta', 'datos' => $d] + $cols);
            LaboralMovimiento::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'fecha' => $d['alta'], 'tipo' => 'Alta', 'detalle' => $d['contrato'], 'created_by' => $request->user()->id]);
            Auditoria::registrar('laboral.alta', 'Alta del trabajador '.$nombre.' en '.$c->nombre, $t, $c->id);

            return redirect()->route('laboral.ficha', [$t->id, 'sec' => 'ss'])->with('ok', $nombre.' dado de alta. Comunica el alta a la TGSS antes del inicio y el contrato al SEPE en 10 días.');
        }
        // Variación de afiliación solo si cambian datos que conoce la TGSS
        $cambia = collect(['naf', 'ccc', 'grupo', 'cno', 'epig', 'contrato', 'jor', 'relEsp', 'nif'])->contains(fn ($k) => (string) ($prev[$k] ?? '') !== (string) ($d[$k] ?? ''));
        $t->update(['datos' => $d] + $cols);
        if ($cambia) {
            LaboralMovimiento::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'fecha' => Plazos::hoy()->toDateString(), 'tipo' => 'Variación', 'detalle' => 'Variación de datos', 'created_by' => $request->user()->id]);
        }
        Auditoria::registrar('laboral.ficha', 'Ficha de '.$nombre.' guardada', $t, $c->id);

        return redirect()->route('laboral.ficha', [$t->id, 'sec' => $request->input('_sec', 'personal')])
            ->with('ok', 'Ficha de '.$nombre.' guardada.'.($cambia ? ' La variación queda pendiente de comunicar a la TGSS.' : ''));
    }

    public function eliminar(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id && $request->user()->esAdmin(), 403);
        $nombre = $trabajador->datos['nombre'] ?? 'Trabajador';
        $trabajador->delete();
        Auditoria::registrar('laboral.eliminar', 'Trabajador '.$nombre.' eliminado de '.$c->nombre, null, $c->id);

        return redirect()->route('laboral.index')->with('ok', $nombre.' eliminado de la plantilla.');
    }

    /** Acciones de la sección «Seguridad Social y SEPE»: se abre la sede y se registra lo hecho */
    public function accion(Request $request, Trabajador $trabajador, string $k)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        $L = ['idc' => 'IDC solicitado', 'ta' => 'Resolución de alta (TA) solicitada', 'alta' => 'Alta comunicada', 'var' => 'Variación de datos comunicada', 'contrata' => 'Contrato comunicado a Contrat@', 'cert' => 'Certificado de empresa enviado a Certific@2'];
        abort_unless(isset($L[$k]), 404);
        $P = Nomina::config($c);
        $isRed = in_array($k, ['idc', 'ta', 'alta', 'var'], true);
        $volver = $request->input('volver') === 'comunica' ? redirect()->route('laboral.comunica') : redirect()->route('laboral.ficha', [$trabajador->id, 'sec' => 'ss']);
        if (! ($isRed ? Nomina::redOk($P) : Nomina::sepeOk($P))) {
            return $volver->withErrors(['ficha' => 'Configura la conexión '.($isRed ? 'con el Sistema RED' : 'con el SEPE').' en Parámetros de laboral (autorizado, correo y certificado digital).']);
        }
        if ($k === 'cert' && ! $trabajador->baja) {
            return $volver->withErrors(['ficha' => 'El certificado de empresa se emite tras la baja del trabajador.']);
        }
        $this->registrarDoc($trabajador, $k, $L[$k]);
        if (in_array($k, ['alta', 'var'], true)) {
            LaboralMovimiento::where('trabajador_id', $trabajador->id)->where('estado', 'Pendiente')->whereIn('tipo', $k === 'alta' ? ['Alta'] : ['Variación'])
                ->update(['estado' => 'Comunicado', 'comunicado_at' => now()]);
        }
        $d = $trabajador->datos;
        Auditoria::registrar('laboral.'.$k, $L[$k].' · '.($d['nombre'] ?? ''), $trabajador, $c->id);

        return $volver->with('ok', $L[$k].' · '.($d['nombre'] ?? '').' (NAF '.($d['naf'] ?? '').', CCC '.(($d['ccc'] ?? '') ?: ($P['cccs'][0] ?? '—')).'). Se ha abierto la sede con el autorizado '.($isRed ? $P['red']['aut'] : $P['sepe']['aut']).'.');
    }

    private function registrarDoc(Trabajador $t, string $k, string $l): void
    {
        $d = $t->datos;
        $d['docs'] = array_merge($d['docs'] ?? [], [['k' => $k, 'l' => $l, 'fecha' => Plazos::hoy()->toDateString()]]);
        $t->update(['datos' => $d]);
    }

    /** Documentos imprimibles: contrato, copia básica, comunicación, modelo 145 y certificado de empresa */
    public function documento(Request $request, Trabajador $trabajador, string $tipo)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        abort_unless(in_array($tipo, ['contrato', 'copia', 'comunicacion', 'm145', 'certificado'], true), 404);
        $w = Nomina::rellenar($trabajador->ficha(), $c);
        $P = Nomina::config($c);
        $extra = null;
        if ($tipo === 'certificado') {
            if (! $trabajador->baja) {
                return redirect()->route('laboral.ficha', [$trabajador->id, 'sec' => 'ss'])->withErrors(['ficha' => 'El certificado de empresa se emite tras la baja del trabajador.']);
            }
            $anio = (int) $trabajador->baja->year;
            $bm = (int) $trabajador->baja->month;
            $inc = Nomina::incidenciasAnio($c, $anio);
            $bases = [];
            foreach (range(max(1, $bm - 5), $bm) as $x) {
                $bases[] = [Nomina::MESES[$x - 1].' '.$anio, $this->nomina->nomina($w, $anio, $x, $inc, (float) $P['at'])['base']];
            }
            $mv = LaboralMovimiento::where('trabajador_id', $trabajador->id)->where('tipo', 'Baja')->latest('fecha')->first();
            $extra = ['causa' => $mv?->detalle, 'bases' => $bases];
        }
        if ($tipo === 'comunicacion') {
            $com = collect($w['docs'] ?? [])->firstWhere('k', 'contrata');
            $extra = $com ? 'Comunicado a Contrat@ el '.Formato::fecha($com['fecha']) : 'Pendiente de comunicar';
        }

        return view('laboral.documento', ['c' => $c, 'w' => $w, 'P' => $P, 'tipo' => $tipo, 'extra' => $extra]);
    }

    // ---------------------------------------------------------------- nóminas
    public function integral(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'int');
        $plantilla = Nomina::plantilla($c);
        $inc = Nomina::incidenciasAnio($c, $b['anio']);
        $noms = $this->nomina->mes($c, $b['anio'], $b['mes'], $plantilla, $inc, (float) $b['P']['at']);
        $mesRow = LaboralMes::where('cliente_id', $c->id)->where('anio', $b['anio'])->where('mes', $b['mes'])->first();
        $asiento = $mesRow?->asiento_id ? Asiento::find($mesRow->asiento_id) : null;
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            return $this->csv($request->query('exportar'), 'nominas-'.$b['anio'].'-'.str_pad((string) $b['mes'], 2, '0', STR_PAD_LEFT), 'Resumen de nóminas · '.Nomina::MESES[$b['mes'] - 1].' '.$b['anio'],
                ['Trabajador', 'NIF', 'Devengado', 'Base de cotización', 'SS trabajador', 'IRPF', 'Líquido', 'SS empresa'],
                array_map(fn ($x) => [$x['w']['nombre'], $x['w']['nif'], Formato::num($x['n']['dev']), Formato::num($x['n']['base']), Formato::num($x['n']['ssT']), Formato::num($x['n']['irpf']), Formato::num($x['n']['liq']), Formato::num($x['n']['ssE'])], $noms));
        }
        $sel = null;
        $idx = -1;
        if ($tid = (int) $request->query('t')) {
            foreach ($noms as $i => $x) {
                if ((int) $x['w']['id'] === $tid) {
                    [$sel, $idx] = [$x, $i];
                }
            }
        }
        $ir = $sel ? $this->nomina->tipoIrpf($sel['w'], $b['anio'], $b['mes'], $inc) : null;

        return view('laboral.integral', $b + ['plantilla' => $plantilla, 'noms' => $noms, 'asiento' => $asiento, 'sel' => $sel, 'idx' => $idx, 'ir' => $ir,
            'inc' => $sel ? ($inc[$b['mes']][$sel['w']['id']] ?? []) : []]);
    }

    public function incidencias(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $m = LaboralMes::firstOrNew(['cliente_id' => $c->id, 'anio' => $anio, 'mes' => $mes]);
        if ($m->asiento_id && Asiento::find($m->asiento_id)) {
            return back()->withErrors(['ficha' => 'Las nóminas de este mes ya están contabilizadas. Borra el asiento en Contabilidad para cambiar las variables.']);
        }
        $all = $m->incidencias ?? [];
        $v = [];
        foreach (array_keys(Nomina::INCIDENCIAS) as $k) {
            $n = round(Formato::parseNum((string) $request->input($k, '')), 2);
            if ($n < 0) {
                return back()->withErrors(['ficha' => 'Las variables del mes no pueden ser negativas.']);
            }
            if ($n) {
                $v[$k] = $n;
            }
        }
        $all[$trabajador->id] = $v;
        $m->incidencias = $all;
        $m->save();

        return redirect()->route('laboral.integral', ['anio' => $anio, 'mes' => $mes, 't' => $trabajador->id])->with('ok', 'Variables de '.Nomina::MESES[$mes - 1].' guardadas para '.($trabajador->datos['nombre'] ?? '').'.');
    }

    /** Recibo de salarios imprimible (3 modelos) */
    public function recibo(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        [$anio, $mes] = $this->periodo($request);
        $w = Nomina::rellenar($trabajador->ficha(), $c);
        abort_unless(Nomina::activo($w, $anio, $mes), 404);
        $P = Nomina::config($c);
        $n = $this->nomina->nomina($w, $anio, $mes, Nomina::incidenciasAnio($c, $anio), (float) $P['at']);
        $ult = Carbon::create($anio, $mes, 1)->endOfMonth()->day;
        $mm = str_pad((string) $mes, 2, '0', STR_PAD_LEFT);

        return view('laboral.recibo', ['c' => $c, 'w' => $w, 'n' => $n, 'P' => $P, 'modelo' => (int) ($request->query('modelo') ?: $P['modelo']),
            'mesL' => Nomina::MESES[$mes - 1].' '.$anio, 'per' => '01/'.$mm.'/'.$anio.' a '.$ult.'/'.$mm.'/'.$anio, 'at' => (float) $P['at'],
            'ccc' => ($w['ccc'] ?? '') ?: ($P['cccs'][0] ?? ''), 'auto' => $request->boolean('imprimir')]);
    }

    public function contabilizar(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $P = Nomina::config($c);
        $noms = $this->nomina->mes($c, $anio, $mes, null, null, (float) $P['at']);
        $volver = redirect()->route('laboral.integral', ['anio' => $anio, 'mes' => $mes]);
        if (! $noms) {
            return $volver->withErrors(['ficha' => 'No hay trabajadores en alta este mes.']);
        }
        $m = LaboralMes::firstOrNew(['cliente_id' => $c->id, 'anio' => $anio, 'mes' => $mes]);
        if ($m->asiento_id && Asiento::find($m->asiento_id)) {
            return $volver->withErrors(['ficha' => 'Las nóminas de este mes ya están contabilizadas.']);
        }
        $s = fn ($f) => round(array_sum(array_map($f, $noms)), 2);
        $dev = $s(fn ($x) => $x['n']['dev']);
        $sse = $s(fn ($x) => $x['n']['ssE']);
        $sst = $s(fn ($x) => $x['n']['ssT']);
        $irpf = $s(fn ($x) => $x['n']['irpf']);
        $ant = $s(fn ($x) => $x['n']['ant']);
        $emb = $s(fn ($x) => $x['n']['emb']);
        $liq = round($dev - $sst - $irpf - $ant - $emb, 2);
        $cp = Parametros::get('contabilidad');
        $cta = fn ($k, $def) => Contabilidad::a9((string) (($cp[$k] ?? '') ?: $def));
        $lineas = [[$cta('sueldos', '640'), $dev, 0], [$cta('ss_emp', '642'), $sse, 0], [$cta('ss_acr', '476'), 0, round($sse + $sst, 2)], [$cta('acr_irpf_nom', '4751'), 0, $irpf]];
        if ($ant) {
            $lineas[] = [$cta('anticipos', '460'), 0, $ant];
        }
        if ($emb) {
            $lineas[] = [$cta('embargos', '460'), 0, $emb];
        }
        $lineas[] = [$cta('rem_pend', '465'), 0, $liq];
        $mesL = mb_strtolower(Nomina::MESES[$mes - 1]);
        try {
            $m->save();
            $a = app(Contabilidad::class)->asentar($c, Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString(), 'Nóminas '.$mesL.' '.$anio, $lineas, 'Nómina', 'laboral', $m->id, $request->user()->id);
        } catch (InvalidArgumentException $e) {
            return $volver->withErrors(['ficha' => $e->getMessage()]);
        }
        $m->update(['asiento_id' => $a->id]);
        Auditoria::registrar('laboral.contabilizar', 'Nóminas de '.$mesL.' '.$anio.' contabilizadas (asiento '.$a->numero.')', $a, $c->id);

        return $volver->with('ok', 'Asiento de nóminas de '.$mesL.' '.$anio.' creado en contabilidad (nº '.$a->numero.').');
    }

    // -------------------------------------------------------- seguros sociales
    public function seguros(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'ss-cret');
        $noms = $this->nomina->mes($c, $b['anio'], $b['mes'], null, null, (float) $b['P']['at']);
        $mesRow = LaboralMes::where('cliente_id', $c->id)->where('anio', $b['anio'])->where('mes', $b['mes'])->first();
        $ini = Carbon::create($b['anio'], $b['mes'], 1);
        $plazo = $ini->copy()->addMonthNoOverflow()->endOfMonth();
        $hoy = Plazos::hoy();
        $st = $mesRow?->ss_confirmada_at ? ['Confirmada', '#E6F6A8', '#2E3A00'] : ($ini->copy()->endOfMonth()->lt($hoy) ? ['Pendiente', '#FCEBCB', '#6E4300'] : ['Borrador', '#ECECE7', '#3C3C3C']);
        if (in_array($request->query('exportar'), ['excel', 'csv'], true)) {
            return $this->csv($request->query('exportar'), 'rnt-'.$b['anio'].'-'.str_pad((string) $b['mes'], 2, '0', STR_PAD_LEFT), 'Relación nominal de trabajadores (RNT) · '.Nomina::MESES[$b['mes'] - 1].' '.$b['anio'],
                ['NAF', 'Trabajador', 'Contrato', 'Base CC', 'Cuota total'],
                array_map(fn ($x) => [$x['w']['naf'], $x['w']['nombre'], Nomina::codigo($x['w']['contrato']), Formato::num($x['n']['base']), Formato::num($x['n']['ssE'] + $x['n']['ssT'])], $noms));
        }

        return view('laboral.seguros', $b + ['noms' => $noms, 'st' => $st, 'plazo' => $plazo, 'confirmada' => (bool) $mesRow?->ss_confirmada_at,
            'puedeConfirmar' => ! $mesRow?->ss_confirmada_at && $ini->lte($hoy) && $noms]);
    }

    public function at(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $v = Formato::parseNum((string) $request->input('at'));
        if ($v <= 0 || $v >= 10) {
            return back()->withErrors(['ficha' => 'El tipo de AT y EP debe estar entre 0 y 10 %.']);
        }
        $this->guardarConfig($c, ['at' => round($v, 2)]);

        return back()->with('ok', 'Tipo de AT y EP actualizado al '.Formato::num($v).' %.');
    }

    public function confirmar(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $P = Nomina::config($c);
        $volver = redirect()->route('laboral.seguros', ['anio' => $anio, 'mes' => $mes]);
        if (! Nomina::redOk($P)) {
            return $volver->withErrors(['ficha' => 'Configura la conexión con el Sistema RED en Parámetros de laboral.']);
        }
        LaboralMes::updateOrCreate(['cliente_id' => $c->id, 'anio' => $anio, 'mes' => $mes], ['ss_confirmada_at' => now()]);
        Auditoria::registrar('laboral.sld', 'Liquidación de '.Nomina::MESES[$mes - 1].' '.$anio.' confirmada en el SLD', null, $c->id);

        return $volver->with('ok', 'Liquidación de '.Nomina::MESES[$mes - 1].' '.$anio.' confirmada en el SLD para el CCC '.($P['cccs'][0] ?? '—').'.');
    }

    // ---------------------------------------------------------- altas y bajas
    public function movimientos(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'ss-mov');

        return view('laboral.movimientos', $b + ['movs' => LaboralMovimiento::where('cliente_id', $c->id)->with('trabajador')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'activos' => Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->get()->sortBy(fn ($t) => $t->datos['nombre'] ?? '')->values(),
            'bajaOpen' => $request->boolean('baja') || $request->old('_baja')]);
    }

    public function baja(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $w = Trabajador::where('cliente_id', $c->id)->where('estado', 'Alta')->find((int) $request->input('trabajador'));
        $fecha = (string) $request->input('fecha');
        $causa = in_array($request->input('causa'), Nomina::CAUSAS_BAJA, true) ? $request->input('causa') : null;
        $err = ! $w ? 'Elige el trabajador.' : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha < $w->alta->toDateString() ? 'La fecha de baja no puede ser anterior al alta.' : (! $causa ? 'Elige la causa de la baja.' : ''));
        if ($err) {
            return back()->withInput()->withErrors(['ficha' => $err]);
        }
        $w->update(['estado' => 'Baja', 'baja' => $fecha]);
        LaboralMovimiento::create(['cliente_id' => $c->id, 'trabajador_id' => $w->id, 'fecha' => $fecha, 'tipo' => 'Baja', 'detalle' => $causa, 'created_by' => $request->user()->id]);
        $nombre = $w->datos['nombre'] ?? '';
        Auditoria::registrar('laboral.baja', 'Baja de '.$nombre.' ('.$causa.')', $w, $c->id);

        return redirect()->route('laboral.movimientos')->with('ok', 'Baja de '.$nombre.' registrada. Comunícala a la TGSS antes del '.Formato::fecha(Carbon::parse($fecha)->addDays(3)).' y envía el certificado de empresa a Certific@2 desde su ficha.');
    }

    public function comunicar(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $P = Nomina::config($c);
        if (! Nomina::redOk($P)) {
            return back()->withErrors(['ficha' => 'Configura la conexión con el Sistema RED en Parámetros de laboral.']);
        }
        $n = LaboralMovimiento::where('cliente_id', $c->id)->where('estado', 'Pendiente')->update(['estado' => 'Comunicado', 'comunicado_at' => now()]);
        Auditoria::registrar('laboral.comunicar', $n.' movimientos marcados como comunicados a la TGSS', null, $c->id);

        return redirect()->route('laboral.movimientos')->with('ok', $n.' movimientos comunicados a la TGSS por el Sistema RED (autorizado '.$P['red']['aut'].').');
    }

    // -------------------------------------------------------------- parámetros
    public function parametros(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $b = $this->base($request, $c, 'par');

        return view('laboral.parametros', $b + ['logo' => (bool) $c->logo_documento_id, 'firma' => (bool) $c->firma_documento_id]);
    }

    protected function guardarConfig(Cliente $c, array $patch): void
    {
        $cfg = LaboralConfig::firstOrNew(['cliente_id' => $c->id]);
        $cfg->datos = array_replace($cfg->datos ?? [], $patch);
        $cfg->save();
    }

    public function guardarParametros(Request $request)
    {
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $request->validate(['red_cert' => ['nullable', 'file', 'max:64'], 'sepe_cert' => ['nullable', 'file', 'max:64']]);
        $P = Nomina::config($c);
        $s = fn ($k, $max = 160) => mb_substr(trim((string) $request->input($k, '')), 0, $max);
        $certCad = $s('red_certCad', 10);
        if ($certCad !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $certCad)) {
            return back()->withInput()->withErrors(['ficha' => 'Revisa la fecha de caducidad del certificado.']);
        }
        $email = $s('red_email', 120);
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return back()->withInput()->withErrors(['ficha' => 'El correo del autorizado RED no es válido.']);
        }
        // Del certificado solo se guarda el nombre del fichero, como en el diseño (la firma se hace en la sede oficial)
        $redCert = $request->file('red_cert')?->getClientOriginalName() ?? ($request->boolean('red_cert_quitar') ? '' : $P['red']['cert']);
        $sepeCert = $request->file('sepe_cert')?->getClientOriginalName() ?? ($request->boolean('sepe_cert_quitar') ? '' : $P['sepe']['cert']);
        $this->guardarConfig($c, [
            'modelo' => in_array((int) $request->input('modelo'), [1, 2, 3], true) ? (int) $request->input('modelo') : 1,
            'useImg' => $request->boolean('useImg'),
            'red' => ['email' => $email, 'aut' => $s('red_aut', 40), 'cert' => mb_substr(basename((string) $redCert), 0, 120), 'certCad' => $certCad],
            'sepe' => ['aut' => $s('sepe_aut', 60), 'mismoCert' => $request->boolean('sepe_mismoCert'), 'cert' => mb_substr(basename((string) $sepeCert), 0, 120)],
        ]);
        Auditoria::registrar('laboral.parametros', 'Parámetros de laboral de '.$c->nombre.' guardados', null, $c->id);

        return redirect()->route('laboral.parametros')->with('ok', 'Parámetros de laboral de '.$c->nombre.' guardados.');
    }

    // --------------------------------------------------------------- exportar
    protected function csv(string $fmt, string $nombre, string $titulo, array $cab, array $filas)
    {
        $sep = $fmt === 'excel' ? ';' : ',';

        return response()->streamDownload(function () use ($titulo, $cab, $filas, $sep) {
            $o = fopen('php://output', 'w');
            fwrite($o, "\xEF\xBB\xBF");
            fputcsv($o, [$titulo], $sep);
            fputcsv($o, $cab, $sep);
            foreach ($filas as $f) {
                fputcsv($o, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $f), $sep);
            }
            fclose($o);
        }, $nombre.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
