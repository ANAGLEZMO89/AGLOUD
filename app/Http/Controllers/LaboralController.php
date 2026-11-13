<?php

namespace App\Http\Controllers;

use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\JornadaAusencia;
use App\Models\LaboralConfig;
use App\Models\LaboralHora;
use App\Models\LaboralMes;
use App\Models\LaboralMovimiento;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Models\TrabajadorCondicion;
use App\Services\Auditoria;
use App\Services\Calendario;
use App\Services\Contabilidad;
use App\Services\Nomina;
use App\Support\Formato;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
            'docs' => collect($d['docs'] ?? [])->reverse()->values()->all(), 'historico' => $t ? Calendario::historico($t->id) : collect()]);
    }

    /** Nombre de los campos con fecha de efecto */
    public const CAMPOS_TXT = ['salTipo' => 'la forma de retribución', 'salBase' => 'el salario base', 'salDia' => 'el salario diario', 'plus' => 'los complementos', 'jor' => 'la jornada',
        'horas' => 'las horas semanales', 'convCat' => 'la categoría', 'cat' => 'la categoría', 'grupo' => 'el grupo de cotización', 'contrato' => 'el contrato', 'pagas' => 'las pagas',
        'prorr' => 'el prorrateo de pagas', 'diasSemana' => 'los días de trabajo', 'diasExtra' => 'los días por paga extra'];

    /** Campos de la ficha enviados en el formulario */
    private function leerFicha(array $in, array $d, array $P): array
    {
        $txt = ['nom1', 'ape1', 'ape2', 'tipoDoc', 'nif', 'fnac', 'sexo', 'nac', 'ecivil', 'dom', 'cp', 'mun', 'prov', 'tel', 'email', 'nivForm', 'iban', 'disc',
            'naf', 'ccc', 'altaSS', 'cno', 'epig', 'relEsp', 'contrato', 'alta', 'fAntig', 'fin', 'causa', 'sustituido', 'horas', 'horario', 'centro', 'convCat', 'prueba', 'vac', 'irpfModo',
            'autMutua', 'autTarifaFin', 'iae'];
        $o = [];
        foreach ($txt as $k) {
            if (array_key_exists($k, $in)) {
                $o[$k] = mb_substr(trim((string) $in[$k]), 0, $k === 'causa' ? 600 : 160);
            }
        }
        foreach (['jor', 'salBase', 'salDia', 'plus', 'irpf', 'autBase', 'autCuota', 'autRend'] as $k) {
            if (array_key_exists($k, $in)) {
                $o[$k] = round(Formato::parseNum((string) $in[$k]), 2);
            }
        }
        $o['regimen'] = ($in['regimen'] ?? ($d['regimen'] ?? 'General')) === 'Autónomo' ? 'Autónomo' : 'General';
        if ($o['regimen'] === 'Autónomo' && ! empty($in['autAlta'])) {
            $o['alta'] = mb_substr(trim((string) $in['autAlta']), 0, 10);
        }
        if (array_key_exists('autTipo', $in)) {
            $o['autTipo'] = in_array($in['autTipo'], Nomina::TIPOS_AUTONOMO, true) ? $in['autTipo'] : Nomina::TIPOS_AUTONOMO[0];
        }
        if (array_key_exists('_aut', $in)) {
            $o['autCese'] = ! empty($in['autCese']);
            $o['autTarifa'] = ! empty($in['autTarifa']);
        }
        $o['grupo'] = max(1, min(11, (int) ($in['grupo'] ?? $d['grupo'])));
        // Solo categorías que existen en el convenio (o la que ya tenía guardada, que la comprobación marcará como error)
        if (array_key_exists('convCat', $o) && $o['convCat'] !== '' && $o['convCat'] !== ($d['convCat'] ?? '') && ! in_array($o['convCat'], array_column($P['conv']['cats'] ?? [], 'cat'), true)) {
            $o['convCat'] = '';
        }
        if (array_key_exists('salTipo', $in)) {
            $o['salTipo'] = $in['salTipo'] === 'Diario' ? 'Diario' : 'Mensual';
            $o['diasExtra'] = max(0, min(60, (int) ($in['diasExtra'] ?? 30)));
        }
        if (array_key_exists('_dias', $in)) {
            $o['diasSemana'] = Calendario::diasSemana(['diasSemana' => (array) ($in['diasSemana'] ?? [])]);
        }
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
        $aut = Nomina::esAutonomo($d);
        $err = $aut ? ($nombre === '' ? 'Indica el nombre y los apellidos.'
            : (! Nomina::dni($d['nif'], $d['tipoDoc']) ? 'El documento de identidad no es válido (revisa la letra).'
            : (! preg_match('/^\d{12}$/', $d['naf']) ? 'El número de afiliación debe tener 12 dígitos.'
            : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['alta']) ? 'Indica la fecha de alta en el RETA.'
            : ($d['autBase'] < 0 || $d['autCuota'] < 0 ? 'La base y la cuota no pueden ser negativas.'
            : ($d['autTarifa'] && $d['autTarifaFin'] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['autTarifaFin']) ? 'Revisa la fecha de fin de la tarifa plana.' : ''))))))
            : ($nombre === '' ? 'Indica el nombre y los apellidos.'
            : (! Nomina::dni($d['nif'], $d['tipoDoc']) ? 'El documento de identidad no es válido (revisa la letra).'
            : (! preg_match('/^\d{12}$/', $d['naf']) ? 'El número de afiliación debe tener 12 dígitos.'
            : (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d['alta']) ? 'Indica la fecha de inicio del contrato.'
            : ($anual <= 0 ? 'Indica el salario.'
            : ($temp && ! $d['fin'] ? 'Los contratos temporales necesitan fecha de fin.'
            : ($temp && mb_strlen(trim($d['causa'])) < 20 ? 'Describe la causa de la temporalidad (art. 15 ET).'
            : ($d['irpfModo'] === 'fijo' && ($d['irpf'] < 0 || $d['irpf'] > 47) ? 'Revisa el tipo fijo de IRPF.'
            : ($d['iban'] !== '' && ! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $d['iban']) ? 'Revisa el IBAN para la nómina.' : '')))))))));
        if ($err) {
            // Vuelve al apartado donde está el dato que falta, con el aviso bien visible
            $secErr = match (true) {
                str_contains($err, 'afiliación') => 'afil',
                str_contains($err, 'salario') => 'retrib',
                str_contains($err, 'IRPF') => 'irpf',
                str_contains($err, 'RETA') || str_contains($err, 'tarifa') || str_contains($err, 'cuota') => 'aut',
                str_contains($err, 'contrato') || str_contains($err, 'temporal') => 'contrato',
                default => 'personal',
            };

            return redirect()->route('laboral.ficha', [$t ? $t->id : 'nuevo', 'sec' => $secErr])->withInput()
                ->withErrors(['ficha' => 'No se ha guardado la ficha: '.mb_strtolower(mb_substr($err, 0, 1)).mb_substr($err, 1).' El resto de datos sigue rellenado: corrígelo y vuelve a pulsar «Guardar ficha».']);
        }
        if (! $temp || $aut) {
            $d['fin'] = '';
        }
        if ($d['irpfModo'] !== 'fijo') {
            $d['irpfModo'] = 'auto';
        }
        $d['nombre'] = $nombre;
        $d['cat'] = $d['convCat'] ?: $d['cat'];
        $d['sal'] = $anual;
        unset($d['id'], $d['estado'], $d['baja']);
        $cols = ['alta' => $d['alta'], 'contrato' => $aut ? 'AUT' : Nomina::codigo($d['contrato'])];
        if (! $t) {
            $d['docs'] = [];
            $t = Trabajador::create(['cliente_id' => $c->id, 'estado' => 'Alta', 'datos' => $d] + $cols);
            LaboralMovimiento::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'fecha' => $d['alta'], 'tipo' => 'Alta', 'detalle' => $aut ? 'Alta en el RETA · '.$d['autTipo'] : $d['contrato'], 'created_by' => $request->user()->id]);
            Auditoria::registrar('laboral.alta', 'Alta del trabajador '.$nombre.' en '.$c->nombre, $t, $c->id);

            return $aut
                ? redirect()->route('laboral.ficha', [$t->id, 'sec' => 'aut'])->with('ok', $nombre.' dado de alta como autónomo. Recuerda tramitar el alta en el RETA (Importass o Sistema RED) antes del inicio de la actividad.')
                : redirect()->route('laboral.ficha', [$t->id, 'sec' => 'ss'])->with('ok', $nombre.' dado de alta. Comunica el alta a la TGSS antes del inicio y el contrato al SEPE en 10 días.');
        }
        // Variación de afiliación solo si cambian datos que conoce la TGSS
        $cambia = collect($aut ? ['naf', 'nif', 'regimen', 'autBase', 'autTipo', 'autMutua'] : ['naf', 'ccc', 'grupo', 'cno', 'epig', 'contrato', 'jor', 'relEsp', 'nif', 'regimen'])->contains(fn ($k) => (string) ($prev[$k] ?? '') !== (string) ($d[$k] ?? ''));
        foreach (['concFijos', 'dedFijas', 'embargos', 'docs'] as $k) {
            if (isset($t->datos[$k]) && ! isset($d[$k])) {
                $d[$k] = $t->datos[$k];
            }
        }
        // Cambios de condiciones (salario, jornada, categoría, contrato…): histórico con fecha de efecto, sin reescribir los meses anteriores
        $norm = fn ($v) => is_array($v) ? json_encode(array_values($v)) : (is_bool($v) ? ($v ? '1' : '') : (is_numeric($v) ? (string) (float) $v : (string) $v));
        $prev['diasSemana'] = Calendario::diasSemana($prev);
        $d['diasSemana'] = Calendario::diasSemana($d);
        $camb = array_values(array_filter(Calendario::CAMPOS_CONDICION, fn ($k) => $norm($prev[$k] ?? '') !== $norm($d[$k] ?? '')));
        $condTxt = '';
        if ($camb && ! $aut) {
            $primero = fn ($k) => collect((array) $request->input($k, []))->map(fn ($x) => trim((string) $x))->first(fn ($x) => $x !== '') ?? '';
            $efectos = $primero('efectos');
            $correccion = collect((array) $request->input('correccion', []))->filter()->isNotEmpty();
            $motivo = mb_substr($primero('motivo'), 0, 255);
            $snap = fn ($x) => array_intersect_key($x, array_flip(Calendario::CAMPOS_CONDICION));
            $hist = Calendario::historico($t->id);
            if (! $correccion && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $efectos)) {
                return redirect()->route('laboral.ficha', [$t->id, 'sec' => $request->input('_sec', 'retrib')])->withInput()
                    ->withErrors(['ficha' => 'Has cambiado '.implode(', ', array_map(fn ($k) => self::CAMPOS_TXT[$k] ?? $k, $camb)).': indica la fecha de efecto del cambio (o marca que es una corrección de un dato mal grabado). No se ha guardado la ficha.']);
            }
            if (! $correccion && $efectos > (string) $prev['alta']) {
                if ($hist->isEmpty()) {
                    TrabajadorCondicion::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'efectos_desde' => $prev['alta'], 'datos' => $snap($prev), 'motivo' => 'Condiciones iniciales', 'user_id' => $request->user()->id]);
                }
                TrabajadorCondicion::updateOrCreate(['trabajador_id' => $t->id, 'efectos_desde' => $efectos], ['cliente_id' => $c->id, 'datos' => $snap($d), 'motivo' => $motivo ?: null, 'user_id' => $request->user()->id]);
                $emit = NominaMes::where('trabajador_id', $t->id)->where('estado', 'Emitida')->get()->filter(fn ($g) => sprintf('%04d-%02d-31', $g->anio, $g->mes) >= $efectos)->count();
                $condTxt = ' Cambio de condiciones con efectos desde el '.Formato::fecha($efectos).'.'.($emit ? ' Hay '.$emit.' '.($emit === 1 ? 'nómina emitida' : 'nóminas emitidas').' desde esa fecha que no cambian: reábrelas o haz un recibo de atrasos si procede.' : '');
            } elseif ($vig = $hist->filter(fn ($x) => $x->efectos_desde->toDateString() <= Plazos::hoy()->toDateString())->last()) {
                // Corrección: se corrige el tramo vigente, no se crea un cambio
                $vig->update(['datos' => array_replace($vig->datos ?? [], $snap($d)), 'user_id' => $request->user()->id, 'motivo' => trim(($vig->motivo ?? '').' · corregido'.($motivo ? ': '.$motivo : ''), ' ·')]);
                $condTxt = ' Dato corregido en las condiciones vigentes.';
            }
            Calendario::olvidar();
            Auditoria::registrar('laboral.condiciones', 'Condiciones de '.$nombre.($correccion ? ' corregidas' : ' cambiadas desde '.$efectos).': '.implode(', ', $camb).($motivo ? ' · '.$motivo : ''), $t, $c->id);
        }
        $t->update(['datos' => $d] + $cols);
        if ($cambia) {
            LaboralMovimiento::create(['cliente_id' => $c->id, 'trabajador_id' => $t->id, 'fecha' => Plazos::hoy()->toDateString(), 'tipo' => 'Variación', 'detalle' => 'Variación de datos', 'created_by' => $request->user()->id]);
        }
        Auditoria::registrar('laboral.ficha', 'Ficha de '.$nombre.' guardada', $t, $c->id);

        return redirect()->route('laboral.ficha', [$t->id, 'sec' => $request->input('_sec', 'personal')])
            ->with('ok', 'Ficha de '.$nombre.' guardada.'.$condTxt.($cambia ? ' La variación queda pendiente de comunicar a la TGSS.' : ''));
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
                array_map(fn ($x) => [$x['w']['nombre'], $x['w']['nif'], Formato::num($x['t']['dev']), Formato::num($x['t']['base']), Formato::num($x['t']['ssT']), Formato::num($x['t']['irpf']), Formato::num($x['t']['liq']), Formato::num($x['t']['ssE'])], $noms));
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
        $ed = null;
        if ($sel) {
            $w = $sel['w'];
            $recibo = array_key_exists((string) $request->query('recibo'), Nomina::RECIBOS) ? (string) $request->query('recibo') : 'Nómina';
            $g = Nomina::guardada((int) $w['id'], $b['anio'], $b['mes'], $recibo);
            $doc = $g ? $g->datos + ['recibo' => $recibo] : ($recibo === 'Nómina'
                ? Nomina::conceptos($w, $b['mes'], $inc[$b['mes']][$w['id']] ?? [], $b['P'], $b['anio'], Nomina::guardada((int) $w['id'], $b['anio'], $b['mes'], 'Extra') !== null) + ['recibo' => 'Nómina']
                : Nomina::conceptosRecibo($w, $recibo, $b['anio'], $b['mes'], $b['P']));
            $sel['n'] = $this->nomina->nomina($w, $b['anio'], $b['mes'], $inc, (float) $b['P']['at'], $recibo);
            $ed = ['g' => $g, 'doc' => $doc, 'avisos' => Nomina::avisos($w, $doc, (float) $ir['tipo']), 'recibo' => $recibo,
                'recibos' => Nomina::otrosRecibos((int) $w['id'], $b['anio'], $b['mes']), 'embargos' => $w['embargos'] ?? [],
                'tab' => in_array($request->query('vista'), ['borrador', 'variables', 'calendario', 'convenio', 'nominas'], true) ? $request->query('vista') : 'borrador',
                'cat' => collect($b['P']['conv']['cats'])->firstWhere('cat', $w['convCat'] ?? ''),
                'anio' => collect(range(1, 12))->filter(fn ($x) => Nomina::activo($w, $b['anio'], $x))->map(fn ($x) => ['m' => $x, 'n' => $this->nomina->nomina($w, $b['anio'], $x, $inc, (float) $b['P']['at'])])->values()->all()];
        }

        if ($ed && $ed['tab'] === 'calendario') {
            $wP = Nomina::paraPeriodo($sel['w'], $b['anio'], $b['mes']);
            $cal = Calendario::mes($wP, $b['anio'], $b['mes']);
            $ini = sprintf('%04d-%02d-01', $b['anio'], $b['mes']);
            $fin = Carbon::parse($ini)->endOfMonth()->toDateString();
            $ed['cal'] = ['cal' => $cal, 'w' => $wP,
                'ausencias' => JornadaAusencia::where('trabajador_id', $wP['id'])->whereDate('desde', '<=', $fin)->whereDate('hasta', '>=', $ini)->orderBy('desde')->get(),
                'horas' => LaboralHora::where('trabajador_id', $wP['id'])->whereBetween('fecha', [$ini, $fin])->orderBy('fecha')->get(),
                'exceso' => Calendario::excesoFichajes($wP, $cal),
                'conceptosHora' => collect($wP['_conv']['pluses'] ?? [])->where('unidad', 'hora')->pluck('nombre')->values()->all(),
                'festivos' => collect(Plazos::festivos($b['anio'], $c->id))->filter(fn ($n, $f) => str_starts_with($f, substr($ini, 0, 7)))->all()];
        }

        return view('laboral.integral', $b + ['plantilla' => $plantilla, 'noms' => $noms, 'asiento' => $asiento, 'sel' => $sel, 'idx' => $idx, 'ir' => $ir, 'ed' => $ed,
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
        // Variables escritas a mano: concepto, tipo e importe
        $lin = [];
        foreach ((array) $request->input('vl', []) as $x) {
            if (! is_array($x) || trim((string) ($x['concepto'] ?? '')) === '') {
                continue;
            }
            $imp = round(Formato::parseNum((string) ($x['importe'] ?? '')), 2);
            if ($imp == 0) {
                continue;
            }
            $cod = collect(Nomina::CATALOGO)->first(fn ($k) => $k[1] === trim($x['concepto']))[0] ?? '';
            $lin[] = ['concepto' => mb_substr(trim($x['concepto']), 0, 120), 'importe' => $imp, 'cod' => $cod,
                'clase' => array_key_exists($x['clase'] ?? '', Nomina::CLASES) ? $x['clase'] : 'salarial'];
        }
        if ($lin) {
            $v['lineas'] = $lin;
        }
        $all[$trabajador->id] = $v;
        $m->incidencias = $all;
        $m->save();

        return redirect()->route('laboral.integral', ['anio' => $anio, 'mes' => $mes, 't' => $trabajador->id, 'vista' => 'variables'])->with('ok', 'Variables de '.Nomina::MESES[$mes - 1].' guardadas para '.($trabajador->datos['nombre'] ?? '').'.');
    }

    /** Editor de la nómina (o de un recibo aparte) del mes: guardar, emitir, reabrir, recalcular o eliminar */
    public function guardarNomina(Request $request, Trabajador $trabajador)
    {
        $c = $this->ctx($request);
        abort_unless($c && $trabajador->cliente_id === $c->id, 404);
        [$anio, $mes] = [(int) $request->input('anio'), (int) $request->input('mes')];
        abort_unless($anio >= 2000 && $mes >= 1 && $mes <= 12, 422);
        $recibo = array_key_exists((string) $request->input('recibo'), Nomina::RECIBOS) ? (string) $request->input('recibo') : 'Nómina';
        $rec = $recibo === 'Nómina' ? 'Nómina' : 'Recibo de '.mb_strtolower(Nomina::RECIBOS[$recibo]);
        $volver = redirect()->route('laboral.integral', ['anio' => $anio, 'mes' => $mes, 't' => $trabajador->id] + ($recibo !== 'Nómina' ? ['recibo' => $recibo] : []));
        $lm = LaboralMes::where('cliente_id', $c->id)->where('anio', $anio)->where('mes', $mes)->first();
        if ($lm?->asiento_id && Asiento::find($lm->asiento_id)) {
            return $volver->withErrors(['ficha' => 'Las nóminas de este mes ya están contabilizadas. Borra el asiento en Contabilidad para cambiarlas.']);
        }
        $w = Nomina::rellenar($trabajador->ficha(), $c);
        abort_unless(Nomina::activo($w, $anio, $mes), 404);
        $P = Nomina::config($c);
        $g = NominaMes::where('trabajador_id', $trabajador->id)->where('anio', $anio)->where('mes', $mes)->where('recibo', $recibo)->first();
        $accion = (string) $request->input('accion', 'guardar');
        $nombre = $w['nombre'];
        $que = $rec.' de '.$nombre.' de '.Nomina::MESES[$mes - 1].' '.$anio;
        if ($accion === 'reabrir') {
            $g?->update(['estado' => 'Borrador', 'emitida_at' => null, 'updated_by' => $request->user()->id]);
            Auditoria::registrar('laboral.nomina', $que.' reabierta', $trabajador, $c->id);
            Nomina::olvidarGuardadas();

            return $volver->with('ok', $rec.' reabierta: puedes volver a modificarla.');
        }
        if ($g?->emitida()) {
            return $volver->withErrors(['ficha' => $rec.' ya está emitida. Pulsa «Reabrir» para modificarla.']);
        }
        if ($accion === 'recalcular' || $accion === 'eliminar') {
            $g?->delete();
            Nomina::olvidarGuardadas();
            Auditoria::registrar('laboral.nomina', $que.($accion === 'eliminar' ? ' eliminada' : ' recalculada desde el convenio'), $trabajador, $c->id);

            return $accion === 'eliminar'
                ? redirect()->route('laboral.integral', ['anio' => $anio, 'mes' => $mes, 't' => $trabajador->id])->with('ok', $rec.' eliminado.')
                : $volver->with('ok', $rec.' calculada de nuevo desde el convenio y la ficha del trabajador.');
        }
        $num = fn ($v) => round(Formato::parseNum((string) $v), 2);
        $datos = $trabajador->datos ?? [];
        // Embargos: levantar los marcados y dar de alta el nuevo
        $embs = $datos['embargos'] ?? [];
        foreach ((array) $request->input('emb_lev', []) as $ref) {
            foreach ($embs as &$e) {
                if ($e['id'] === $ref) {
                    $e['levantado'] = Plazos::hoy()->toDateString();
                }
            }
            unset($e);
        }
        $ne = (array) $request->input('emb_new', []);
        if (trim((string) ($ne['total'] ?? '')) !== '' || trim((string) ($ne['expediente'] ?? '')) !== '') {
            $tot = $num($ne['total'] ?? 0);
            $modo = ($ne['modo'] ?? 'lec') === 'fijo' ? 'fijo' : 'lec';
            $cuota = $num($ne['cuota'] ?? 0);
            $org = in_array($ne['organismo'] ?? '', Nomina::ORGANISMOS_EMBARGO, true) ? $ne['organismo'] : null;
            $err = ! $org ? 'Elige el organismo que ordena el embargo.' : ($tot <= 0 ? 'Indica el importe total que hay que retener.' : ($modo === 'fijo' && $cuota <= 0 ? 'Indica la cantidad fija mensual del embargo.' : ''));
            if ($err) {
                return $volver->withInput()->withErrors(['ficha' => $err]);
            }
            $embs[] = ['id' => Str::lower(Str::random(8)), 'organismo' => $org, 'expediente' => mb_substr(trim((string) ($ne['expediente'] ?? '')), 0, 60), 'total' => $tot,
                'modo' => $modo, 'cuota' => $modo === 'fijo' ? $cuota : 0, 'desde' => sprintf('%04d-%02d', $anio, $mes), 'alta' => Plazos::hoy()->toDateString()];
        }
        $datos['embargos'] = array_values($embs);
        $w['embargos'] = $datos['embargos'];
        // Conceptos del formulario; la referencia del convenio no se toma del formulario: se recalcula
        $incM = Nomina::incidenciasAnio($c, $anio)[$mes][$trabajador->id] ?? [];
        $base = $recibo === 'Nómina' ? Nomina::conceptos($w, $mes, $incM, $P, $anio) : Nomina::conceptosRecibo($w, $recibo, $anio, $mes, $P);
        $auto = collect($base['lineas'])->keyBy('id');
        $L = [];
        $linea = function (array $x, ?array $a, bool $nueva) use (&$L, $num) {
            $cant = trim((string) ($x['cant'] ?? '')) !== '' ? $num($x['cant']) : null;
            $precio = trim((string) ($x['precio'] ?? '')) !== '' ? round(Formato::parseNum((string) $x['precio']), 4) : null;
            $imp = $cant && $precio ? round($cant * $precio, 2) : $num($x['importe'] ?? 0);
            $clase = array_key_exists($x['clase'] ?? '', Nomina::CLASES) ? $x['clase'] : 'salarial';
            $fijo = ! empty($x['fijo']);
            $origen = $a ? $a['origen'] : 'manual';
            if ($origen === 'fijo' && ! $fijo) {
                $origen = 'manual';
            }
            $cod = mb_substr((string) ($x['cod'] ?? ''), 0, 4);
            if ($cod === '') {
                $cod = collect(Nomina::CATALOGO)->first(fn ($k) => $k[1] === trim($x['concepto']))[0] ?? '';
            }
            $L[] = ['id' => $nueva || ! ($x['id'] ?? '') ? 'm'.count($L).Str::lower(Str::random(3)) : (string) $x['id'], 'cod' => $cod, 'concepto' => mb_substr(trim($x['concepto']), 0, 120),
                'cant' => $cant, 'precio' => $precio, 'importe' => $imp, 'clase' => $clase,
                'cobro' => $clase === 'extra' ? (($x['cobro'] ?? '') === 'prorrateada' ? 'prorrateada' : 'en_su_mes') : null,
                'origen' => $origen, 'conv' => $a['conv'] ?? null, 'activo' => $nueva || ! empty($x['activo']), 'fijo' => $fijo];
        };
        foreach ((array) $request->input('l', []) as $x) {
            if (is_array($x) && trim((string) ($x['concepto'] ?? '')) !== '') {
                $linea($x, $auto->get((string) ($x['id'] ?? '')), false);
            }
        }
        $nl = (array) $request->input('nl', []);
        foreach (isset($nl['concepto']) ? [$nl] : $nl as $x) {
            if (is_array($x) && trim((string) ($x['concepto'] ?? '')) !== '') {
                $linea($x, null, true);
            }
        }
        // Deducciones: los embargos los calcula el motor; el resto, las del formulario
        $D = [];
        $vivos = collect(Nomina::embargosActivos($w, $anio, $mes))->keyBy('ref');
        foreach ((array) $request->input('d', []) as $x) {
            if (! is_array($x)) {
                continue;
            }
            if (! empty($x['ref'])) {
                if ($vivos->has($x['ref'])) {
                    $D[] = $vivos->pull($x['ref']);
                }

                continue;
            }
            if (trim((string) ($x['concepto'] ?? '')) !== '') {
                $D[] = ['id' => in_array($x['id'] ?? '', ['ant', 'emb'], true) ? $x['id'] : 'od', 'concepto' => mb_substr(trim($x['concepto']), 0, 120), 'importe' => $num($x['importe'] ?? 0), 'fijo' => ! empty($x['fijo'])];
            }
        }
        foreach ($vivos as $e) {
            $D[] = $e; // embargo nuevo
        }
        $nd = (array) $request->input('nd', []);
        foreach (isset($nd['concepto']) ? [$nd] : $nd as $x) {
            if (is_array($x) && trim((string) ($x['concepto'] ?? '')) !== '') {
                $D[] = ['id' => 'od', 'concepto' => mb_substr(trim($x['concepto']), 0, 120), 'importe' => $num($x['importe'] ?? 0), 'fijo' => ! empty($x['fijo'])];
            }
        }
        foreach (array_merge($L, $D) as $x) {
            if ((float) ($x['importe'] ?? 0) < 0 && ($x['clase'] ?? '') !== 'salarial') {
                return $volver->withInput()->withErrors(['ficha' => 'Los importes no pueden ser negativos (salvo un ajuste salarial).']);
            }
        }
        $tipos = [];
        foreach (['des_t', 'irpf'] as $k) {
            $v = trim((string) $request->input('tipos.'.$k, ''));
            if ($v !== '') {
                $n = Formato::parseNum($v);
                if ($n < 0 || $n > 60) {
                    return $volver->withInput()->withErrors(['ficha' => 'Revisa los tipos de desempleo e IRPF.']);
                }
                $tipos[$k] = round($n, 2);
            }
        }
        // Lo marcado como «Todos los meses» pasa a la ficha del trabajador y sale solo en las nóminas siguientes
        if ($recibo === 'Nómina') {
            $datos['concFijos'] = array_values(array_map(fn ($l) => ['cod' => $l['cod'], 'concepto' => $l['concepto'], 'clase' => $l['clase'], 'importe' => $l['importe'], 'cant' => $l['cant'], 'precio' => $l['precio']],
                array_filter($L, fn ($l) => $l['fijo'] && $l['activo'])));
            $datos['dedFijas'] = array_values(array_map(fn ($d) => ['concepto' => $d['concepto'], 'importe' => $d['importe']], array_filter($D, fn ($d) => ! empty($d['fijo']) && empty($d['ref']))));
        }
        if ($datos !== ($trabajador->datos ?? [])) {
            $trabajador->update(['datos' => $datos]);
        }
        $inc = Nomina::incidenciasAnio($c, $anio);
        $ipAuto = (float) $this->nomina->tipoIrpf($w, $anio, $mes, $inc)['tipo'];
        [$pd, $ph, $pdias, $pmes] = Nomina::periodo($w, $anio, $mes);
        $doc = ['lineas' => $L, 'deducciones' => $D, 'tipos' => $tipos, 'recibo' => $recibo, 'dias' => $pdias, 'diasMes' => $pmes, 'desde' => $pd, 'hasta' => $ph];
        if ($recibo === 'Nómina') {
            // Lo que sale del calendario (cotización de los días de IT y sus avisos) se recalcula siempre: no se edita a mano
            $auto = Nomina::conceptos($w, $mes, $inc[$mes][$trabajador->id] ?? [], $P, $anio);
            $doc += ['itCot' => $auto['itCot'], 'calendario' => $auto['calendario'], 'avisosCal' => $auto['avisosCal']];
            $errCal = array_values(array_filter($auto['avisosCal'], fn ($x) => $x[0] === 'error'));
            if ($accion === 'emitir' && $errCal) {
                return $volver->withErrors(['ficha' => 'No se puede emitir: '.implode(' ', array_column($errCal, 1))]);
            }
        }
        $calc = Nomina::computar(Nomina::paraPeriodo($w, $anio, $mes), $doc, isset($tipos['irpf']) ? (float) $tipos['irpf'] : $ipAuto, (float) $P['at']);
        $doc['retenido'] = collect($calc['deducciones'])->filter(fn ($d) => ! empty($d['ref']))->mapWithKeys(fn ($d) => [$d['ref'] => $d['importe']])->all();
        $fp = (string) $request->input('fecha_pago');
        $g ??= new NominaMes(['cliente_id' => $c->id, 'trabajador_id' => $trabajador->id, 'anio' => $anio, 'mes' => $mes, 'recibo' => $recibo]);
        $g->datos = $doc;
        $g->fecha_pago = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fp) ? $fp : null;
        $g->updated_by = $request->user()->id;
        if ($accion === 'emitir') {
            $g->estado = 'Emitida';
            $g->emitida_at = now();
            // Foto del cálculo emitido: no cambia aunque después se edite el convenio, la ficha o los parámetros
            $g->datos = $doc + ['calc' => $calc];
        }
        $g->save();
        Nomina::olvidarGuardadas();
        $avisos = Nomina::avisos($w, $doc, $ipAuto);
        $nAv = count($avisos['lineas']) + count($avisos['generales']);
        Auditoria::registrar('laboral.nomina', $que.($accion === 'emitir' ? ' emitida' : ' guardada').($nAv ? ' con '.$nAv.' conceptos fuera de convenio' : ''), $trabajador, $c->id);

        return $volver->with('ok', ($accion === 'emitir' ? $rec.' emitida.' : ($recibo === 'Nómina' ? 'Borrador guardado.' : $rec.' guardado.')).($nAv ? ' Atención: '.$nAv.' '.($nAv === 1 ? 'concepto no es' : 'conceptos no son').' por convenio.' : ''));
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
        $recibo = array_key_exists((string) $request->query('recibo'), Nomina::RECIBOS) ? (string) $request->query('recibo') : 'Nómina';
        $n = $this->nomina->nomina($w, $anio, $mes, Nomina::incidenciasAnio($c, $anio), (float) $P['at'], $recibo);
        $g = Nomina::guardada((int) $trabajador->id, $anio, $mes, $recibo);
        $ult = Carbon::create($anio, $mes, 1)->endOfMonth()->day;
        $mm = str_pad((string) $mes, 2, '0', STR_PAD_LEFT);

        return view('laboral.recibo', ['c' => $c, 'w' => $w, 'n' => $n, 'P' => $P, 'modelo' => in_array((int) $request->query('modelo'), [1, 2, 3, 4], true) ? (int) $request->query('modelo') : (int) $P['modelo'],
            'mesL' => Nomina::MESES[$mes - 1].' '.$anio, 'per' => '01/'.$mm.'/'.$anio.' a '.$ult.'/'.$mm.'/'.$anio, 'at' => (float) $P['at'],
            'ccc' => ($w['ccc'] ?? '') ?: ($P['cccs'][0] ?? ''), 'auto' => $request->boolean('imprimir'), 'recibo' => $recibo,
            'fechaPago' => $g?->fecha_pago?->toDateString() ?? Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString()]);
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
        $dev = $s(fn ($x) => $x['t']['dev']);
        $sse = $s(fn ($x) => $x['t']['ssE']);
        $sst = $s(fn ($x) => $x['t']['ssT']);
        $irpf = $s(fn ($x) => $x['t']['irpf']);
        $ant = $s(fn ($x) => $x['t']['ant']);
        $emb = $s(fn ($x) => $x['t']['emb']);
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
        // Los trabajadores del RETA no van a la liquidación del Régimen General
        $noms = array_values(array_filter($this->nomina->mes($c, $b['anio'], $b['mes'], null, null, (float) $b['P']['at']), fn ($x) => ! Nomina::esAutonomo($x['w'])));
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
        Nomina::olvidarReglas();
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
            'modelo' => in_array((int) $request->input('modelo'), [1, 2, 3, 4], true) ? (int) $request->input('modelo') : 1,
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
