<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Convenio;
use App\Models\ConvenioCategoria;
use App\Models\ConvenioConcepto;
use App\Models\ConvenioConceptoImporte;
use App\Models\ConvenioVersion;
use App\Models\EmpresaConvenio;
use App\Support\Formato;
use Illuminate\Support\Facades\DB;

/**
 * Convenios versionados (fase 2): única fuente de los datos del convenio.
 *
 * - Una empresa tiene asignado un convenio desde una fecha (empresa_convenios).
 * - Cada convenio tiene versiones con efectos económicos; en cada fecha se aplica la versión aprobada vigente
 *   (o, si no hay ninguna aprobada, la vigente en borrador o revisión, con aviso).
 * - conv() devuelve la forma que ya usa el motor de nóminas, con el origen (convenio, versión y estado).
 */
class Convenios
{
    public const PLUS_CONVENIO = 'Plus de convenio';

    /** Asignación vigente de la empresa (toda la empresa, sin centro) */
    public static function asignacion(Cliente $c, ?string $fecha = null): ?EmpresaConvenio
    {
        $f = $fecha ?: now()->toDateString();

        return EmpresaConvenio::withoutGlobalScopes()->with('convenio')->where('cliente_id', $c->id)->whereNull('centro')
            ->where(fn ($q) => $q->whereNull('desde')->orWhere('desde', '<=', $f))
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>=', $f))
            ->orderByRaw('desde is null')->orderByDesc('desde')->orderByDesc('id')->first();
    }

    /** Versión aplicable en una fecha: primero las aprobadas; si no hay, la más reciente sin sustituir */
    public static function version(Convenio $cv, ?string $fecha = null): ?ConvenioVersion
    {
        $f = $fecha ?: now()->toDateString();
        $vs = ConvenioVersion::withoutGlobalScopes()->where('convenio_id', $cv->id)->get()->filter(fn ($v) => $v->aplicaEn($f));
        $orden = fn ($l) => $l->sortByDesc(fn ($v) => ($v->efectos_desde?->toDateString() ?? '0000').'-'.str_pad((string) $v->id, 10, '0', STR_PAD_LEFT));

        return $orden($vs->where('estado', 'aprobado'))->first() ?? $orden($vs->whereIn('estado', ['borrador', 'revision']))->first();
    }

    /** Convenio, versión y datos en la forma del motor de nóminas, o null si la empresa no tiene convenio */
    public static function conv(Cliente $c, ?string $fecha = null): ?array
    {
        $a = self::asignacion($c, $fecha);
        if (! $a || ! $a->convenio) {
            return null;
        }
        $v = self::version($a->convenio, $fecha);

        return self::forma($a->convenio, $v);
    }

