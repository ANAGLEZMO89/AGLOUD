<?php

namespace App\Http\Controllers;

use App\Models\Convenio;
use App\Models\ConvenioCategoria;
use App\Models\ConvenioConcepto;
use App\Services\Auditoria;
use App\Services\Convenios;
use App\Services\Formula;
use App\Support\Formato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Editor manual del convenio por secciones (fase 3). Cada sección se guarda por separado, se valida en el servidor
 * y los errores vuelven junto a su campo. Escribe en la versión que se está editando; nunca toca fichas de trabajadores.
 */
class ConvenioEditorController extends LaboralController
{
    public const SECCIONES = ['identificacion' => 'Identificación', 'version' => 'Versión y vigencia', 'categorias' => 'Categorías y tablas', 'conceptos' => 'Conceptos retributivos',
        'pagas' => 'Pagas extraordinarias', 'antiguedad' => 'Antigüedad', 'jornada' => 'Jornada, vacaciones y horas', 'it' => 'Incapacidad temporal', 'contratacion' => 'Contratación'];

    /** Número español («1.234,56») a float; vacío = null (no localizado, nunca cero) */
    private static function num($v): ?float
    {
        $s = trim((string) $v);

        return $s === '' ? null : round(Formato::parseNum($s), 4);
    }

    private static function txt($v, int $max = 500): ?string
    {
        $s = trim((string) $v);

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    public function guardar(Request $request, string $seccion)
    {
        abort_unless(array_key_exists($seccion, self::SECCIONES), 404);
        $c = $this->ctx($request);
        if (! $c) {
            return $this->sinEmpresa();
        }
        $cv = Convenios::deEmpresa($c, $request->user()->id);
        $v = Convenios::editable($cv, $request->integer('version_id') ?: null);
        $volver = fn () => redirect()->to(route('laboral.convenios', ['version' => $v->id]).'#sec-'.$seccion);
        $falla = fn ($validator) => $volver()->withErrors($validator)->withInput()->with('cvSeccion', $seccion);
        $in = $request->all();

        switch ($seccion) {
            case 'identificacion':
                $val = Validator::make($in, ['nombre' => ['required', 'string', 'max:250'], 'codigo' => ['nullable', 'string', 'max:40'], 'url' => ['nullable', 'url', 'max:500'],
                    'fecha_publicacion' => ['nullable', 'date'], 'estado' => ['required', Rule::in(array_keys(Convenio::ESTADOS))]],
                    ['nombre.required' => 'El convenio necesita un nombre.', 'url.url' => 'El enlace no es una dirección web válida.', 'fecha_publicacion.date' => 'Fecha no válida.']);
                if ($val->fails()) {
                    return $falla($val);
                }
                $cv->fill(['nombre' => self::txt($in['nombre'], 250), 'codigo' => self::txt($in['codigo'] ?? '', 40), 'sector' => self::txt($in['sector'] ?? '', 160),
                    'ambito_territorial' => self::txt($in['ambito_territorial'] ?? '', 160), 'ambito_funcional' => self::txt($in['ambito_funcional'] ?? ''), 'ambito_personal' => self::txt($in['ambito_personal'] ?? ''),
                    'publicacion' => self::txt($in['publicacion'] ?? '', 250), 'url' => self::txt($in['url'] ?? ''), 'fecha_publicacion' => self::txt($in['fecha_publicacion'] ?? '', 10),
                    'estado' => $in['estado'], 'observaciones' => self::txt($in['observaciones'] ?? '', 5000), 'no_soportado' => self::txt($in['no_soportado'] ?? '', 5000)])->save();
                break;

            case 'version':
                $val = Validator::make($in, ['nombre' => ['required', 'string', 'max:160'], 'efectos_desde' => ['nullable', 'date'], 'efectos_hasta' => ['nullable', 'date', 'after_or_equal:efectos_desde'],
                    'vigencia_desde' => ['nullable', 'date'], 'vigencia_hasta' => ['nullable', 'date', 'after_or_equal:vigencia_desde'], 'estado' => ['required', Rule::in(array_keys(Convenio::ESTADOS))]],
                    ['nombre.required' => 'Pon un nombre a la versión.', 'efectos_hasta.after_or_equal' => 'No puede ser anterior al inicio de efectos.', 'vigencia_hasta.after_or_equal' => 'No puede ser anterior al inicio de la vigencia.']);
                if ($val->fails()) {
                    return $falla($val);
                }
                if ($in['estado'] === 'aprobado' && ! ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->whereNotNull('salario_base')->exists()) {
                    $val->errors()->add('estado', 'No se puede aprobar una versión sin categorías con salario.');

                    return $falla($val);
                }
                $v->fill(['nombre' => self::txt($in['nombre'], 160), 'efectos_desde' => self::txt($in['efectos_desde'] ?? '', 10), 'efectos_hasta' => self::txt($in['efectos_hasta'] ?? '', 10),
                    'vigencia_desde' => self::txt($in['vigencia_desde'] ?? '', 10), 'vigencia_hasta' => self::txt($in['vigencia_hasta'] ?? '', 10)]);
                if ($in['estado'] !== $v->estado) {
                    $v->estado = $in['estado'];
                    if ($v->estado === 'aprobado') {
                        $v->aprobada_por = $request->user()->id;
                        $v->aprobada_at = now();
                    }
                }
                $v->save();
                break;

            case 'categorias':
                $filas = array_values(array_filter((array) ($in['cats'] ?? []), 'is_array'));
                foreach ($filas as &$f) {
                    $f['nombre'] = trim((string) ($f['nombre'] ?? ''));
                    $f['salario_base'] = self::num($f['salario_base'] ?? '');
                    $f['plus'] = self::num($f['plus'] ?? '') ?? 0.0;
                }
                unset($f);
                $val = Validator::make(['cats' => $filas], ['cats.*.nombre' => ['required', 'max:160', 'distinct'], 'cats.*.grupo_cotizacion' => ['nullable', 'integer', 'between:1,11'],
                    'cats.*.unidad' => ['required', Rule::in(['mes', 'dia'])], 'cats.*.salario_base' => ['nullable', 'numeric', 'min:0'], 'cats.*.plus' => ['numeric', 'min:0']],
                    ['cats.*.nombre.required' => 'Falta el nombre de la categoría.', 'cats.*.nombre.distinct' => 'Categoría repetida.', 'cats.*.grupo_cotizacion.between' => 'Grupo de 1 a 11.',
                        'cats.*.salario_base.min' => 'No puede ser negativo.']);
                if ($val->fails()) {
                    return $falla($val);
                }
                Convenios::guardar($v, ['cats' => array_map(fn ($f) => ['cat' => $f['nombre'], 'codigo' => $f['codigo'] ?? '', 'grupo_profesional' => $f['grupo_profesional'] ?? '', 'nivel' => $f['nivel'] ?? '',
                    'grupo' => (string) ($f['grupo_cotizacion'] ?? ''), 'unidad' => $f['unidad'], 'base' => $f['salario_base'], 'plus' => $f['plus'], 'art' => $f['referencia'] ?? ''], $filas)]);
                break;

            case 'conceptos':
                $filas = array_values(array_filter((array) ($in['conceptos'] ?? []), 'is_array'));
                $catIds = ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->pluck('id')->all();
                $limpio = [];
                foreach ($filas as $f) {
                    $imp = [];
                    foreach ((array) ($f['importes'] ?? []) as $cid => $x) {
                        if (in_array((int) $cid, $catIds, true) && ($n = self::num($x)) !== null) {
                            $imp[(int) $cid] = $n;
                        }
                    }
                    $limpio[] = ['nombre' => trim((string) ($f['nombre'] ?? '')), 'codigo' => self::txt($f['codigo'] ?? '', 20), 'descripcion' => self::txt($f['descripcion'] ?? ''), 'cra' => self::txt($f['cra'] ?? '', 10),
                        'naturaleza' => (string) ($f['naturaleza'] ?? ''), 'calculo' => (string) ($f['calculo'] ?? ''), 'unidad' => (string) ($f['unidad'] ?? ''), 'valor' => self::num($f['valor'] ?? ''),
                        'base' => self::txt($f['base'] ?? '', 30), 'formula' => self::txt($f['formula'] ?? ''), 'automatico' => ! empty($f['automatico']), 'prop_jornada' => ! empty($f['prop_jornada']),
                        'prop_periodo' => ! empty($f['prop_periodo']), 'en_pagas' => ! empty($f['en_pagas']), 'cotiza' => (string) ($f['cotiza'] ?? ''), 'tributa' => (string) ($f['tributa'] ?? ''),
                        'limite' => self::txt($f['limite'] ?? '', 250), 'limite_importe' => self::num($f['limite_importe'] ?? ''), 'limite_unidad' => self::txt($f['limite_unidad'] ?? '', 4), 'redondeo' => (string) ($f['redondeo'] ?? '2d'), 'referencia' => self::txt($f['referencia'] ?? '', 120), 'importes' => $imp];
                }
                $val = Validator::make(['conceptos' => $limpio], [
                    'conceptos.*.nombre' => ['required', 'max:120', 'distinct', Rule::notIn([Convenios::PLUS_CONVENIO])],
                    'conceptos.*.naturaleza' => ['required', Rule::in(array_keys(ConvenioConcepto::NATURALEZAS))],
                    'conceptos.*.calculo' => ['required', Rule::in(['fijo', 'porcentaje', 'formula'])],
                    'conceptos.*.unidad' => ['required', Rule::in(array_keys(ConvenioConcepto::UNIDADES))],
                    'conceptos.*.valor' => ['nullable', 'numeric'],
                    'conceptos.*.base' => ['nullable', 'required_if:conceptos.*.calculo,porcentaje', Rule::in(['salario_base', 'salario_base_plus'])],
                    'conceptos.*.formula' => ['nullable', 'required_if:conceptos.*.calculo,formula'],
                    'conceptos.*.cotiza' => ['required', Rule::in(['si', 'no', 'limite'])], 'conceptos.*.tributa' => ['required', Rule::in(['si', 'no', 'limite'])],
                    'conceptos.*.redondeo' => ['required', Rule::in(['2d', '0d'])],
                    'conceptos.*.codigo' => ['nullable', 'distinct:ignore_case', 'regex:/^[A-Za-z0-9_]+$/'],
                    'conceptos.*.limite_importe' => ['nullable', 'numeric', 'min:0'], 'conceptos.*.limite_unidad' => ['nullable', Rule::in(['dia', 'mes'])],
                ], ['conceptos.*.nombre.required' => 'Falta el nombre.', 'conceptos.*.nombre.distinct' => 'Concepto repetido.', 'conceptos.*.nombre.not_in' => 'El plus de convenio se edita en las categorías.',
                    'conceptos.*.base.required_if' => 'Un porcentaje necesita su base.', 'conceptos.*.formula.required_if' => 'Escribe la fórmula.',
                    'conceptos.*.codigo.distinct' => 'Código repetido.', 'conceptos.*.codigo.regex' => 'Solo letras, números y _ (se usa en las fórmulas como c_CÓDIGO).']);
                $val->after(function ($vv) use ($limpio) {
                    foreach ($limpio as $i => $f) {
                        if ($f['calculo'] !== 'formula' && $f['valor'] === null && ! $f['importes']) {
                            $vv->errors()->add("conceptos.$i.valor", 'Indica el importe general o el de cada categoría (vacío = no localizado).');
                        }
                        if (($f['cotiza'] === 'limite' || $f['tributa'] === 'limite') && ($f['limite_importe'] === null || ! $f['limite_unidad'])) {
                            $vv->errors()->add("conceptos.$i.limite_importe", 'Indica el importe del límite y si es por día o por mes.');
                        }
                        if ($f['calculo'] === 'formula' && $f['formula'] && ($e = Formula::validar($f['formula'], array_values(array_filter(array_column($limpio, 'codigo')))))) {
                            $vv->errors()->add("conceptos.$i.formula", $e);
                        }
                    }
                    // Las fórmulas no pueden llamarse en círculo
                    $form = [];
                    foreach ($limpio as $f) {
                        if ($f['calculo'] === 'formula' && $f['codigo'] && $f['formula']) {
                            $form[$f['codigo']] = $f['formula'];
                        }
                    }
                    if ($ciclo = Formula::orden($form)[1]) {
                        foreach ($limpio as $i => $f) {
                            if ($f['calculo'] === 'formula' && $f['codigo'] && str_contains($ciclo, strtoupper($f['codigo']))) {
                                $vv->errors()->add("conceptos.$i.formula", $ciclo);
                            }
                        }
                    }
                });
                if ($val->fails()) {
                    return $falla($val);
                }
                Convenios::guardarConceptos($v, $limpio);
                break;

            case 'pagas':
                $filas = array_values(array_filter((array) ($in['extras'] ?? []), 'is_array'));
                $limpio = array_map(fn ($x) => ['nombre' => trim((string) ($x['nombre'] ?? '')), 'mes' => (int) ($x['mes'] ?? 0), 'calculo' => (string) ($x['calculo'] ?? ''),
                    'dias' => self::num($x['dias'] ?? ''), 'importe' => self::num($x['importe'] ?? ''), 'plus' => ! empty($x['plus']), 'antig' => ! empty($x['antig']),
                    'devengo' => ['tipo' => (string) ($x['devengo_tipo'] ?? 'anual'), 'desde' => (int) ($x['devengo_desde'] ?? 0) ?: null, 'hasta' => (int) ($x['devengo_hasta'] ?? 0) ?: null],
                    'conceptos' => self::txt($x['conceptos'] ?? '', 300), 'art' => self::txt($x['referencia'] ?? '', 120)], $filas);
                $val = Validator::make(['extras' => $limpio], ['extras.*.nombre' => ['required', 'max:80', 'distinct'], 'extras.*.mes' => ['integer', 'between:1,12'],
                    'extras.*.calculo' => ['required', Rule::in(['mensualidad', 'dias', 'importe'])], 'extras.*.dias' => ['nullable', 'required_if:extras.*.calculo,dias', 'numeric', 'min:0'],
                    'extras.*.importe' => ['nullable', 'required_if:extras.*.calculo,importe', 'numeric', 'min:0'], 'extras.*.devengo.tipo' => [Rule::in(['anual', 'semestral', 'otro'])],
                    'extras.*.devengo.desde' => ['nullable', 'required_if:extras.*.devengo.tipo,otro', 'integer', 'between:1,12'], 'extras.*.devengo.hasta' => ['nullable', 'required_if:extras.*.devengo.tipo,otro', 'integer', 'between:1,12']],
                    ['extras.*.nombre.required' => 'Falta el nombre.', 'extras.*.nombre.distinct' => 'Paga repetida.', 'extras.*.mes.between' => 'Elige el mes de cobro.',
                        'extras.*.dias.required_if' => 'Indica los días de salario.', 'extras.*.importe.required_if' => 'Indica el importe.',
                        'extras.*.devengo.desde.required_if' => 'Indica el mes inicial del devengo.', 'extras.*.devengo.hasta.required_if' => 'Indica el mes final del devengo.']);
                if ($val->fails()) {
                    return $falla($val);
                }
                Convenios::guardarReglas($v, ['extras' => $limpio, 'pagas' => $limpio ? (string) (12 + count($limpio)) : (($v->reglas['pagas'] ?? '14')), 'pagasArt' => self::txt($in['referencia'] ?? '', 120)]);
                break;

            case 'antiguedad':
                $si = $request->boolean('si');
                $d = ['periodo' => (int) ($in['periodo'] ?? 0), 'tipo' => (string) ($in['tipo'] ?? ''), 'valor' => self::num($in['valor'] ?? ''), 'base' => (string) ($in['base'] ?? 'salario_base'),
                    'tope' => (int) ($in['tope'] ?? 0) ?: null, 'computo' => (string) ($in['computo'] ?? 'alta'), 'congelada' => self::txt($in['congelada'] ?? '', 10), 'art' => self::txt($in['referencia'] ?? '', 120)];
                if ($si) {
                    $val = Validator::make($d, ['periodo' => ['integer', 'between:1,10'], 'tipo' => [Rule::in(['importe', 'porcentaje'])], 'valor' => ['required', 'numeric', 'min:0'],
                        'base' => [Rule::in(['salario_base', 'salario_base_plus'])], 'tope' => ['nullable', 'integer', 'min:1'], 'computo' => [Rule::in(['alta', 'antiguedad'])], 'congelada' => ['nullable', 'date']],
                        ['valor.required' => 'Indica el importe o el porcentaje por periodo.', 'periodo.between' => 'Elige cada cuántos años.']);
                    if ($val->fails()) {
                        return $falla($val);
                    }
                }
                Convenios::guardarReglas($v, ['antig' => $si ? $d : null, 'antigNo' => ! $si && $request->boolean('no_tiene')]);
                break;

            case 'jornada':
                $d = ['jornada' => self::num($in['jornada'] ?? ''), 'semanal' => self::num($in['semanal'] ?? ''), 'vacaciones' => self::num($in['vacaciones'] ?? ''), 'hx_valor' => self::num($in['hx_valor'] ?? '')];
                $val = Validator::make($d + $in, ['jornada' => ['nullable', 'numeric', 'between:1,3000'], 'semanal' => ['nullable', 'numeric', 'between:1,60'], 'vacaciones' => ['nullable', 'numeric', 'between:1,60'],
                    'vac_tipo' => [Rule::in(['naturales', 'laborables'])], 'hx_tipo' => [Rule::in(['no_consta', 'importe', 'recargo'])], 'hx_valor' => ['nullable', 'required_unless:hx_tipo,no_consta', 'numeric', 'min:0'],
                    'hx_compensacion' => [Rule::in(['economica', 'descanso', 'ambas'])]],
                    ['jornada.between' => 'Jornada anual no válida.', 'semanal.between' => 'Jornada semanal no válida.', 'vacaciones.between' => 'Días de vacaciones no válidos.', 'hx_valor.required_unless' => 'Indica el precio o el recargo.']);
                if ($val->fails()) {
                    return $falla($val);
                }
                Convenios::guardarReglas($v, ['jornada' => $d['jornada'] !== null ? (string) $d['jornada'] : '', 'vacaciones' => $d['vacaciones'] !== null ? (string) $d['vacaciones'] : '',
                    'calendario' => ['semanal' => $d['semanal'], 'distribucion' => self::txt($in['distribucion'] ?? '', 1000), 'vac_tipo' => $in['vac_tipo'] ?? 'naturales',
                        'descansos' => self::txt($in['descansos'] ?? '', 1000), 'nocturno' => self::txt($in['nocturno'] ?? '', 1000), 'festivos' => self::txt($in['festivos'] ?? '', 1000),
                        'hcomp' => self::txt($in['hcomp'] ?? '', 1000), 'permisos' => self::txt($in['permisos'] ?? '', 2000), 'art' => self::txt($in['referencia'] ?? '', 120)],
                    'hx' => ($in['hx_tipo'] ?? 'no_consta') === 'no_consta' ? null : ['tipo' => $in['hx_tipo'], 'valor' => $d['hx_valor'], 'compensacion' => $in['hx_compensacion'] ?? 'economica', 'art' => self::txt($in['referencia'] ?? '', 120)]]);
                break;

            case 'it':
                $tr = fn ($l) => array_values(array_map(fn ($t) => ['desde' => (int) ($t['desde'] ?? 0), 'hasta' => trim((string) ($t['hasta'] ?? '')) === '' ? null : (int) $t['hasta'],
                    'pct' => self::num($t['pct'] ?? ''), 'hosp' => ! empty($t['hosp'])], array_filter((array) $l, 'is_array')));
                $d = ['cc' => $tr($in['it_cc'] ?? []), 'at' => $tr($in['it_at'] ?? [])];
                $val = Validator::make(['it_cc' => $d['cc'], 'it_at' => $d['at']], ['*.*.desde' => ['integer', 'min:1'], '*.*.hasta' => ['nullable', 'integer'], '*.*.pct' => ['required', 'numeric', 'between:0,100']],
                    ['*.*.desde.min' => 'Día inicial no válido.', '*.*.pct.required' => 'Indica el porcentaje.', '*.*.pct.between' => 'Porcentaje de 0 a 100.']);
                $val->after(function ($vv) use ($d) {
                    foreach (['cc' => 'it_cc', 'at' => 'it_at'] as $k => $campo) {
                        foreach ($d[$k] as $i => $t) {
                            if ($t['hasta'] !== null && $t['hasta'] < $t['desde']) {
                                $vv->errors()->add("$campo.$i.hasta", 'El día final no puede ser menor que el inicial.');
                            }
                        }
                    }
                });
                if ($val->fails()) {
                    return $falla($val);
                }
                Convenios::guardarReglas($v, ['it' => $d + ['base' => self::txt($in['base'] ?? '', 300), 'pagas' => self::txt($in['pagas'] ?? '', 500), 'obs' => self::txt($in['obs'] ?? '', 2000),
                    'art' => self::txt($in['referencia'] ?? '', 120)]]);
                break;

            case 'contratacion':
                $rows = fn ($l, $keys) => array_values(array_filter(array_map(fn ($x) => array_map(fn ($k) => self::txt($x[$k] ?? '', 200), array_combine($keys, $keys)), array_filter((array) $l, 'is_array')),
                    fn ($x) => array_filter($x)));
                Convenios::guardarReglas($v, ['contrato' => ['prueba' => $rows($in['prueba'] ?? [], ['aplica', 'modalidad', 'duracion']), 'preaviso' => $rows($in['preaviso'] ?? [], ['quien', 'aplica', 'plazo']),
                    'clausulas' => self::txt($in['clausulas'] ?? '', 5000), 'obs' => self::txt($in['obs'] ?? '', 5000), 'art' => self::txt($in['referencia'] ?? '', 120)]]);
                break;
        }
        Auditoria::registrar('laboral.convenio.seccion', 'Convenio «'.$cv->nombre.'» · '.$v->nombre.' · '.self::SECCIONES[$seccion].' guardada por '.$request->user()->name, null, $c->id);

        return $volver()->with('ok', self::SECCIONES[$seccion].': guardado.')->with('cvSeccion', $seccion);
    }
}
