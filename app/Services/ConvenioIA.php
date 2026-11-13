<?php

namespace App\Services;

use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaRawMessageDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use App\Models\Ajuste;
use RuntimeException;

/**
 * Lectura del PDF de un convenio colectivo con Claude (API de Anthropic): tablas salariales, pagas extra,
 * antigüedad, pluses, complemento de IT y horas extra, con el artículo de donde sale cada dato.
 * Solo se envía el texto público del convenio (BOE o boletín provincial), nunca datos de trabajadores.
 */
class ConvenioIA
{
    public const CLAVE = 'ia.anthropic';

    public const MODELO = 'claude-opus-5-5';

    /** @param  ?callable  $llamar  (string $pdfBase64, string $prompt, array $schema): string JSON — para las pruebas */
    public function __construct(private $llamar = null) {}

    public static function clave(): ?string
    {
        return Ajuste::get(self::CLAVE) ?: (env('ANTHROPIC_API_KEY') ?: null);
    }

    public static function configurada(): bool
    {
        return (bool) self::clave();
    }

    /** Lee el convenio y devuelve sus reglas normalizadas */
    public function leer(string $pdf): array
    {
        if (strncmp($pdf, '%PDF', 4) !== 0) {
            throw new RuntimeException('El archivo no es un PDF.');
        }
        $json = $this->llamar
            ? ($this->llamar)(base64_encode($pdf), self::PROMPT, self::esquema())
            : $this->api(base64_encode($pdf));
        $d = json_decode($json, true);
        if (! is_array($d)) {
            throw new RuntimeException('La IA no ha devuelto los datos del convenio en el formato esperado. Vuelve a intentarlo.');
        }

        return self::normalizar($d);
    }