    public static function forma(Convenio $cv, ?ConvenioVersion $v): array
    {
        $r = $v?->reglas ?? [];
        $cats = $v ? ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->orderBy('orden')->orderBy('id')->get() : collect();
        $conceptos = $v ? ConvenioConcepto::withoutGlobalScopes()->with(['importes' => fn ($q) => $q->withoutGlobalScopes()])->where('version_id', $v->id)->orderBy('orden')->orderBy('id')->get() : collect();
        $plusConv = $conceptos->firstWhere('nombre', self::PLUS_CONVENIO);
        $plusPor = $plusConv ? $plusConv->importes->pluck('importe', 'categoria_id') : collect();
        $catNom = $cats->pluck('nombre', 'id');

        return [
            'convenio_id' => $cv->id, 'version_id' => $v?->id, 'version' => $v?->nombre, 'estadoVersion' => $v?->estado, 'estadoConvenio' => $cv->estado,
            'efectos_desde' => $v?->efectos_desde?->toDateString(), 'efectos_hasta' => $v?->efectos_hasta?->toDateString(),
            'nombre' => $cv->nombre, 'codigo' => (string) $cv->codigo, 'ambito' => (string) $cv->ambito_territorial, 'vigencia' => (string) ($r['vigencia'] ?? ''),
            'jornada' => (string) ($r['jornada'] ?? ''), 'vacaciones' => (string) ($r['vacaciones'] ?? ''), 'pagas' => (string) ($r['pagas'] ?? '14'),
            'cats' => $cats->map(fn ($x) => ['id' => $x->id, 'cat' => $x->nombre, 'grupo' => (string) ($x->grupo_cotizacion ?? ''), 'grupo_profesional' => (string) $x->grupo_profesional,
                'nivel' => (string) $x->nivel, 'base' => $x->salario_base !== null ? (float) $x->salario_base : null, 'plus' => (float) ($plusPor[$x->id] ?? 0),
                'unidad' => $x->unidad === 'dia' ? 'dia' : 'mes', 'art' => (string) $x->referencia])->values()->all(),
            'pluses' => $conceptos->reject(fn ($p) => $p->nombre === self::PLUS_CONVENIO)->map(fn ($p) => ['id' => $p->id, 'nombre' => $p->nombre, 'codigo' => (string) $p->codigo,
                'unidad' => $p->unidad, 'tipo' => $p->calculo === 'porcentaje' ? 'porcentaje_base' : 'importe', 'calculo' => $p->calculo,
                'valor' => $p->valor !== null ? (float) $p->valor : 0.0, 'auto' => (bool) $p->automatico, 'cotiza' => $p->cotiza !== 'no', 'tributa' => $p->tributa !== 'no', 'art' => (string) $p->referencia,
                'naturaleza' => $p->naturaleza, 'prop_jornada' => (bool) $p->prop_jornada, 'prop_periodo' => (bool) $p->prop_periodo, 'en_pagas' => (bool) $p->en_pagas, 'limite' => (string) $p->limite,
                'cotizaModo' => $p->cotiza, 'tributaModo' => $p->tributa, 'formula' => (string) $p->formula, 'redondeo' => $p->redondeo,
                'limite_importe' => $p->limite_importe !== null ? (string) $p->limite_importe : null, 'limite_unidad' => $p->limite_unidad,
                'importes' => $p->importes->mapWithKeys(fn ($im) => [(string) ($catNom[$im->categoria_id] ?? '') => (float) $im->importe])->filter(fn ($v, $k) => $k !== '')->all(),
                'soportado' => self::soportado($p)])->values()->all(),
            'extras' => $r['extras'] ?? [], 'antig' => $r['antig'] ?? null, 'it' => $r['it'] ?? null, 'hx' => $r['hx'] ?? null, 'ia' => $r['ia'] ?? null,
        ];
    }

    /** ¿El motor de nóminas sabe calcular este concepto por sí solo? (fórmulas y algunas unidades llegan en la fase 4) */
    public static function soportado(ConvenioConcepto $p): bool
    {
        return self::motivoNoSoportado($p) === null;
    }

    /** Por qué el motor no puede calcular este concepto (null si puede) */
    public static function motivoNoSoportado(ConvenioConcepto $p): ?string
    {
        if (! in_array($p->unidad, ['mes', 'dia_natural', 'dia_trabajado', 'dia_efectivo', 'hora'], true)) {
            return 'la unidad «'.(ConvenioConcepto::UNIDADES[$p->unidad] ?? $p->unidad).'» no tiene un cálculo automático (se añade a mano en la nómina)';
        }
        if ($p->calculo === 'formula') {
            $codigos = ConvenioConcepto::withoutGlobalScopes()->where('version_id', $p->version_id)->whereNotNull('codigo')->pluck('codigo')->all();
            if ($e = Formula::validar((string) $p->formula, $codigos)) {
                return 'la fórmula no es válida: '.$e;
            }
        }
        if ($p->cotiza === 'limite' || $p->tributa === 'limite') {
            if ($p->limite_importe === null || ! in_array($p->limite_unidad, ['dia', 'mes'], true)) {
                return 'falta el importe y la unidad del límite';
            }
            if (($p->cotiza === 'si' && $p->tributa === 'limite') || ($p->cotiza === 'limite' && $p->tributa === 'no')) {
                return 'esa combinación de cotización e IRPF no tiene un tratamiento automático';
            }
        }

        return null;
    }

    /**
     * Simulación de una nómina con una versión del convenio, sin guardar nada: categoría, jornada, mes y fechas de alta y baja.
     * El salario sale de la tabla de la categoría (× jornada); no se calcula IRPF (depende del trabajador). Devuelve [lineas, n, w, error].
     */
    public static function simular(Convenio $cv, ConvenioVersion $v, array $in): array
    {
        $conv = self::forma($cv, $v);
        $cat = collect($conv['cats'])->firstWhere('cat', (string) ($in['cat'] ?? ''));
        if (! $cat) {
            return ['error' => 'Elige una categoría de la tabla salarial.'];
        }
        if ($cat['base'] === null) {
            return ['error' => 'La categoría «'.$cat['cat'].'» no tiene salario base en la tabla: no se puede simular sin inventarlo.'];
        }
        [$anio, $m] = array_map('intval', explode('-', (string) $in['mes']));
        $jor = max(1, min(100, (float) ($in['jor'] ?? 100)));
        $coef = $jor / 100;
        $dia = $cat['unidad'] === 'dia';
        $w = ['id' => 0, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => (int) ($cat['grupo'] ?: 7), 'jor' => $jor, 'horas' => (string) round(40 * $coef, 2),
            'alta' => (string) ($in['alta'] ?? '') ?: sprintf('%04d-01-01', $anio), 'baja' => (string) ($in['baja'] ?? ''), 'fAntig' => (string) ($in['antig'] ?? ''),
            'convCat' => $cat['cat'], 'pagas' => (int) ($conv['pagas'] ?: 14) ?: 14, 'prorr' => ! empty($in['prorr']),
            'salTipo' => $dia ? 'Diario' : 'Mensual', 'salDia' => $dia ? round($cat['base'] * $coef, 4) : 0, 'salBase' => $dia ? 0 : round($cat['base'] * $coef, 2),
            'plus' => round(($cat['plus'] ?? 0) * $coef, 2), 'diasExtra' => 30, 'regimen' => 'General',
            '_conv' => ['extras' => $conv['extras'], 'antig' => $conv['antig'], 'pluses' => $conv['pluses'], 'hx' => $conv['hx'], 'it' => $conv['it'], 'cats' => $conv['cats'],
                'version_id' => $conv['version_id'], 'version' => $conv['version'], 'jornada' => $conv['jornada']]];
        $doc = Nomina::conceptos($w, $m, [], null, $anio);
        // En la simulación el salario y el plus salen de la tabla, no de una ficha
        foreach ($doc['lineas'] as &$l) {
            if (in_array($l['id'] ?? '', ['sb', 'pl'], true)) {
                $l['regla'] = 'Tabla salarial del convenio, categoría '.$cat['cat'].($jor < 100 ? ' × '.Formato::num($jor, 0).' % de jornada' : '');
            }
        }
        unset($l);
        $n = Nomina::computar($w, $doc + ['recibo' => 'Nómina'], 0);

        return ['lineas' => $doc['lineas'], 'n' => $n, 'doc' => $doc, 'w' => $w, 'cat' => $cat, 'error' => null];
    }

    /** Convenio de la empresa para editar: el asignado o uno nuevo (borrador) asignado desde siempre */
    public static function deEmpresa(Cliente $c, ?int $userId = null): Convenio
    {
        if ($a = self::asignacion($c)) {
            return $a->convenio;
        }
        $cv = Convenio::create(['nombre' => 'Convenio de '.$c->nombre, 'estado' => 'borrador', 'created_by' => $userId]);
        EmpresaConvenio::create(['cliente_id' => $c->id, 'convenio_id' => $cv->id]);

        return $cv;
    }

    /** Versión que se edita: la indicada, la aplicable hoy o una nueva */
    public static function editable(Convenio $cv, ?int $versionId = null): ConvenioVersion
    {
        if ($versionId && ($v = ConvenioVersion::withoutGlobalScopes()->where('convenio_id', $cv->id)->find($versionId))) {
            return $v;
        }

        return self::version($cv) ?? ConvenioVersion::create(['convenio_id' => $cv->id, 'nombre' => 'Versión '.now()->year, 'estado' => 'borrador', 'fuente' => 'manual', 'reglas' => []]);
    }