    private function api(string $b64): string
    {
        $key = self::clave();
        if (! $key) {
            throw new RuntimeException('Falta la clave de la API de Anthropic (Laboral › Gestión › Convenios › Lectura con IA).');
        }
        @set_time_limit(600);
        $client = new Client(apiKey: $key, requestOptions: ['timeout' => 540]);
        $stream = $client->beta->messages->createStream(
            model: self::MODELO,
            maxTokens: 64000,
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
            thinking: ['type' => 'adaptive'],
            outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => self::esquema()]],
            messages: [['role' => 'user', 'content' => [
                ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => $b64]],
                ['type' => 'text', 'text' => self::PROMPT],
            ]]],
        );
        $txt = '';
        $stop = null;
        foreach ($stream as $ev) {
            if ($ev instanceof BetaRawContentBlockDeltaEvent && $ev->delta instanceof BetaTextDelta) {
                $txt .= $ev->delta->text;
            } elseif ($ev instanceof BetaRawMessageDeltaEvent && $ev->delta->stopReason) {
                $stop = $ev->delta->stopReason;
            }
        }
        if ($stop === 'refusal') {
            throw new RuntimeException('La IA no ha podido procesar este documento.');
        }
        if ($stop === 'max_tokens') {
            throw new RuntimeException('El convenio es demasiado extenso para leerlo de una vez.');
        }

        return $txt;
    }

    private const PROMPT = <<<'TXT'
        Eres un asesor laboral español experto en nóminas. Lee el convenio colectivo adjunto completo (incluidos anexos y tablas salariales) y extrae lo necesario para calcular nóminas.

        Reglas:
        - Usa la tabla salarial del año más reciente que aparezca en el convenio y di cuál en "anio_tablas".
        - Importes en euros como números (1234.56). Si la tabla da importes anuales, conviértelos a mensuales dividiendo entre el número de pagas e indícalo en "avisos".
        - "unidad" de la tabla: "dia" si el convenio fija salario diario; si no, "mes".
        - grupo_cotizacion: el grupo de cotización a la Seguridad Social (1 a 11) que corresponde a la categoría según sus funciones; si no se puede deducir, 7 y añade un aviso.
        - Pluses: solo los que fija el convenio. "automatico" = true si se cobra todos los meses por igual sin depender de horas, días concretos o circunstancias (por ejemplo, plus de convenio o de transporte mensual); false si depende de horas nocturnas, festivos trabajados, toxicidad puntual, etc.
        - "cotiza" y "tributa": según la normativa vigente (desde 2014 casi todos cotizan; dietas y kilometraje en sus límites no).
        - Complemento de IT: tramos de días con el porcentaje del salario que garantiza el convenio (incluida la prestación). Si no hay mejora, lista vacía.
        - Cita el artículo o anexo de cada dato en "articulo".
        - No inventes. Si un dato no está en el convenio, deja null o lista vacía y explica la duda en "avisos".
        TXT;

    public static function esquema(): array
    {
        $num = ['type' => ['number', 'null']];
        $int = ['type' => ['integer', 'null']];
        $str = ['type' => 'string'];
        $obj = fn (array $p) => ['type' => 'object', 'properties' => $p, 'required' => array_keys($p), 'additionalProperties' => false];
        $arr = fn (array $p) => ['type' => 'array', 'items' => $obj($p)];
        $tramos = $arr(['desde_dia' => ['type' => 'integer'], 'hasta_dia' => $int, 'porcentaje' => ['type' => 'number']]);

        return $obj([
            'nombre' => $str, 'codigo' => $str, 'ambito' => $str, 'vigencia' => $str, 'anio_tablas' => $int,
            'jornada_anual' => $num, 'vacaciones_dias' => $int,
            'tablas' => $arr(['categoria' => $str, 'grupo_cotizacion' => ['type' => 'integer'], 'salario_base' => ['type' => 'number'],
                'unidad' => ['type' => 'string', 'enum' => ['mes', 'dia']], 'plus_convenio' => ['type' => 'number'], 'articulo' => $str]),
            'pagas_extra' => $arr(['nombre' => $str, 'mes_cobro' => ['type' => 'integer'], 'calculo' => ['type' => 'string', 'enum' => ['mensualidad', 'dias', 'importe']],
                'dias' => $num, 'importe' => $num, 'incluye_antiguedad' => ['type' => 'boolean'], 'incluye_plus_convenio' => ['type' => 'boolean'], 'articulo' => $str]),
            'antiguedad' => $obj(['existe' => ['type' => 'boolean'], 'periodo_anios' => $int, 'tipo' => ['type' => 'string', 'enum' => ['importe', 'porcentaje']], 'valor' => $num,
                'tope_periodos' => $int, 'articulo' => $str]),
            'pluses' => $arr(['nombre' => $str, 'unidad' => ['type' => 'string', 'enum' => ['mes', 'dia_trabajado', 'dia_natural', 'hora']],
                'tipo' => ['type' => 'string', 'enum' => ['importe', 'porcentaje_base']], 'valor' => ['type' => 'number'], 'automatico' => ['type' => 'boolean'],
                'cotiza' => ['type' => 'boolean'], 'tributa' => ['type' => 'boolean'], 'articulo' => $str]),
            'it' => $obj(['contingencias_comunes' => $tramos, 'accidente_trabajo' => $tramos, 'articulo' => $str]),
            'horas_extra' => $obj(['tipo' => ['type' => 'string', 'enum' => ['importe', 'recargo', 'no_consta']], 'valor' => $num, 'articulo' => $str]),
            'avisos' => ['type' => 'array', 'items' => $str],
        ]);
    }

    /** Limpia y acota lo que devuelve la IA al formato de la configuración laboral */
    public static function normalizar(array $d): array
    {
        $n = fn ($v) => is_numeric($v) ? round((float) $v, 2) : null;
        $t = fn ($v, $max = 200) => mb_substr(trim((string) $v), 0, $max);
        $cats = [];
        foreach ((array) ($d['tablas'] ?? []) as $x) {
            if ($t($x['categoria'] ?? '') === '' || ! is_numeric($x['salario_base'] ?? null)) {
                continue;
            }
            $cats[] = ['cat' => $t($x['categoria'], 120), 'grupo' => (string) max(1, min(11, (int) ($x['grupo_cotizacion'] ?? 7))),
                'base' => $n($x['salario_base']), 'plus' => $n($x['plus_convenio'] ?? 0) ?? 0.0, 'unidad' => ($x['unidad'] ?? 'mes') === 'dia' ? 'dia' : 'mes', 'art' => $t($x['articulo'] ?? '', 80)];
        }
        $pagas = [];
        foreach ((array) ($d['pagas_extra'] ?? []) as $i => $x) {
            $pagas[] = ['nombre' => $t($x['nombre'] ?? 'Paga extraordinaria '.($i + 1), 80), 'mes' => max(1, min(12, (int) ($x['mes_cobro'] ?? 12))),
                'calculo' => in_array($x['calculo'] ?? '', ['mensualidad', 'dias', 'importe'], true) ? $x['calculo'] : 'mensualidad',
                'dias' => $n($x['dias'] ?? null), 'importe' => $n($x['importe'] ?? null), 'antig' => ! empty($x['incluye_antiguedad']), 'plus' => ! empty($x['incluye_plus_convenio']), 'art' => $t($x['articulo'] ?? '', 80)];
        }
        $a = (array) ($d['antiguedad'] ?? []);
        $antig = ! empty($a['existe']) && (int) ($a['periodo_anios'] ?? 0) > 0 && is_numeric($a['valor'] ?? null)
            ? ['periodo' => (int) $a['periodo_anios'], 'tipo' => ($a['tipo'] ?? '') === 'porcentaje' ? 'porcentaje' : 'importe', 'valor' => $n($a['valor']),
                'tope' => (int) ($a['tope_periodos'] ?? 0) ?: null, 'art' => $t($a['articulo'] ?? '', 80)]
            : null;
        $pluses = [];
        foreach ((array) ($d['pluses'] ?? []) as $x) {
            if ($t($x['nombre'] ?? '') === '' || ! is_numeric($x['valor'] ?? null)) {
                continue;
            }
            $pluses[] = ['nombre' => $t($x['nombre'], 80), 'unidad' => in_array($x['unidad'] ?? '', ['mes', 'dia_trabajado', 'dia_natural', 'hora'], true) ? $x['unidad'] : 'mes',
                'tipo' => ($x['tipo'] ?? '') === 'porcentaje_base' ? 'porcentaje_base' : 'importe', 'valor' => $n($x['valor']), 'auto' => ! empty($x['automatico']),
                'cotiza' => (bool) ($x['cotiza'] ?? true), 'tributa' => (bool) ($x['tributa'] ?? true), 'art' => $t($x['articulo'] ?? '', 80)];
        }
        $tr = fn ($l) => array_values(array_map(fn ($x) => ['desde' => max(1, (int) ($x['desde_dia'] ?? 1)), 'hasta' => isset($x['hasta_dia']) ? (int) $x['hasta_dia'] : null,
            'pct' => max(0, min(100, (float) ($x['porcentaje'] ?? 0)))], array_filter((array) $l, 'is_array')));
        $it = (array) ($d['it'] ?? []);
        $hx = (array) ($d['horas_extra'] ?? []);

        return [
            'nombre' => $t($d['nombre'] ?? ''), 'codigo' => $t($d['codigo'] ?? '', 40), 'ambito' => $t($d['ambito'] ?? '', 160), 'vigencia' => $t($d['vigencia'] ?? '', 60),
            'anioTablas' => is_numeric($d['anio_tablas'] ?? null) ? (int) $d['anio_tablas'] : null,
            'jornada' => is_numeric($d['jornada_anual'] ?? null) ? (string) round((float) $d['jornada_anual']) : '', 'vacaciones' => is_numeric($d['vacaciones_dias'] ?? null) ? (string) (int) $d['vacaciones_dias'] : '',
            'cats' => $cats, 'extras' => $pagas, 'antig' => $antig, 'pluses' => $pluses,
            'it' => ['cc' => $tr($it['contingencias_comunes'] ?? []), 'at' => $tr($it['accidente_trabajo'] ?? []), 'art' => $t($it['articulo'] ?? '', 80)],
            'hx' => in_array($hx['tipo'] ?? '', ['importe', 'recargo'], true) && is_numeric($hx['valor'] ?? null) ? ['tipo' => $hx['tipo'], 'valor' => $n($hx['valor']), 'art' => $t($hx['articulo'] ?? '', 80)] : null,
            'avisos' => array_values(array_map(fn ($x) => $t($x, 300), array_slice((array) ($d['avisos'] ?? []), 0, 20))),
        ];
    }
}