    /**
     * Guarda en una versión los datos en la forma del motor (formulario o propuesta aprobada).
     * Categorías y conceptos se actualizan por nombre: reintentar no duplica y quitar uno lo borra.
     */
    public static function guardar(ConvenioVersion $v, array $conv): void
    {
        DB::transaction(function () use ($v, $conv) {
            $cv = Convenio::withoutGlobalScopes()->find($v->convenio_id);
            $cv->fill(array_filter(['nombre' => $conv['nombre'] ?? null, 'codigo' => $conv['codigo'] ?? null, 'ambito_territorial' => $conv['ambito'] ?? null], fn ($x) => $x !== null && $x !== ''));
            $cv->save();
            $reglas = array_replace($v->reglas ?? [], array_intersect_key($conv, array_flip(['vigencia', 'jornada', 'vacaciones', 'pagas', 'extras', 'antig', 'it', 'hx', 'ia'])));
            $v->reglas = $reglas;
            $v->save();

            if (array_key_exists('cats', $conv)) {
                $ids = [];
                foreach (array_values($conv['cats']) as $i => $x) {
                    $nom = mb_substr(trim((string) $x['cat']), 0, 160);
                    if ($nom === '' || isset($ids[$nom])) {
                        continue;
                    }
                    $cat = ConvenioCategoria::withoutGlobalScopes()->firstOrNew(['version_id' => $v->id, 'nombre' => $nom]);
                    $cat->fill(['codigo' => array_key_exists('codigo', $x) ? (mb_substr(trim((string) $x['codigo']), 0, 30) ?: null) : $cat->codigo, 'grupo_cotizacion' => ($x['grupo'] ?? '') !== '' ? max(1, min(11, (int) $x['grupo'])) : null, 'unidad' => ($x['unidad'] ?? 'mes') === 'dia' ? 'dia' : 'mes',
                        'salario_base' => is_numeric($x['base'] ?? null) ? round((float) $x['base'], 4) : null, 'referencia' => mb_substr((string) ($x['art'] ?? ''), 0, 120) ?: null,
                        'grupo_profesional' => mb_substr((string) ($x['grupo_profesional'] ?? ''), 0, 80) ?: null, 'nivel' => mb_substr((string) ($x['nivel'] ?? ''), 0, 40) ?: null, 'orden' => $i]);
                    $cat->save();
                    $ids[$nom] = ['id' => $cat->id, 'plus' => (float) ($x['plus'] ?? 0)];
                }
                ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->whereNotIn('id', array_column($ids, 'id') ?: [0])->delete();
                // Plus de convenio por categoría
                $conPlus = array_filter($ids, fn ($x) => $x['plus'] != 0);
                $pc = ConvenioConcepto::withoutGlobalScopes()->where('version_id', $v->id)->where('nombre', self::PLUS_CONVENIO)->first();
                if ($conPlus) {
                    $pc ??= ConvenioConcepto::create(['version_id' => $v->id, 'codigo' => '02', 'nombre' => self::PLUS_CONVENIO, 'naturaleza' => 'salarial', 'calculo' => 'fijo', 'unidad' => 'mes', 'automatico' => true]);
                    ConvenioConceptoImporte::withoutGlobalScopes()->where('concepto_id', $pc->id)->delete();
                    foreach ($conPlus as $x) {
                        ConvenioConceptoImporte::create(['concepto_id' => $pc->id, 'categoria_id' => $x['id'], 'importe' => round($x['plus'], 4)]);
                    }
                } elseif ($pc) {
                    $pc->delete();
                }
            }

            if (array_key_exists('pluses', $conv)) {
                $vivos = [];
                foreach (array_values($conv['pluses']) as $i => $p) {
                    $nom = mb_substr(trim((string) ($p['nombre'] ?? '')), 0, 120);
                    if ($nom === '' || $nom === self::PLUS_CONVENIO || isset($vivos[$nom])) {
                        continue;
                    }
                    $c = ConvenioConcepto::withoutGlobalScopes()->firstOrNew(['version_id' => $v->id, 'nombre' => $nom]);
                    $c->fill(['codigo' => $c->codigo ?: '06', 'naturaleza' => ! empty($p['cotiza']) ? 'salarial' : 'extrasalarial', 'calculo' => ($p['tipo'] ?? '') === 'porcentaje_base' ? 'porcentaje' : 'fijo',
                        'unidad' => in_array($p['unidad'] ?? '', array_keys(ConvenioConcepto::UNIDADES), true) ? $p['unidad'] : 'mes', 'valor' => is_numeric($p['valor'] ?? null) ? round((float) $p['valor'], 4) : null,
                        'base' => ($p['tipo'] ?? '') === 'porcentaje_base' ? 'salario_base' : null, 'automatico' => ! empty($p['auto']),
                        'cotiza' => ! empty($p['cotiza']) ? 'si' : 'no', 'tributa' => ! empty($p['tributa']) ? 'si' : 'no', 'referencia' => mb_substr((string) ($p['art'] ?? ''), 0, 120) ?: null, 'orden' => $i + 1]);
                    $c->save();
                    $vivos[$nom] = $c->id;
                }
                ConvenioConcepto::withoutGlobalScopes()->where('version_id', $v->id)->where('nombre', '!=', self::PLUS_CONVENIO)->whereNotIn('id', array_values($vivos) ?: [0])->delete();
            }
        });
        Nomina::olvidarReglas();
    }

    /**
     * Guarda los conceptos retributivos de una versión con todos sus campos e importes por categoría.
     * Se identifican por nombre (no se duplican); los que no vienen se borran. El «Plus de convenio» se edita en las categorías.
     *
     * @param  array<int, array>  $filas  ya validadas
     */
    public static function guardarConceptos(ConvenioVersion $v, array $filas): void
    {
        DB::transaction(function () use ($v, $filas) {
            $cats = ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->pluck('id')->all();
            $vivos = [];
            foreach (array_values($filas) as $i => $f) {
                $c = ConvenioConcepto::withoutGlobalScopes()->firstOrNew(['version_id' => $v->id, 'nombre' => $f['nombre']]);
                $c->fill(array_intersect_key($f, array_flip(['codigo', 'descripcion', 'cra', 'naturaleza', 'calculo', 'unidad', 'valor', 'base', 'formula', 'automatico', 'prop_jornada',
                    'prop_periodo', 'en_pagas', 'cotiza', 'tributa', 'limite', 'limite_importe', 'limite_unidad', 'redondeo', 'referencia'])) + ['orden' => $i + 1]);
                $c->save();
                $vivos[] = $c->id;
                ConvenioConceptoImporte::withoutGlobalScopes()->where('concepto_id', $c->id)->delete();
                foreach ($f['importes'] ?? [] as $catId => $imp) {
                    if ($imp !== null && in_array((int) $catId, $cats, true)) {
                        ConvenioConceptoImporte::create(['concepto_id' => $c->id, 'categoria_id' => (int) $catId, 'importe' => $imp]);
                    }
                }
            }
            ConvenioConcepto::withoutGlobalScopes()->where('version_id', $v->id)->where('nombre', '!=', self::PLUS_CONVENIO)->whereNotIn('id', $vivos ?: [0])->delete();
        });
        Nomina::olvidarReglas();
    }

    /** Guarda un bloque de reglas de la versión (pagas, antigüedad, jornada, IT, horas extra, contratación) */
    public static function guardarReglas(ConvenioVersion $v, array $parche): void
    {
        $v->reglas = array_replace($v->reglas ?? [], $parche);
        $v->save();
        Nomina::olvidarReglas();
    }

    /** Nueva versión copiando la indicada (tablas, conceptos e importes) con otras fechas de efecto */
    public static function nuevaVersion(ConvenioVersion $base, array $datos): ConvenioVersion
    {
        return DB::transaction(function () use ($base, $datos) {
            $n = ConvenioVersion::create(['convenio_id' => $base->convenio_id, 'nombre' => $datos['nombre'], 'efectos_desde' => $datos['efectos_desde'] ?? null,
                'efectos_hasta' => $datos['efectos_hasta'] ?? null, 'vigencia_desde' => $base->vigencia_desde, 'vigencia_hasta' => $base->vigencia_hasta,
                'estado' => 'borrador', 'fuente' => 'manual', 'reglas' => $base->reglas ?? []]);
            $mapa = [];
            foreach (ConvenioCategoria::withoutGlobalScopes()->where('version_id', $base->id)->get() as $c) {
                $mapa[$c->id] = ConvenioCategoria::create(array_merge($c->only(['codigo', 'nombre', 'grupo_profesional', 'nivel', 'grupo_cotizacion', 'unidad', 'salario_base', 'referencia', 'orden']), ['version_id' => $n->id]))->id;
            }
            foreach (ConvenioConcepto::withoutGlobalScopes()->with(['importes' => fn ($q) => $q->withoutGlobalScopes()])->where('version_id', $base->id)->get() as $c) {
                $nc = ConvenioConcepto::create(array_merge($c->only(['codigo', 'nombre', 'descripcion', 'cra', 'naturaleza', 'calculo', 'unidad', 'valor', 'base', 'formula', 'automatico',
                    'prop_jornada', 'prop_periodo', 'en_pagas', 'cotiza', 'tributa', 'limite', 'redondeo', 'referencia', 'orden']), ['version_id' => $n->id]));
                foreach ($c->importes as $im) {
                    if (isset($mapa[$im->categoria_id])) {
                        ConvenioConceptoImporte::create(['concepto_id' => $nc->id, 'categoria_id' => $mapa[$im->categoria_id], 'importe' => $im->importe]);
                    }
                }
            }

            return $n;
        });
    }

    /**
     * Validaciones del convenio y sus versiones: [[nivel (error|aviso|info), texto]].
     * Solapamientos y huecos entre versiones aprobadas, versiones sin fecha de efectos, categorías sin salario o sin grupo.
     */
    public static function validar(Convenio $cv): array
    {
        $out = [];
        $vs = ConvenioVersion::withoutGlobalScopes()->where('convenio_id', $cv->id)->where('estado', '!=', 'sustituido')->get();
        if ($vs->isEmpty()) {
            return [['error', 'El convenio no tiene ninguna versión.']];
        }
        foreach ($vs as $v) {
            if (! $v->efectos_desde) {
                $out[] = ['aviso', '«'.$v->nombre.'» no tiene fecha de inicio de efectos económicos: se aplica a cualquier fecha.'];
            }
            if ($v->efectos_desde && $v->efectos_hasta && $v->efectos_hasta->lt($v->efectos_desde)) {
                $out[] = ['error', '«'.$v->nombre.'»: la fecha final de efectos es anterior a la inicial.'];
            }
            $cats = ConvenioCategoria::withoutGlobalScopes()->where('version_id', $v->id)->get();
            if ($cats->isEmpty()) {
                $out[] = ['error', '«'.$v->nombre.'» no tiene categorías.'];
            }
            foreach ($cats as $c) {
                if ($c->salario_base === null) {
                    $out[] = ['error', '«'.$v->nombre.'» · '.$c->nombre.': falta el salario base.'];
                }
                if (! $c->grupo_cotizacion) {
                    $out[] = ['aviso', '«'.$v->nombre.'» · '.$c->nombre.': falta el grupo de cotización.'];
                }
            }
            if ($v->estado !== 'aprobado') {
                $out[] = ['info', '«'.$v->nombre.'» está en '.mb_strtolower(Convenio::ESTADOS[$v->estado] ?? $v->estado).'.'];
            }
        }
        $ap = $vs->where('estado', 'aprobado')->filter(fn ($v) => $v->efectos_desde)->sortBy(fn ($v) => $v->efectos_desde->toDateString())->values();
        for ($i = 1; $i < $ap->count(); $i++) {
            $a = $ap[$i - 1];
            $b = $ap[$i];
            $finA = $a->efectos_hasta?->toDateString();
            if (! $finA || $finA >= $b->efectos_desde->toDateString()) {
                $out[] = ['error', 'Se solapan «'.$a->nombre.'» y «'.$b->nombre.'»: pon fecha final a la primera antes del '.$b->efectos_desde->format('d/m/Y').'.'];
            } elseif ($b->efectos_desde->copy()->subDay()->toDateString() > $finA) {
                $out[] = ['aviso', 'Hueco entre «'.$a->nombre.'» (hasta el '.$a->efectos_hasta->format('d/m/Y').') y «'.$b->nombre.'» (desde el '.$b->efectos_desde->format('d/m/Y').').'];
            }
        }

        return $out;
    }
}
