<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\JornadaAusencia;
use App\Models\LaboralConfig;
use App\Models\LaboralMes;
use App\Models\LaboralMovimiento;
use App\Models\NominaMes;
use App\Models\Trabajador;
use App\Support\Dec;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Motor de Laboral y Seguridad Social (prototipo «Laboral y SS»):
 * nómina mensual, bases y cuotas de cotización de 2026 y tipo de retención del IRPF
 * con regularización mensual. Las cifras de 2026 son las del diseño: revisarlas si cambia la normativa.
 */
class Nomina
{
    /** Bases mínimas mensuales por grupo de cotización y base máxima (2026) */
    public const BASE_MIN = [1 => 1989.30, 2 => 1649.70, 3 => 1435.20, 4 => 1424.40, 5 => 1424.40, 6 => 1424.40, 7 => 1424.40];

    /** Grupos 8 a 11: base mínima DIARIA (la mensual de los grupos 4-7 entre 30), por los días naturales del mes */
    public const BASE_MIN_DIA = [8 => 47.48, 9 => 47.48, 10 => 47.48, 11 => 47.48];

    public const BASE_MAX = 5101.20;

    /** Año al que corresponden las bases y tipos fijos de este archivo (pendientes de pasar a parámetros con fecha de efecto) */
    public const PARAM_ANIO = 2026;

    /** Texto de relleno que versiones anteriores guardaban como si fuera una categoría */
    public const SIN_CAT = 'Sin categorías en el convenio';

    /** SMI anual de 2026 (14 pagas) usado en la comprobación legal del contrato */
    public const SMI_ANUAL = 17094.0;

    /** Tipos de cotización 2026 en tanto por uno: [trabajador, empresa] */
    public const TIPOS = ['cc' => [0.047, 0.236], 'mei' => [0.0015, 0.0075], 'des_ind' => [0.0155, 0.055], 'des_tmp' => [0.016, 0.067], 'fp' => [0.001, 0.006], 'fog' => [0, 0.002]];

    public const GRUPOS = ['1 · Ingenieros y licenciados', '2 · Ingenieros técnicos y peritos', '3 · Jefes administrativos y de taller', '4 · Ayudantes no titulados', '5 · Oficiales administrativos', '6 · Subalternos', '7 · Auxiliares administrativos', '8 · Oficiales de primera y segunda', '9 · Oficiales de tercera y especialistas', '10 · Peones', '11 · Trabajadores menores de 18 años'];

    public const CONTRATOS = ['100 · Indefinido a tiempo completo · ordinario', '200 · Indefinido a tiempo parcial · ordinario', '300 · Fijo discontinuo', '402 · Duración determinada a tiempo completo · circunstancias de la producción', '410 · Duración determinada a tiempo completo · sustitución', '130 · Indefinido a tiempo completo · persona con discapacidad', '150 · Indefinido a tiempo completo · con incentivos', '189 · Indefinido a tiempo completo · transformación de temporal', '230 · Indefinido a tiempo parcial · persona con discapacidad', '250 · Indefinido a tiempo parcial · con incentivos', '289 · Indefinido a tiempo parcial · transformación de temporal', '420 · Formativo para la obtención de práctica profesional · tiempo completo', '421 · Formación en alternancia', '502 · Duración determinada a tiempo parcial · circunstancias de la producción', '510 · Duración determinada a tiempo parcial · sustitución', '520 · Formativo para la obtención de práctica profesional · tiempo parcial'];

    /** Autónomos (RETA) que se pueden dar de alta en la plantilla */
    public const TIPOS_AUTONOMO = ['Titular de la actividad', 'Autónomo colaborador familiar', 'Socio trabajador de comunidad de bienes o sociedad civil', 'Socio o administrador de sociedad (autónomo societario)', 'Socio trabajador de cooperativa'];

    public const NIVEL_FORMATIVO = ['Sin estudios', 'Educación primaria', 'ESO o equivalente', 'Bachillerato', 'FP de grado medio', 'FP de grado superior', 'Grado universitario', 'Máster', 'Doctorado'];

    public const CLAUSULAS = [
        'conf' => ['Confidencialidad', 'El trabajador se obliga a guardar secreto sobre la información de la empresa a la que tenga acceso, durante la relación laboral y después de su extinción.'],
        'datos' => ['Protección de datos', 'La empresa tratará los datos personales del trabajador para la gestión de la relación laboral conforme al RGPD y a la LOPDGDD.'],
        'desc' => ['Desconexión digital', 'Se garantiza el derecho a la desconexión digital fuera del tiempo de trabajo (art. 88 LOPDGDD y art. 20 bis ET).'],
        'medios' => ['Uso de medios digitales', 'Los dispositivos facilitados por la empresa son de uso profesional y podrán ser controlados en los términos de la política interna, que el trabajador declara conocer.'],
        'excl' => ['Plena dedicación', 'El trabajador se compromete a no trabajar para otras empresas durante la vigencia del contrato a cambio de la compensación económica pactada (art. 21.1 ET).'],
        'nocomp' => ['No competencia poscontractual', 'Pacto de no competencia de hasta 2 años (técnicos) o 6 meses (resto) tras la extinción, con compensación económica adecuada (art. 21.2 ET).'],
        'perm' => ['Permanencia por formación', 'Si la empresa costea una especialización profesional, el trabajador se compromete a permanecer hasta 2 años (art. 21.4 ET).'],
        'tele' => ['Trabajo a distancia', 'La prestación podrá realizarse parcialmente a distancia mediante acuerdo escrito conforme a la Ley 10/2021.'],
        'reg' => ['Registro de jornada', 'La jornada se registrará diariamente con hora de inicio y fin (art. 34.9 ET).'],
    ];

    public const URL = ['red' => 'https://sede.seg-social.gob.es', 'sepe' => 'https://sede.sepe.gob.es', 'regcon' => 'https://expinterweb.mites.gob.es/regcon/', 'boe' => 'https://www.boe.es/buscar/', 'aeat' => 'https://sede.agenciatributaria.gob.es'];

    public const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public const CAUSAS_BAJA = ['Fin de contrato', 'Baja voluntaria', 'Despido disciplinario', 'Despido objetivo', 'No superación del periodo de prueba', 'Jubilación'];

    public const INCIDENCIAS = ['hx' => 'Horas extraordinarias (€)', 'otros' => 'Otros devengos salariales (€)', 'dietas' => 'Dietas (€)', 'km' => 'Kilometraje (€)', 'anticipo' => 'Anticipos (€)', 'embargo' => 'Embargos (€)'];

    /** Conceptos retributivos abonados (CRA): concepto de la nómina => etiqueta. El código de la tabla CRA de la TGSS se asigna en la pantalla CRA. */
    public const CRA = ['sb' => 'Salario base', 'pl' => 'Complementos salariales', 'extra' => 'Pagas extraordinarias', 'pror' => 'Prorrata de pagas extraordinarias', 'hx' => 'Horas extraordinarias', 'otr' => 'Otros devengos salariales', 'die' => 'Dietas', 'km' => 'Plus de distancia y kilometraje'];

    /** Texto por defecto del cuerpo del contrato. **negrita** y marcadores {así} */
    public const PLANTILLA_CONTRATO = "**Primera.** El trabajador prestará sus servicios como {categoria} en el centro de trabajo {centro}.\n\n**Segunda.** {duracion}\n\n**Tercera.** La jornada será de {horas} horas semanales con la siguiente distribución: {horario}. Se llevará registro diario de la jornada.\n\n**Cuarta.** El trabajador percibirá una retribución bruta anual de {retribucion} distribuida en {pagas} pagas, conforme a {convenio}.\n\n**Quinta.** Se establece un periodo de prueba de {prueba} (art. 14 ET).\n\n**Sexta.** Las vacaciones serán de {vacaciones} días naturales por año trabajado.\n\n**Séptima.** En lo no previsto se estará a lo dispuesto en {convenio}, en el Estatuto de los Trabajadores y demás normativa aplicable.";

    public const MARCADORES = ['empresa' => 'Nombre de la empresa', 'cif' => 'CIF de la empresa', 'ccc' => 'Código de cuenta de cotización', 'trabajador' => 'Nombre del trabajador', 'nif' => 'Documento del trabajador',
        'naf' => 'Nº de afiliación', 'categoria' => 'Categoría', 'grupo' => 'Grupo de cotización', 'centro' => 'Centro de trabajo', 'inicio' => 'Fecha de inicio', 'fin' => 'Fecha de fin', 'duracion' => 'Frase de duración (indefinido o temporal con causa)',
        'causa' => 'Causa de la temporalidad', 'horas' => 'Horas semanales', 'jornada' => 'Jornada %', 'horario' => 'Distribución del horario', 'retribucion' => 'Retribución bruta anual', 'salario' => 'Salario base mensual', 'pagas' => 'Número de pagas',
        'convenio' => 'Convenio colectivo', 'prueba' => 'Periodo de prueba', 'vacaciones' => 'Días de vacaciones', 'municipio' => 'Municipio de la empresa', 'contrato' => 'Modalidad del contrato'];

    public const ORDINALES = ['Primera', 'Segunda', 'Tercera', 'Cuarta', 'Quinta', 'Sexta', 'Séptima', 'Octava', 'Novena', 'Décima', 'Undécima', 'Duodécima', 'Decimotercera', 'Decimocuarta', 'Decimoquinta', 'Decimosexta', 'Decimoséptima', 'Decimoctava', 'Decimonovena', 'Vigésima', 'Vigesimoprimera', 'Vigesimosegunda', 'Vigesimotercera', 'Vigesimocuarta', 'Vigesimoquinta'];

    /** Escala de retenciones y límites excluidos (situación 1, 2, 3 × 0, 1, 2+ hijos) */
    private const ESCALA = [[12450, 19], [20200, 24], [35200, 30], [60000, 37], [300000, 45], [INF, 47]];

    private const LIMITES = [1 => [0, 17644, 18694], 2 => [17197, 18130, 19262], 3 => [15876, 16342, 16867]];

    private array $memo = [];

    public static function codigo(string $contrato): string
    {
        return explode(' · ', $contrato)[0];
    }

    public static function contratoCompleto(string $codigo): string
    {
        foreach (self::CONTRATOS as $x) {
            if (self::codigo($x) === $codigo) {
                return $x;
            }
        }

        return $codigo;
    }

    public static function esAutonomo(array $w): bool
    {
        return ($w['regimen'] ?? 'General') === 'Autónomo';
    }

    public static function temporal(array $w): bool
    {
        return (bool) preg_match('/^[45]/', self::codigo((string) ($w['contrato'] ?? '')));
    }

    public static function parcial(array $w): bool
    {
        return (bool) preg_match('/^[25]/', self::codigo((string) ($w['contrato'] ?? '')));
    }

    public static function activo(array $w, int $anio, int $m): bool
    {
        $mm = $anio.'-'.str_pad((string) $m, 2, '0', STR_PAD_LEFT);

        return ($w['alta'] ?? '9999') <= $mm.'-31' && (empty($w['baja']) || $w['baja'] >= $mm.'-01');
    }

    public static function n($v): float
    {
        return is_numeric($v) ? (float) $v : Formato::parseNum((string) ($v ?? ''));
    }

    public static function dni(string $nif, string $tipo = 'DNI'): bool
    {
        $n = strtoupper(trim($nif));
        $L = 'TRWAGMYFPDXBNJZSQVHLCKE';
        if (preg_match('/^(\d{8})([A-Z])$/', $n, $m)) {
            return $L[(int) $m[1] % 23] === $m[2];
        }
        if (preg_match('/^([XYZ])(\d{7})([A-Z])$/', $n, $m)) {
            return $L[(int) (strpos('XYZ', $m[1]).$m[2]) % 23] === $m[3];
        }

        return $tipo === 'Pasaporte' && strlen($n) > 4;
    }

    // ------------------------------------------------------------------ ficha
    /** Valores por defecto de la ficha (lbFill) */
    public static function rellenar(array $w, Cliente $c): array
    {
        $p = preg_split('/\s+/', trim((string) ($w['nombre'] ?? ''))) ?: [];
        $jor = (float) ($w['jor'] ?? 100) ?: 100;

        return array_merge([
            'tipoDoc' => preg_match('/^[XYZ]/i', (string) ($w['nif'] ?? '')) ? 'NIE' : 'DNI', 'nom1' => $p[0] ?? '', 'ape1' => $p[1] ?? '', 'ape2' => implode(' ', array_slice($p, 2)),
            'fnac' => '', 'sexo' => 'Mujer', 'nac' => 'Española', 'ecivil' => 'Soltero/a', 'dom' => '', 'cp' => '', 'mun' => $c->municipio ?? '', 'prov' => $c->provincia ?: 'Huelva', 'tel' => '', 'email' => '',
            'nivForm' => 'ESO o equivalente', 'iban' => '', 'disc' => 'Sin discapacidad',
            'naf' => '', 'nif' => '', 'ccc' => '', 'altaSS' => $w['alta'] ?? '', 'grupo' => 7, 'cno' => '', 'epig' => 'Tarifa general por CNAE', 'relEsp' => 'Común',
            'contrato' => self::CONTRATOS[0], 'alta' => '', 'fin' => '', 'causa' => '', 'sustituido' => '', 'jor' => 100,
            'horas' => (string) round(40 * $jor / 100), 'horario' => $jor < 100 ? 'De lunes a viernes de 9:00 a 13:00' : 'De lunes a viernes de 8:00 a 16:00', 'centro' => '', 'convCat' => $w['cat'] ?? '',
            'prueba' => ((int) ($w['grupo'] ?? 7)) <= 2 ? '6 meses' : '2 meses', 'vac' => '30',
            'salBase' => 0, 'salTipo' => 'Mensual', 'salDia' => 0, 'diasExtra' => 30, 'plus' => 0, 'pagas' => 14, 'prorr' => false, 'irpfModo' => 'auto', 'irpf' => 2,
            'm145' => ['sit' => '3', 'nifCony' => '', 'movil' => false, 'pension' => '', 'alimentos' => '', 'vivienda' => false, 'fecha' => '', 'hijos' => [], 'asc' => []],
            'claus' => ['conf', 'datos', 'desc', 'reg'], 'docs' => [], 'nombre' => '', 'cat' => '',
            'regimen' => 'General', 'autTipo' => self::TIPOS_AUTONOMO[0], 'autBase' => 0, 'autCuota' => 0, 'autRend' => 0, 'autMutua' => '', 'autCese' => true,
            'autTarifa' => false, 'autTarifaFin' => '', 'iae' => '', 'fAntig' => '',
        ], $w, ['_conv' => self::reglas($c), '_cid' => $c->id], ($w['convCat'] ?? '') === self::SIN_CAT ? ['convCat' => ''] : [], ($w['cat'] ?? '') === self::SIN_CAT ? ['cat' => ''] : []);
    }

    /** Retribución por día trabajado (salario diario) en vez de mensual */
    public static function diario(array $w): bool
    {
        return ($w['salTipo'] ?? 'Mensual') === 'Diario';
    }

    /** Importe de cada paga extraordinaria: el salario base mensual, o los días de salario que fije el convenio si cobra por día */
    public static function pagaExtra(array $w): float
    {
        return self::diario($w) ? round(self::n($w['salDia'] ?? 0) * ((int) ($w['diasExtra'] ?? 30) ?: 30), 2) : self::n($w['salBase'] ?? 0);
    }

    /** Días naturales del mes (salario diario y base mínima de los grupos 8 a 11) */
    public static function diasMes(?int $anio, int $m): int
    {
        return Carbon::create($anio ?: (int) now()->year, max(1, min(12, $m)), 1)->daysInMonth;
    }

    private static array $reglasCache = [];

    /** Tras cambiar el convenio, las reglas se vuelven a leer */
    public static function olvidarReglas(): void
    {
        self::$reglasCache = [];
    }

    /** Reglas del convenio de la empresa que se aplican solas en la nómina, de la versión aplicable en la fecha */
    public static function reglas(Cliente $c, ?string $fecha = null): array
    {
        $cache = &self::$reglasCache;
        $k = $c->id.'|'.($fecha ?? 'hoy');
        if (! isset($cache[$k]) || app()->runningUnitTests()) {
            $conv = self::config($c, $fecha)['conv'];
            $cache[$k] = ['extras' => $conv['extras'] ?? [], 'antig' => $conv['antig'] ?? null, 'pluses' => $conv['pluses'] ?? [], 'hx' => $conv['hx'] ?? null, 'it' => $conv['it'] ?? null, 'cats' => $conv['cats'] ?? [], 'version_id' => $conv['version_id'] ?? null, 'version' => $conv['version'] ?? null, 'jornada' => $conv['jornada'] ?? ''];
        }

        return $cache[$k];
    }

    /**
     * Grado de configuración del convenio: [[apartado, estado (ok|pendiente|falta), detalle]].
     * Que tenga nombre, jornada y vacaciones no significa que esté aplicado a las nóminas.
     */
    public static function estadoConvenio(array $conv): array
    {
        $cats = $conv['cats'] ?? [];
        $it = ($conv['it']['cc'] ?? []) || ($conv['it']['at'] ?? []);

        return [
            ['Identificación', ($conv['nombre'] ?? '') !== '' ? (($conv['codigo'] ?? '') !== '' ? 'ok' : 'pendiente') : 'falta',
                ($conv['nombre'] ?? '') === '' ? 'Sin nombre' : (($conv['codigo'] ?? '') === '' ? 'Falta el código REGCON' : $conv['codigo'])],
            ['Tablas salariales', $cats ? 'ok' : 'falta', $cats ? count($cats).' '.(count($cats) === 1 ? 'categoría' : 'categorías') : 'Sin categorías: las nóminas no se pueden calcular ni comprobar con el convenio'],
            ['Pagas extraordinarias', ($conv['extras'] ?? []) ? 'ok' : 'pendiente', ($conv['extras'] ?? []) ? count($conv['extras']).' pagas' : 'Sin definir: se aplican junio y diciembre de una mensualidad'],
            ['Antigüedad', ($conv['antig'] ?? null) ? 'ok' : 'pendiente', ($conv['antig'] ?? null) ? 'Cada '.$conv['antig']['periodo'].' años' : 'Sin definir: no se calcula'],
            ['Pluses', ($conv['pluses'] ?? []) ? 'ok' : 'pendiente', ($conv['pluses'] ?? []) ? count($conv['pluses']).' definidos' : 'Ninguno definido'],
            ['Complemento de IT', 'pendiente', $it ? 'Guardado, pero la nómina todavía no calcula las bajas' : 'Sin definir (y la nómina todavía no calcula las bajas)'],
            ['Horas extraordinarias', ($conv['hx'] ?? null) ? 'ok' : 'pendiente', ($conv['hx'] ?? null) ? 'Precio definido' : 'Sin precio'],
            ['Jornada y vacaciones', ($conv['jornada'] ?? '') !== '' && ($conv['vacaciones'] ?? '') !== '' ? 'ok' : 'pendiente', trim(($conv['jornada'] ?? '') !== '' ? $conv['jornada'].' h/año' : 'Sin jornada').' · '.(($conv['vacaciones'] ?? '') !== '' ? $conv['vacaciones'].' días de vacaciones' : 'sin vacaciones')],
        ];
    }

    /** Reglas del convenio de la versión aplicable en el mes de la nómina (no la de hoy) */
    public static function paraPeriodo(array $w, ?int $anio, int $m): array
    {
        // Condiciones del contrato vigentes en el mes (histórico con fecha de efecto)
        $w = Calendario::aplicarCondiciones($w, $anio ?: (int) now()->year, max(1, min(12, $m)));
        if (! empty($w['_cid']) && ($c = Cliente::find($w['_cid']))) {
            $w['_conv'] = self::reglas($c, Carbon::create($anio ?: (int) now()->year, max(1, min(12, $m)), 1)->toDateString());
        }

        return $w;
    }

    /** Salario base mensual equivalente (si cobra por día, 30 días) */
    private static function salMes(array $w): float
    {
        return self::diario($w) ? round(self::n($w['salDia'] ?? 0) * 30, 2) : self::n($w['salBase'] ?? 0);
    }

    /** Antigüedad del mes según el convenio: [importe, nº de periodos, texto] */
    public static function antiguedad(array $w, ?int $anio = null, int $m = 0): array
    {
        $a = $w['_conv']['antig'] ?? null;
        $desde = (string) (($w['fAntig'] ?? '') ?: ($w['alta'] ?? ''));
        if (! $a || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || (int) $a['periodo'] <= 0) {
            return [0.0, 0, ''];
        }
        $ref = $m ? Carbon::create($anio ?: (int) now()->year, $m, 1)->endOfMonth() : Carbon::now();
        // Antigüedad congelada por el convenio: no genera periodos nuevos desde esa fecha
        if (! empty($a['congelada']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['congelada']) && $ref->gt(Carbon::parse($a['congelada']))) {
            $ref = Carbon::parse($a['congelada']);
        }
        $n = intdiv((int) Carbon::parse($desde)->diffInYears($ref), (int) $a['periodo']);
        if (! empty($a['tope'])) {
            $n = min($n, (int) $a['tope']);
        }
        if ($n <= 0) {
            return [0.0, 0, ''];
        }
        $baseA = self::salMes($w) + (($a['base'] ?? 'salario_base') === 'salario_base_plus' ? self::n($w['plus'] ?? 0) : 0);
        $imp = $a['tipo'] === 'porcentaje' ? round($baseA * (float) $a['valor'] / 100 * $n, 2) : round((float) $a['valor'] * $n, 2);
        $nom = [2 => 'bienios', 3 => 'trienios', 4 => 'cuatrienios', 5 => 'quinquenios'][(int) $a['periodo']] ?? 'periodos';

        return [$imp, $n, $n.' '.$nom];
    }

    /** Pagas extraordinarias del trabajador: las del convenio si las tiene, si no verano y Navidad. [[id, cod, nombre, mes, importe]] */
    public static function extras(array $w, ?int $anio = null, int $m = 0): array
    {
        if ((int) ($w['pagas'] ?? 14) === 12) {
            return [];
        }
        $conv = $w['_conv']['extras'] ?? [];
        if (! $conv) {
            return array_map(fn ($x) => [$x[0], $x[1], $x[2], $x[3], self::pagaExtra($w), ['tipo' => 'anual'], ''], self::PAGAS_EXTRA);
        }
        $mes = self::salMes($w);
        $dia = self::diario($w) ? self::n($w['salDia'] ?? 0) : round($mes / 30, 4);
        $pl = self::n($w['plus'] ?? 0);
        $ant = self::antiguedad($w, $anio, $m)[0];
        $out = [];
        foreach (array_values($conv) as $i => $x) {
            $imp = match ($x['calculo'] ?? 'mensualidad') {
                'importe' => (float) ($x['importe'] ?? 0),
                'dias' => $dia * (float) ($x['dias'] ?? 30) + (! empty($x['plus']) ? $pl / 30 * (float) ($x['dias'] ?? 30) : 0) + (! empty($x['antig']) ? $ant / 30 * (float) ($x['dias'] ?? 30) : 0),
                default => $mes + (! empty($x['plus']) ? $pl : 0) + (! empty($x['antig']) ? $ant : 0),
            };
            $out[] = [$i === 0 ? 'pv' : ($i === 1 ? 'pn' : 'px'.$i), (string) (90 + $i), (string) $x['nombre'], (int) $x['mes'], round($imp, 2), $x['devengo'] ?? ['tipo' => 'anual'], (string) ($x['art'] ?? '')];
        }

        return $out;
    }

    /**
     * Periodo de devengo de una paga que se cobra en el mes $mc del año $anio: anual (los 12 meses que terminan en su mes de cobro),
     * semestral (los 6) u otro (del mes inicial al final que fije el convenio). [inicio, fin]
     */
    public static function periodoDevengo(array $dev, int $anio, int $mc): array
    {
        $tipo = $dev['tipo'] ?? 'anual';
        if ($tipo === 'otro' && ! empty($dev['desde']) && ! empty($dev['hasta'])) {
            $h = (int) $dev['hasta'];
            $fin = Carbon::create($h <= $mc ? $anio : $anio - 1, $h, 1)->endOfMonth()->startOfDay();
            $meses = ((int) $dev['hasta'] - (int) $dev['desde'] + 12) % 12 + 1;
        } else {
            $fin = Carbon::create($anio, $mc, 1)->endOfMonth()->startOfDay();
            $meses = $tipo === 'semestral' ? 6 : 12;
        }

        return [$fin->copy()->startOfMonth()->subMonthsNoOverflow($meses - 1), $fin];
    }

    /**
     * Días que generan la paga dentro de su periodo de devengo: en alta (hasta la baja o el corte) y sin IT ni ausencias no retribuidas.
     * [días del periodo, días que cuentan, días descontados por ausencias]
     */
    public static function diasDevengo(array $w, Carbon $ini, Carbon $fin, ?Carbon $corte = null): array
    {
        $total = (int) $ini->diffInDays($fin) + 1;
        $d = $ini->copy();
        $alta = preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($w['alta'] ?? '')) ? Carbon::parse(substr($w['alta'], 0, 10)) : $ini->copy();
        $d = $alta->greaterThan($d) ? $alta : $d;
        $h = $fin->copy();
        foreach ([$w['baja'] ?? null, $corte?->toDateString()] as $x) {
            if (is_string($x) && preg_match('/^\d{4}-\d{2}-\d{2}/', $x) && Carbon::parse(substr($x, 0, 10))->lessThan($h)) {
                $h = Carbon::parse(substr($x, 0, 10));
            }
        }
        if ($h->lessThan($d)) {
            return [$total, 0, 0];
        }
        $desc = 0;
        if (! empty($w['id'])) {
            foreach (Calendario::ausencias((int) $w['id'], $d->toDateString(), $h->toDateString()) as $a) {
                $t = Calendario::AUSENCIAS[$a->tipo] ?? ['retrib' => true];
                if (! empty($t['it']) || ! ($t['retrib'] ?? true)) {
                    $x = $a->desde->greaterThan($d) ? $a->desde : $d;
                    $y = $a->hasta->lessThan($h) ? $a->hasta : $h;
                    $desc += $y->greaterThanOrEqualTo($x) ? (int) $x->diffInDays($y) + 1 : 0;
                }
            }
        }

        return [$total, max(0, (int) $d->diffInDays($h) + 1 - $desc), $desc];
    }

    /** Variables de las fórmulas para un trabajador y un mes (ver Formula::VARIABLES) */
    public static function contextoFormula(array $w, ?int $anio, int $m): array
    {
        [$pd, $ph, $pdias, $pmes] = self::periodo($w, $anio, $m);
        $cal = $w['_cal'] ?? Calendario::mes($w, $anio ?: (int) now()->year, $m);
        $ha = self::n($w['_conv']['jornada'] ?? 0);
        $sm = self::salMes($w);

        return ['salario_base' => Dec::de($sm), 'salario_dia' => Dec::de(self::diario($w) ? self::n($w['salDia'] ?? 0) : $sm)->entre(self::diario($w) ? 1 : 30),
            'plus_convenio' => Dec::de(self::n($w['plus'] ?? 0)), 'antiguedad' => Dec::de(self::antiguedad($w, $anio, $m)[0]),
            'jornada' => Dec::de((self::n($w['jor'] ?? 100) ?: 100) / 100), 'horas_semana' => Dec::de(self::n($w['horas'] ?? 0)),
            'horas_anuales' => $ha > 0 ? Dec::de($ha) : null, 'horas_mes' => $ha > 0 ? Dec::de($ha)->entre(12) : null,
            'dias_mes' => Dec::de($pmes), 'dias_alta' => Dec::de($pdias), 'dias_laborables' => Dec::de($cal['laborables']),
            'dias_trabajados' => Dec::de($cal['trabajados']), 'dias_efectivos' => Dec::de($cal['efectivos']), 'cantidad' => null];
    }

    /**
     * Conceptos del convenio que salen solos cada mes, calculados con decimales exactos y explicados línea a línea.
     * Primero los de importe y porcentaje; después las fórmulas en el orden de sus dependencias (c_CODIGO).
     * Lo que el motor no sabe calcular (unidad sin calendario, fórmula inválida, límite incompleto) no se aproxima: se omite y se avisa en el convenio.
     */
    private static function plusesAuto(array $w, ?int $anio, int $m): array
    {
        $L = [];
        $coef = (self::n($w['jor'] ?? 100) ?: 100) / 100;
        [$pd, $ph, $pdias, $pmes] = self::periodo($w, $anio, $m);
        $cal = $w['_cal'] ?? Calendario::mes($w, $anio ?: (int) now()->year, $m);
        [$fr, $nP] = Calendario::fraccionPagada($cal, $w['_n30'] ?? null);
        $ctx = self::contextoFormula($w, $anio, $m);
        $cat = $w['convCat'] ?? '';
        $ver = $w['_conv']['version'] ?? null;
        $auto = array_values(array_filter($w['_conv']['pluses'] ?? [], fn ($p) => ! empty($p['auto']) && ($p['soportado'] ?? true) !== false && in_array($p['unidad'], ['mes', 'dia_natural', 'dia_trabajado', 'dia_efectivo'], true)));
        $form = [];
        foreach ($auto as $p) {
            if (($p['calculo'] ?? '') === 'formula' && ($p['codigo'] ?? '') !== '') {
                $form[strtoupper($p['codigo'])] = $p['formula'];
            }
        }
        [$orden, $ciclo] = Formula::orden($form);
        if ($ciclo) {
            $auto = array_values(array_filter($auto, fn ($p) => ($p['calculo'] ?? '') !== 'formula'));
        }
        $rank = array_flip($orden);
        usort($auto, fn ($a, $b) => ((($a['calculo'] ?? '') === 'formula') <=> (($b['calculo'] ?? '') === 'formula')) ?: (($rank[strtoupper($a['codigo'] ?? '')] ?? 0) <=> ($rank[strtoupper($b['codigo'] ?? '')] ?? 0)));
        foreach ($auto as $i => $p) {
            $unidad = $p['unidad'];
            $cant = match ($unidad) {
                'dia_natural' => $cal['pagados'], 'dia_trabajado' => $cal['trabajados'], 'dia_efectivo' => $cal['efectivos'], default => null
            };
            $fj = ($p['prop_jornada'] ?? true) && ($p['tipo'] ?? '') !== 'porcentaje_base' ? $coef : 1;
            $fp = ($p['prop_periodo'] ?? true) && $cant === null ? $fr : 1;
            try {
                if (($p['calculo'] ?? '') === 'formula') {
                    [$unit, $txtF] = Formula::evaluar($p['formula'], $ctx);
                    $orig = 'fórmula «'.$p['formula'].'» ('.$txtF.')';
                } else {
                    $porCat = isset($p['importes'][$cat]);
                    $valor = Dec::de($porCat ? $p['importes'][$cat] : $p['valor']);
                    $unit = ($p['tipo'] ?? '') === 'porcentaje_base' ? Dec::de(self::salMes($w))->por($valor)->entre(100) : $valor;
                    $orig = ($p['tipo'] ?? '') === 'porcentaje_base' ? $valor->txt().' % del salario base' : ($porCat ? 'importe de la categoría «'.$cat.'»' : 'importe general');
                }
            } catch (\Throwable $e) {
                continue; // dato ausente o división por cero: no se inventa un importe
            }
            $imp = ($cant !== null ? $unit->por($cant) : $unit)->por($fj)->por($fp)->redondear(($p['redondeo'] ?? '2d') === '0d' ? 0 : 2);
            if ($imp->esCero()) {
                continue;
            }
            $expl = ($cant !== null ? $cant.' '.['dia_trabajado' => 'días trabajados', 'dia_efectivo' => 'días efectivos', 'dia_natural' => 'días naturales pagados'][$unidad].' × '.$unit->txt(4).' €' : $unit->txt().' €/mes')
                .($fj != 1 ? ' × '.Formato::num($fj * 100, 0).' % de jornada' : '').($fp != 1 ? ' × '.$nP.'/30 días' : '').' = '.$imp->txt().' € · '.$orig;
            if (! empty($p['codigo'])) {
                $ctx['c_'.strtoupper($p['codigo'])] = $imp;
            }
            $regla = 'Convenio'.($ver ? ' · '.$ver : '').' · '.$p['nombre'].($p['art'] ?? '' ? ' · '.$p['art'] : '');
            $base = ['cod' => '06', 'cant' => $cant, 'precio' => $cant !== null ? $unit->redondear(4)->float() : null, 'origen' => 'convenio', 'conv' => null, 'regla' => $regla];
            $lims = self::partirLimite($p, $imp, $cant ?? $pdias);
            foreach ($lims as $k => [$parte, $clase, $suf]) {
                $L[] = $base + ['id' => 'cp'.$i.($k ? 'x' : ''), 'concepto' => $p['nombre'].$suf, 'importe' => $parte->float(), 'clase' => $clase, 'expl' => $expl.($suf ? ' · '.trim($suf, ' ()') : '')];
            }
        }

        return $L;
    }

    /**
     * Reparte un importe con límite de cotización o IRPF: [[importe, clase, sufijo]].
     * Dentro del límite: sin cotizar y/o sin tributar según el concepto; el exceso, como salario (cotiza y tributa) o solo tributa.
     */
    public static function partirLimite(array $p, Dec $imp, int $dias): array
    {
        $cot = $p['cotizaModo'] ?? ($p['cotiza'] ? 'si' : 'no');
        $tri = $p['tributaModo'] ?? ($p['tributa'] ? 'si' : 'no');
        $claseSin = fn ($c, $t) => $c === 'si' ? 'salarial' : ($t === 'si' ? 'tributa' : 'no_salarial');
        if ($cot !== 'limite' && $tri !== 'limite') {
            return [[$imp, $claseSin($cot, $tri), '']];
        }
        $lim = Dec::de($p['limite_importe'] ?? 0)->por(($p['limite_unidad'] ?? 'mes') === 'dia' ? $dias : 1);
        $dentro = $imp->min($lim);
        $exceso = $imp->menos($dentro);
        // Dentro del límite: lo que es «límite» no cotiza / no tributa; fuera: cotiza o tributa como diga el concepto
        $claseDentro = $claseSin($cot === 'si' ? 'si' : 'no', $tri === 'si' ? 'si' : 'no');
        $claseFuera = $claseSin($cot === 'no' ? 'no' : 'si', $tri === 'no' ? 'no' : 'si');
        $out = [[$dentro, $claseDentro, ' (hasta el límite)']];
        if (! $exceso->esCero()) {
            $out[] = [$exceso, $claseFuera, ' (exceso sobre el límite)'];
        }

        return $out;
    }

    /**
     * Periodo retribuido del mes según el inicio del contrato y la baja: [desde, hasta, días naturales en alta, días del mes].
     * Un trabajador que entra el día 10 de noviembre solo cobra y cotiza del 10 al 30.
     */
    public static function periodo(array $w, ?int $anio, int $m): array
    {
        $ini = Carbon::create($anio ?: (int) now()->year, max(1, min(12, $m)), 1)->startOfDay();
        $fin = $ini->copy()->endOfMonth()->startOfDay();
        $f = fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) ? Carbon::parse(substr($v, 0, 10))->startOfDay() : null;
        $alta = $f($w['alta'] ?? null);
        $baja = $f($w['baja'] ?? null);
        $d = $alta && $alta->gt($ini) ? $alta : $ini;
        $h = $baja && $baja->lt($fin) ? $baja : $fin;
        $dias = $h->gte($d) ? (int) $d->diffInDays($h) + 1 : 0;

        return [$d->toDateString(), $h->toDateString(), $dias, $ini->daysInMonth];
    }

    /** Fracción del mes para importes mensuales: 1 con el mes completo; si no, días en alta / 30 (mes comercial de 30 días) */
    public static function fraccion(int $dias, int $diasMes): float
    {
        return $dias >= $diasMes ? 1.0 : max(0, min(1, $dias / 30));
    }

    /** Días de lunes a viernes del mes (aproximación de los días trabajados sin calendario laboral) */
    public static function diasLaborables(?int $anio, int $m, ?string $desde = null, ?string $hasta = null): int
    {
        $d = Carbon::create($anio ?: (int) now()->year, max(1, min(12, $m)), 1);
        $n = 0;
        for ($i = 0, $t = $d->daysInMonth; $i < $t; $i++) {
            $x = $d->copy()->addDays($i);
            $k = $x->toDateString();
            $n += $x->isWeekday() && (! $desde || $k >= $desde) && (! $hasta || $k <= $hasta) ? 1 : 0;
        }

        return $n;
    }

    public static function anual(array $w): float
    {
        $ext = array_sum(array_column(self::extras($w), 4));
        $ant = self::antiguedad($w)[0];
        $auto = array_sum(array_map(fn ($p) => ! empty($p['auto']) && $p['unidad'] === 'mes' ? ($p['tipo'] === 'porcentaje_base' ? self::salMes($w) * (float) $p['valor'] / 100 : (float) $p['valor']) : 0, $w['_conv']['pluses'] ?? []));
        $fijo = (self::diario($w) ? self::n($w['salDia'] ?? 0) * 365 / 12 : self::n($w['salBase'] ?? 0)) + self::n($w['plus'] ?? 0) + $ant + $auto;

        return round($fijo * 12 + $ext, 2);
    }

    // ------------------------------------------------------------- parámetros
    public static function defectoConfig(Cliente $c): array
    {
        $cccs = collect($c->seccion('centros'))->pluck('ccc')->merge(collect($c->seccion('acts'))->pluck('ccc'))->filter()->unique()->values()->all();

        return ['conv' => ['nombre' => '', 'codigo' => '', 'ambito' => '', 'vigencia' => '', 'jornada' => '1780', 'vacaciones' => '30', 'pagas' => '14', 'cats' => []],
            'modelo' => 1, 'useImg' => true, 'at' => 1.5,
            'red' => ['email' => '', 'aut' => '', 'cert' => '', 'certCad' => ''], 'sepe' => ['aut' => '', 'mismoCert' => true, 'cert' => ''],
            'cccs' => $cccs, 'cccsDet' => [], 'contratos' => [], 'clausExtra' => [], 'cra' => [], 'hab' => array_map(fn ($x) => self::codigo($x), self::CONTRATOS), 'claus' => ['conf', 'datos', 'desc', 'reg']];
    }

    public static function config(Cliente $c, ?string $fecha = null): array
    {
        $d = LaboralConfig::where('cliente_id', $c->id)->first()?->datos ?? [];
        $def = self::defectoConfig($c);
        $P = array_replace($def, $d);
        foreach (['red', 'sepe'] as $k) {
            $P[$k] = array_replace($def[$k], $d[$k] ?? []);
        }
        // El convenio sale siempre de las tablas de convenios (versión aplicable en la fecha); el JSON antiguo ya no se lee
        $P['conv'] = array_replace($def['conv'], Convenios::conv($c, $fecha) ?? []);
        if ($P['cccsDet']) {
            $P['cccs'] = array_values(array_unique(array_column($P['cccsDet'], 'ccc')));
        }
        if (! $P['cccs']) {
            $P['cccs'] = $def['cccs'];
        }

        return $P;
    }

    public static function redOk(array $P): bool
    {
        return $P['red']['aut'] !== '' && $P['red']['cert'] !== '' && $P['red']['email'] !== '';
    }

    public static function sepeOk(array $P): bool
    {
        return $P['sepe']['aut'] !== '' && ($P['sepe']['mismoCert'] ? $P['red']['cert'] : $P['sepe']['cert']) !== '';
    }

    // ------------------------------------------------------------------ datos
    /** @return Collection<int, array> fichas de la plantilla (rellenadas) */
    public static function plantilla(Cliente $c): Collection
    {
        return Trabajador::where('cliente_id', $c->id)->orderBy('alta')->orderBy('id')->get()
            ->map(fn (Trabajador $t) => self::rellenar($t->ficha(), $c))->values();
    }

    /** Solo trabajadores por cuenta ajena (los que tienen contrato, nómina, seguros sociales y registro de jornada) */
    public static function asalariados(Cliente $c): Collection
    {
        return self::plantilla($c)->reject(fn ($w) => self::esAutonomo($w))->values();
    }

    /** Variables del año: [mes => [trabajadorId => [k => v]]] */
    public static function incidenciasAnio(Cliente $c, int $anio): array
    {
        return LaboralMes::where('cliente_id', $c->id)->where('anio', $anio)->get()
            ->mapWithKeys(fn ($m) => [(int) $m->mes => $m->incidencias ?? []])->all();
    }

    // ---------------------------------------------------------------- cálculo
    /** Nómina de un mes con el tipo de IRPF $ip, calculada con los conceptos automáticos */
    public static function calcular(array $w, int $m, array $inc, float $ip, float $at = 0): array
    {
        $w = self::paraPeriodo($w, null, $m);

        return self::computar($w, self::conceptos($w, $m, $inc), $ip, $at);
    }

    /** Pagas extraordinarias: [id, código, concepto, mes de cobro] */
    public const PAGAS_EXTRA = [['pv', '90', 'Paga de verano', 6], ['pn', '91', 'Paga de Navidad', 12]];

    /** Tipos de recibo del Integral de nóminas (clave guardada => nombre) */
    public const RECIBOS = ['Nómina' => 'Nómina', 'Extra' => 'Paga extra', 'Atrasos' => 'Atrasos', 'Finiquito' => 'Finiquito', 'Sal. tramitación' => 'Salarios de tramitación'];

    /**
     * Clases de concepto: cómo cotiza y tributa cada devengo (art. 147 LGSS y art. 7 LIRPF).
     * Desde el RDL 16/2013 el plus de transporte, el quebranto de moneda o el desgaste de útiles cotizan como salario.
     */
    public const CLASES = [
        'salarial' => 'Salarial (cotiza y tributa)',
        'hx' => 'Horas extraordinarias',
        'extra' => 'Paga extraordinaria',
        'especie' => 'En especie (cotiza y tributa, no se cobra)',
        'tributa' => 'IT y mejoras de IT (no cotiza, tributa)',
        'no_salarial' => 'Dietas y gastos de viaje (exentos)',
        'exento' => 'Indemnización exenta (ni cotiza ni tributa)',
    ];

    /** Catálogo de conceptos retributivos para añadir a la nómina: [código, concepto, clase] */
    public const CATALOGO = [
        ['01', 'Salario base', 'salarial'], ['02', 'Plus de convenio', 'salarial'], ['03', 'Antigüedad (trienios o quinquenios)', 'salarial'],
        ['04', 'Complemento personal (ad personam)', 'salarial'], ['05', 'Mejora voluntaria', 'salarial'], ['06', 'Plus de transporte', 'salarial'],
        ['07', 'Plus de nocturnidad', 'salarial'], ['08', 'Plus de turnicidad', 'salarial'], ['09', 'Plus de peligrosidad, toxicidad o penosidad', 'salarial'],
        ['11', 'Plus de asistencia y puntualidad', 'salarial'], ['12', 'Plus de productividad o incentivos', 'salarial'], ['13', 'Comisiones sobre ventas', 'salarial'],
        ['14', 'Recargo por trabajo en festivo', 'salarial'], ['15', 'Horas complementarias', 'salarial'], ['16', 'Plus de disponibilidad o guardias', 'salarial'],
        ['17', 'Plus de responsabilidad o jefatura', 'salarial'], ['18', 'Quebranto de moneda', 'salarial'], ['19', 'Plus de ropa de trabajo o desgaste de útiles', 'salarial'],
        ['20', 'Plus de desplazamiento', 'salarial'], ['22', 'Vacaciones no disfrutadas', 'salarial'], ['23', 'Atrasos de convenio', 'salarial'],
        ['24', 'Salarios de tramitación', 'salarial'], ['10', 'Horas extraordinarias', 'hx'],
        ['90', 'Paga de verano', 'extra'], ['91', 'Paga de Navidad', 'extra'], ['92', 'Paga de beneficios', 'extra'],
        ['30', 'Dietas', 'no_salarial'], ['31', 'Plus de distancia y kilometraje', 'no_salarial'], ['32', 'Gastos de locomoción', 'no_salarial'],
        ['40', 'Prestación IT por pago delegado', 'tributa'], ['41', 'Complemento de IT a cargo de la empresa', 'tributa'],
        ['50', 'Retribución en especie (vivienda, vehículo, seguro…)', 'especie'], ['51', 'Seguro médico pagado por la empresa', 'especie'],
        ['60', 'Indemnización por despido o cese', 'exento'], ['61', 'Indemnización por fin de contrato temporal', 'exento'],
    ];

    /** Deducciones habituales para añadir a la nómina */
    public const CATALOGO_DED = ['Anticipo', 'Préstamo de la empresa', 'Cuota sindical', 'Seguro médico', 'Compra de productos de la empresa', 'Pensión compensatoria o de alimentos', 'Otras deducciones'];

    /** Organismos que suelen ordenar embargos de salario */
    public const ORGANISMOS_EMBARGO = ['Juzgado', 'Agencia Tributaria (AEAT)', 'Tesorería General de la Seguridad Social', 'Ayuntamiento o Diputación', 'Junta de Andalucía', 'Otro organismo'];

    /**
     * Conceptos automáticos de la nómina del mes: salario base y complementos de la ficha (con la referencia
     * del convenio si hay categoría), pagas extra prorrateadas o en su mes (salvo que vayan en recibo aparte),
     * las variables del mes, los conceptos y deducciones fijos del trabajador y sus embargos.
     */
    public static function conceptos(array $w, int $m, array $inc, ?array $P = null, ?int $anio = null, bool $sinPagas = false): array
    {
        $w = self::paraPeriodo($w, $anio, $m);
        if (! empty($w['_cambiosMes']) && empty($w['_tramo'])) {
            return self::conceptosTramos($w, $m, $inc, $P, $anio ?: (int) now()->year, $sinPagas);
        }
        $pagas = (int) ($w['pagas'] ?? 14) ?: 14;
        $pro = ! empty($w['prorr']) && $pagas === 14;
        $coef = (self::n($w['jor'] ?? 100) ?: 100) / 100;
        $cat = ($w['convCat'] ?? '') !== '' ? collect($P ? ($P['conv']['cats'] ?? []) : ($w['_conv']['cats'] ?? []))->firstWhere('cat', $w['convCat']) : null;
        $convBase = $cat && $cat['base'] !== null ? round((float) $cat['base'] * $coef, 2) : null;
        $convPlus = $cat ? round((float) ($cat['plus'] ?? 0) * $coef, 2) : null;
        [$desde, $hasta, $dias, $diasMes] = self::periodo($w, $anio, $m);
        // Calendario del mes: IT y ausencias no retribuidas descuentan salario (1/30 por día); vacaciones y permisos retribuidos, no
        $Y = $anio ?: (int) now()->year;
        $cal = Calendario::mes($w, $Y, $m);
        $w['_cal'] = $cal;
        $desc = $cal['noRetrib'] + $cal['it'];
        [$fr, $nP] = Calendario::fraccionPagada($cal, $w['_n30'] ?? null);
        $descTxt = $desc ? ' (descontados '.$desc.' '.($desc === 1 ? 'día' : 'días').' de IT o ausencia no retribuida)' : '';
        $frTxt = $fr < 1 ? ' × '.$nP.' / 30 días'.$descTxt : '';
        // Origen real del importe: la tabla del convenio solo cuenta si el trabajador tiene una categoría que existe en ella
        $orig = $cat ? 'convenio' : 'ficha';
        $N = fn ($v, $d = 2) => Formato::num((float) $v, $d);
        $fuente = $cat ? 'Ficha del trabajador; referencia: tabla del convenio, categoría '.$w['convCat'] : 'Ficha del trabajador (sin categoría del convenio)';
        $jorTxt = $coef < 1 ? ' · jornada al '.$N($coef * 100, 0).' %' : '';
        $sb = self::n($w['salBase'] ?? 0) ?: round(self::n($w['sal'] ?? 0) / $pagas, 2);
        $pl = self::n($w['plus'] ?? 0);
        if (self::diario($w)) {
            $sd = self::n($w['salDia'] ?? 0);
            $dp = $cal['pagados'];
            $L = [['id' => 'sb', 'cod' => '01', 'concepto' => 'Salario base ('.$dp.' días)', 'cant' => $dp, 'precio' => $sd, 'importe' => round($sd * $dp, 2), 'clase' => 'salarial', 'origen' => $orig, 'conv' => null,
                'expl' => $dp.' días × '.$N($sd, 4).' €/día'.($desc ? ' ('.$dias.' días en alta menos '.$desc.' de IT o ausencia no retribuida)' : ''), 'regla' => $fuente]];
            $sb = self::pagaExtra($w);
            $convBase = null;
        } elseif ($fr < 1) {
            $L = [['id' => 'sb', 'cod' => '01', 'concepto' => 'Salario base ('.$nP.' días)', 'cant' => $nP, 'precio' => round($sb / 30, 4), 'importe' => round($sb * $fr, 2), 'clase' => 'salarial', 'origen' => $orig,
                'conv' => $convBase !== null ? round($convBase * $fr, 2) : null, 'expl' => $N($sb).' €/mes × '.$nP.' / 30 días'.($desc ? $descTxt : ' (mes incompleto: alta o baja dentro del mes)').$jorTxt, 'regla' => $fuente]];
        } else {
            $L = [['id' => 'sb', 'cod' => '01', 'concepto' => 'Salario base mensual', 'importe' => round($sb, 2), 'clase' => 'salarial', 'origen' => $orig, 'conv' => $convBase,
                'expl' => $N($sb).' €/mes (mes completo)'.$jorTxt, 'regla' => $fuente]];
        }
        if ($pl || $convPlus) {
            $L[] = ['id' => 'pl', 'cod' => '02', 'concepto' => 'Plus de convenio y complementos', 'importe' => round($pl * $fr, 2), 'clase' => 'salarial', 'origen' => $orig, 'conv' => $convPlus !== null ? round($convPlus * $fr, 2) : null,
                'expl' => $N($pl).' €/mes'.$frTxt, 'regla' => $fuente];
        }
        [$ant, , $antTxt] = self::antiguedad($w, $anio, $m);
        if ($ant) {
            $L[] = ['id' => 'ant', 'cod' => '03', 'concepto' => 'Antigüedad ('.$antTxt.')', 'importe' => round($ant * $fr, 2), 'clase' => 'salarial', 'origen' => 'convenio', 'conv' => null,
                'expl' => $antTxt.': '.$N($ant).' €/mes'.$frTxt, 'regla' => 'Convenio: regla de antigüedad'];
        }
        foreach (self::plusesAuto($w, $anio, $m) as $x) {
            $L[] = $x;
        }
        $pro = ! empty($w['prorr']) && $pagas !== 12;
        foreach (self::extras($w, $anio, $m) as [$id, $cod, $nom, $mesCobro, $imp, $dev, $art]) {
            if ($pro) {
                $L[] = ['id' => $id, 'cod' => $cod, 'concepto' => $nom, 'importe' => round($imp / 12 * $fr, 2), 'clase' => 'extra', 'cobro' => 'prorrateada', 'origen' => 'convenio', 'conv' => null,
                    'expl' => $N($imp).' € / 12 meses'.$frTxt.' (prorrateada)', 'regla' => 'Convenio: pagas extraordinarias'];
            } elseif ($m === $mesCobro && ! $sinPagas) {
                // Se cobra lo devengado en su periodo: días en alta sin IT ni ausencias no retribuidas / días del periodo
                [$di, $df] = self::periodoDevengo($dev, $Y, $mesCobro);
                [$tot, $cuenta, $dsc] = self::diasDevengo($w, $di, $df);
                $impP = $cuenta >= $tot ? round($imp, 2) : round($imp * $cuenta / $tot, 2);
                $L[] = ['id' => $id, 'cod' => $cod, 'concepto' => $nom, 'cant' => $cuenta < $tot ? $cuenta : null, 'precio' => $cuenta < $tot ? round($imp / $tot, 4) : null, 'importe' => $impP,
                    'clase' => 'extra', 'cobro' => 'en_su_mes', 'origen' => 'convenio', 'conv' => null,
                    'expl' => ($cuenta >= $tot ? 'Paga completa: '.$N($imp).' €, devengada entera' : $N($imp).' € × '.$cuenta.' / '.$tot.' días devengados'.($dsc ? ' (descontados '.$dsc.' de IT o ausencia no retribuida)' : ''))
                        .' · devengo del '.Formato::fecha($di->toDateString()).' al '.Formato::fecha($df->toDateString()),
                    'regla' => 'Convenio: pagas extraordinarias'.($art !== '' ? ' · '.$art : '')];
                if ($dsc && collect(Calendario::ausencias((int) ($w['id'] ?? 0), $di->toDateString(), $df->toDateString()))->contains(fn ($a) => $a->tipo === 'Incapacidad temporal')) {
                    $tramosAv[] = ['info', $nom.': los días de IT no generan la paga a cargo de la empresa (la prestación ya incluye su prorrata), salvo que el convenio diga otra cosa. Revísalo con el convenio.'];
                }
            }
        }
        // Incapacidad temporal (prestación y mejora del convenio por separado) y horas del calendario
        [$itL, $itCot, $avCal] = Calendario::it($w, $cal, $Y, $m);
        [$hL, $hAv] = Calendario::horas($w, $cal, self::contextoFormula($w, $Y, $m), $Y, $m);
        array_push($L, ...$itL, ...$hL);
        $avCal = array_merge($avCal, $hAv, self::avisosCalendario($w, $cal), $tramosAv ?? []);
        $g = fn ($k) => round(self::n($inc[$k] ?? 0), 2);
        foreach ([['hx', '10', 'Horas extraordinarias', 'hx'], ['otr', '20', 'Otros devengos salariales', 'salarial'], ['die', '30', 'Dietas', 'no_salarial'], ['km', '31', 'Plus de distancia y kilometraje', 'no_salarial']] as [$id, $cod, $nom, $cl]) {
            $v = $g(['hx' => 'hx', 'otr' => 'otros', 'die' => 'dietas', 'km' => 'km'][$id]);
            if ($v) {
                $L[] = ['id' => $id, 'cod' => $cod, 'concepto' => $nom, 'importe' => $v, 'clase' => $cl, 'origen' => 'variable', 'conv' => null, 'expl' => 'Importe introducido en las variables del mes', 'regla' => 'Variables del mes'];
            }
        }
        // Variables del mes escritas a mano (pantalla Variables)
        foreach ($inc['lineas'] ?? [] as $i => $x) {
            if (trim((string) ($x['concepto'] ?? '')) !== '' && (float) ($x['importe'] ?? 0) != 0) {
                $L[] = ['id' => 'v'.$i, 'cod' => (string) ($x['cod'] ?? ''), 'concepto' => $x['concepto'], 'importe' => round((float) $x['importe'], 2),
                    'clase' => array_key_exists($x['clase'] ?? '', self::CLASES) ? $x['clase'] : 'salarial', 'origen' => 'variable', 'conv' => null];
            }
        }
        // Conceptos fijos del trabajador (guardados desde el Integral con «Todos los meses»)
        $ym = sprintf('%04d-%02d', $Y, $m);
        foreach ($w['concFijos'] ?? [] as $i => $x) {
            if ((($x['desde'] ?? '') !== '' && $x['desde'] > $ym) || (($x['hasta'] ?? '') !== '' && $x['hasta'] < $ym)) {
                continue;
            }
            $L[] = ['id' => 'f'.$i, 'cod' => (string) ($x['cod'] ?? ''), 'concepto' => $x['concepto'], 'importe' => round((float) $x['importe'], 2),
                'cant' => $x['cant'] ?? null, 'precio' => $x['precio'] ?? null,
                'clase' => array_key_exists($x['clase'] ?? '', self::CLASES) ? $x['clase'] : 'salarial', 'origen' => 'fijo', 'fijo' => true, 'conv' => null,
                'expl' => 'Concepto fijo del trabajador'.(($x['desde'] ?? '') !== '' ? ' desde '.$x['desde'] : '').(($x['hasta'] ?? '') !== '' ? ' hasta '.$x['hasta'] : ' sin fecha de fin'), 'regla' => 'Ficha del trabajador'];
        }
        $D = [];
        foreach ([['ant', 'Anticipos', 'anticipo'], ['emb', 'Embargo', 'embargo']] as [$id, $nom, $k]) {
            if ($g($k)) {
                $D[] = ['id' => $id, 'concepto' => $nom, 'importe' => $g($k)];
            }
        }
        foreach ($w['dedFijas'] ?? [] as $x) {
            $D[] = ['id' => 'od', 'concepto' => $x['concepto'], 'importe' => round((float) $x['importe'], 2), 'fijo' => true];
        }
        foreach (self::embargosActivos($w, $anio, $m) as $e) {
            $D[] = $e;
        }

        return ['lineas' => $L, 'deducciones' => $D, 'tipos' => [], 'dias' => $dias, 'diasMes' => $diasMes, 'desde' => $desde, 'hasta' => $hasta,
            'itCot' => $itCot, 'avisosCal' => $avCal,
            'calendario' => ['laborables' => $cal['laborables'], 'trabajados' => $cal['trabajados'], 'efectivos' => $cal['efectivos'], 'noRetrib' => $cal['noRetrib'], 'it' => $cal['it'], 'pagados' => $cal['pagados']],
            'convenio' => ['version_id' => $w['_conv']['version_id'] ?? null, 'version' => $w['_conv']['version'] ?? null, 'categoria' => $cat['cat'] ?? null]];
    }

    /**
     * Mes con cambio de condiciones: se calcula por tramos (cada uno con las condiciones vigentes en él) y se juntan.
     * Mes comercial de 30 días: con el mes completo, el último tramo completa hasta 30. Pagas en su mes, variables, fijos y deducciones
     * solo una vez (en el último tramo). La base mínima de cotización se aplica con el grupo y la jornada del último tramo.
     */
    private static function conceptosTramos(array $w, int $m, array $inc, ?array $P, int $Y, bool $sinPagas): array
    {
        [$pd, $ph, $dias, $dm] = self::periodo($w, $Y, $m);
        $cortes = array_values(array_unique(array_filter($w['_cambiosMes'], fn ($f) => $f > $pd && $f <= $ph)));
        sort($cortes);
        $w['_cambiosMes'] = [];
        if (! $cortes) {
            return self::conceptos($w + ['_tramo' => true], $m, $inc, $P, $Y, $sinPagas);
        }
        $ini = array_merge([$pd], $cortes);
        $base = $w;
        $base['fAntig'] = ($w['fAntig'] ?? '') ?: ($w['alta'] ?? '');
        $L = $D = $av = [];
        $itCot = 0.0;
        $calSum = ['laborables' => 0, 'trabajados' => 0, 'efectivos' => 0, 'noRetrib' => 0, 'it' => 0, 'pagados' => 0];
        $acum = 0;
        $ult = count($ini) - 1;
        $grupos = [];
        foreach ($ini as $k => $d0) {
            $d1 = $k < $ult ? Carbon::parse($ini[$k + 1])->subDay()->toDateString() : $ph;
            $nd = (int) Carbon::parse($d0)->diffInDays(Carbon::parse($d1)) + 1;
            $n30 = $dias >= $dm && $k === $ult ? max(0, 30 - $acum) : $nd;
            $acum += $nd;
            $wt = array_merge($base, ['alta' => $d0, 'baja' => $d1, '_tramo' => true, '_n30' => $n30]);
            unset($wt['_cal']);
            $doc = self::conceptos($wt, $m, $k === $ult ? $inc : [], $P, $Y, $k === $ult ? $sinPagas : true);
            $wq = self::paraPeriodo($wt, $Y, $m);
            $grupos[] = (int) $wq['grupo'].'|'.self::n($wq['jor'] ?? 100);
            $suf = ' (del '.(int) substr($d0, 8, 2).' al '.(int) substr($d1, 8, 2).')';
            foreach ($doc['lineas'] as $l) {
                $porTramo = in_array($l['origen'] ?? '', ['convenio', 'ficha', 'calendario'], true) && ! (($l['clase'] ?? '') === 'extra' && ($l['cobro'] ?? '') === 'en_su_mes');
                if ($porTramo) {
                    $l['concepto'] .= $suf;
                    $l['regla'] = trim(($l['regla'] ?? '').' · condiciones vigentes desde el '.Formato::fecha($d0), ' ·');
                    $L[] = $l;
                } elseif ($k === $ult) {
                    $L[] = $l;
                }
            }
            if ($k === $ult) {
                $D = $doc['deducciones'];
                $conv = $doc['convenio'];
            }
            $itCot += (float) $doc['itCot'];
            foreach ($doc['avisosCal'] as $x) {
                if (! in_array($x, $av, true)) {
                    $av[] = $x;
                }
            }
            foreach ($calSum as $c => $v) {
                $calSum[$c] = $v + $doc['calendario'][$c];
            }
        }
        array_unshift($av, ['info', 'Mes calculado en '.count($ini).' tramos por el cambio de condiciones del '.implode(', ', array_map(fn ($f) => Formato::fecha($f), $cortes)).': cada tramo con sus condiciones (mes de 30 días).']);
        if (count(array_unique($grupos)) > 1) {
            $av[] = ['aviso', 'Cambian el grupo de cotización o la jornada dentro del mes: la base mínima se aplica con los del último tramo. Revísala.'];
        }

        return ['lineas' => $L, 'deducciones' => $D, 'tipos' => [], 'dias' => $dias, 'diasMes' => $dm, 'desde' => $pd, 'hasta' => $ph, 'itCot' => round($itCot, 2),
            'avisosCal' => $av, 'calendario' => $calSum, 'convenio' => $conv ?? [], 'tramos' => count($ini)];
    }

    /** Calcula la nómina (o el recibo) a partir de sus conceptos, automáticos o editados en el Integral de nóminas */
    public static function computar(array $w, array $doc, float $ip, float $at = 0): array
    {
        $recibo = $doc['recibo'] ?? 'Nómina';
        $L = array_values(array_filter($doc['lineas'] ?? [], fn ($l) => ($l['activo'] ?? true) && (float) ($l['importe'] ?? 0) != 0));
        $sum = fn ($f) => array_sum(array_map(fn ($l) => (float) $l['importe'], array_filter($L, $f)));
        $cl = fn ($l) => $l['clase'] ?? 'salarial';
        $sb = $sum(fn ($l) => ($l['id'] ?? '') === 'sb');
        $pl = $sum(fn ($l) => ($l['id'] ?? '') === 'pl');
        $hx = $sum(fn ($l) => $cl($l) === 'hx');
        $km = $sum(fn ($l) => ($l['id'] ?? '') === 'km');
        $die = $sum(fn ($l) => $cl($l) === 'no_salarial') - $km;
        $extra = $sum(fn ($l) => $cl($l) === 'extra' && ($l['cobro'] ?? '') !== 'prorrateada');
        $pror = $sum(fn ($l) => $cl($l) === 'extra' && ($l['cobro'] ?? '') === 'prorrateada');
        $esp = $sum(fn ($l) => $cl($l) === 'especie');
        $trib = $sum(fn ($l) => $cl($l) === 'tributa');
        $exe = $sum(fn ($l) => $cl($l) === 'exento');
        $salarial = $sum(fn ($l) => in_array($cl($l), ['salarial', 'especie'], true));
        $otr = $salarial - $sb - $pl;
        $dev = $sum(fn ($l) => true);
        $pagas = (int) ($w['pagas'] ?? 14) ?: 14;
        $prorrateadas = ! empty($w['prorr']) && $pagas === 14;
        if ($recibo === 'Nómina') {
            // Prorrata de las pagas que se cobran en su mes: entra en la base de cotización todos los meses
            $ex = self::extras($w);
            $prorrateadas = ! empty($w['prorr']) && $ex;
            $dC = (int) ($doc['dias'] ?? 30);
            $dM = (int) ($doc['diasMes'] ?? 30);
            $frB = self::fraccion($dC, $dM);
            $prorB = $ex ? ($prorrateadas ? $pror : round(array_sum(array_column($ex, 4)) / 12 * $frB, 2)) : 0;
            $jor = self::n($w['jor'] ?? 100) ?: 100;
            $g = (int) ($w['grupo'] ?? 7);
            $minB = round((isset(self::BASE_MIN_DIA[$g]) ? self::BASE_MIN_DIA[$g] * $dC : (self::BASE_MIN[$g] ?? self::BASE_MIN[7]) * $frB) * $jor / 100, 2);
            $maxB = round(self::BASE_MAX * $frB, 2);
            // Base de contingencias comunes (sin horas extra) y base de AT y EP, desempleo, FP y FOGASA (con horas extra)
            $itCot = round((float) ($doc['itCot'] ?? 0), 2);
            $bruta = round($salarial + ($prorrateadas ? $pror : $prorB) + $itCot, 2);
            $base = min(max($bruta, $minB), $maxB);
            $minTxt = isset(self::BASE_MIN_DIA[$g]) ? Formato::eur(self::BASE_MIN_DIA[$g]).' al día × '.$dC.' días' : Formato::eur(self::BASE_MIN[$g] ?? self::BASE_MIN[7]).' al mes'.($frB < 1 ? ' × '.$dC.'/30 días' : '');
            $baseExpl = ['Remuneración computable '.Formato::eur($salarial).' + prorrata de pagas extra '.Formato::eur($prorrateadas ? $pror : $prorB)
                .($itCot ? ' + base reguladora de los días de IT '.Formato::eur($itCot) : '').' = '.Formato::eur($bruta).'.'];
            if ($bruta < $minB) {
                $baseExpl[] = 'Es inferior a la base mínima del grupo '.$g.' ('.$minTxt.($jor < 100 ? ' × '.Formato::num($jor, 0).' % de jornada' : '').' = '.Formato::eur($minB).'): se cotiza por la mínima.';
            } elseif ($bruta > $maxB) {
                $baseExpl[] = 'Supera la base máxima ('.Formato::eur(self::BASE_MAX).($frB < 1 ? ' × '.$dC.'/30 días' : '').' = '.Formato::eur($maxB).'): se cotiza por la máxima.';
            }
            $baseExpl[] = 'Bases mínima y máxima: parámetros del sistema para '.self::PARAM_ANIO.' pendientes de contrastar con la Orden de cotización de ese año.';
        } else {
            // Recibos aparte: la paga extra ya cotiza con la prorrata mensual; atrasos, finiquito y salarios de tramitación
            // cotizan por sus conceptos salariales (en la liquidación complementaria o en la del mes de la baja)
            $prorB = 0;
            $base = $recibo === 'Extra' ? 0 : min($salarial, self::BASE_MAX);
            $baseExpl = [$recibo === 'Extra' ? 'La paga extra no cotiza en su mes: ya cotiza cada mes con la prorrata.' : 'Cotiza por sus conceptos salariales.'];
        }
        $baseAT = $recibo === 'Extra' ? 0 : min($base + $hx, self::BASE_MAX);
        $tmp = self::temporal($w);
        $des = $tmp ? self::TIPOS['des_tmp'] : self::TIPOS['des_ind'];
        $desT = isset($doc['tipos']['des_t']) && $doc['tipos']['des_t'] !== null && $doc['tipos']['des_t'] !== '' ? (float) $doc['tipos']['des_t'] / 100 : $des[0];
        $T = ['cc' => $base * self::TIPOS['cc'][0], 'mei' => $base * self::TIPOS['mei'][0], 'des' => $baseAT * $desT, 'fp' => $baseAT * self::TIPOS['fp'][0], 'hx' => $hx * self::TIPOS['cc'][0]];
        $E = ['cc' => $base * self::TIPOS['cc'][1], 'mei' => $base * self::TIPOS['mei'][1], 'des' => $baseAT * $des[1], 'fog' => $baseAT * self::TIPOS['fog'][1], 'fp' => $baseAT * self::TIPOS['fp'][1], 'at' => $baseAT * $at / 100, 'hx' => $hx * self::TIPOS['cc'][1]];
        if (self::esAutonomo($w) || $recibo === 'Extra') {
            // RETA: no cotiza al Régimen General por esta retribución. Paga extra en recibo aparte: cotizada con la prorrata.
            [$base, $baseAT, $prorB] = [0, 0, self::esAutonomo($w) ? 0 : $prorB];
            $T = array_map(fn () => 0, $T);
            $E = array_map(fn () => 0, $E);
        }
        $T = array_map(fn ($v) => round($v, 2), $T);
        $E = array_map(fn ($v) => round($v, 2), $E);
        $ssT = round(array_sum($T), 2);
        $baseIrpf = $dev - $die - $km - $exe;
        $irpf = round($baseIrpf * $ip / 100, 2);
        // Embargos: importe del art. 607 LEC sobre el salario neto, o importe fijo; nunca más que lo pendiente
        $neto = max(0, $dev - $die - $km - $exe - $esp - $ssT - $irpf);
        $smi = self::SMI_ANUAL / ($prorrateadas || $pagas === 12 ? 12 : 14) * ($extra > 0 && $recibo === 'Nómina' ? 2 : 1) * ($recibo === 'Nómina' ? self::fraccion((int) ($doc['dias'] ?? 30), (int) ($doc['diasMes'] ?? 30)) : 1);
        $libre = self::embargable($neto, $smi);
        $D = [];
        foreach ($doc['deducciones'] ?? [] as $d) {
            if (! empty($d['ref'])) {
                $pend = max(0, (float) ($d['pend'] ?? 0));
                $imp = ($d['modo'] ?? 'lec') === 'fijo' ? (float) ($d['cuota'] ?? 0) : $libre;
                $d['importe'] = round(max(0, min($imp, $pend, ($d['modo'] ?? 'lec') === 'fijo' ? $neto : $libre)), 2);
                if (($d['modo'] ?? 'lec') !== 'fijo') {
                    $libre -= $d['importe'];
                }
                $neto -= $d['importe'];
            }
            if ((float) ($d['importe'] ?? 0) != 0) {
                $D[] = $d;
            }
        }
        $ded = round(array_sum(array_map(fn ($l) => (float) $l['importe'], $D)), 2);
        $ant = array_sum(array_map(fn ($l) => (float) $l['importe'], array_filter($D, fn ($l) => ($l['id'] ?? '') === 'ant')));
        $emb = $ded - $ant;

        $baseExpl ??= [];

        return compact('baseExpl', 'sb', 'pl', 'extra', 'prorB', 'hx', 'otr', 'die', 'km', 'ant', 'emb', 'dev', 'base', 'baseAT', 'T', 'E', 'ssT', 'ip', 'baseIrpf', 'irpf', 'tmp', 'pror', 'desT', 'esp', 'trib', 'exe', 'recibo')
            + ['mensual' => $sb + $pl, 'liq' => round($dev - $ssT - $irpf - $ded - $esp, 2), 'ssE' => round(array_sum($E), 2), 'lineas' => $L, 'deducciones' => $D, 'ded' => $ded];
    }

    /** Parte embargable de un salario neto mensual según la escala del art. 607 LEC (el SMI es inembargable) */
    public static function embargable(float $neto, float $smi): float
    {
        $x = $neto - $smi;
        $r = 0;
        foreach ([0.30, 0.50, 0.60, 0.75] as $p) {
            if ($x <= 0) {
                return round($r, 2);
            }
            $t = min($x, $smi);
            $r += $t * $p;
            $x -= $t;
        }

        return round($r + max(0, $x) * 0.90, 2);
    }

    /** Embargos del trabajador vigentes en el mes, con lo que queda pendiente (descontado lo retenido en nóminas guardadas anteriores) */
    public static function embargosActivos(array $w, ?int $anio, int $m): array
    {
        $out = [];
        $ym = $anio ? sprintf('%04d-%02d', $anio, $m) : null;
        foreach ($w['embargos'] ?? [] as $e) {
            if (! empty($e['levantado']) || ($ym && (($e['desde'] ?? '0000-00') > $ym))) {
                continue;
            }
            $ret = $ym && ! empty($w['id']) ? self::retenidoEmbargo((int) $w['id'], (string) $e['id'], $ym) : 0;
            $pend = round((float) $e['total'] - $ret, 2);
            if ($pend <= 0) {
                continue;
            }
            $out[] = ['id' => 'emb', 'ref' => $e['id'], 'concepto' => 'Embargo · '.$e['organismo'].($e['expediente'] ? ' · exp. '.$e['expediente'] : ''),
                'modo' => $e['modo'] ?? 'lec', 'cuota' => (float) ($e['cuota'] ?? 0), 'pend' => $pend, 'total' => (float) $e['total'], 'importe' => 0];
        }

        return $out;
    }

    private static array $retenidos = [];

    /** Lo ya retenido de un embargo en las nóminas guardadas de meses anteriores */
    public static function retenidoEmbargo(int $tid, string $ref, string $ym): float
    {
        self::$retenidos[$tid] ??= NominaMes::where('trabajador_id', $tid)->get()->map(fn ($g) => [sprintf('%04d-%02d', $g->anio, $g->mes), $g->datos['retenido'] ?? []])->all();
        $t = 0;
        foreach (self::$retenidos[$tid] as [$k, $r]) {
            if ($k < $ym) {
                $t += (float) ($r[$ref] ?? 0);
            }
        }

        return round($t, 2);
    }

    /** Conceptos por defecto de un recibo aparte (paga extra, atrasos, finiquito o salarios de tramitación) */
    public static function conceptosRecibo(array $w, string $recibo, int $anio, int $m, ?array $P = null): array
    {
        $pagas = (int) ($w['pagas'] ?? 14) ?: 14;
        $pro = ! empty($w['prorr']) && $pagas === 14;
        $sb = self::diario($w) ? self::pagaExtra($w) : (self::n($w['salBase'] ?? 0) ?: round(self::n($w['sal'] ?? 0) / $pagas, 2));
        $pl = self::n($w['plus'] ?? 0);
        $L = [];
        if ($recibo === 'Extra') {
            foreach (empty($w['prorr']) ? self::extras($w, $anio, $m) : [] as [$id, $cod, $nom, $mc, $imp]) {
                $L[] = ['id' => $id, 'cod' => $cod, 'concepto' => $nom, 'importe' => $m === $mc ? round($imp, 2) : 0, 'clase' => 'extra', 'cobro' => 'en_su_mes', 'origen' => 'calculo', 'conv' => null];
            }
        } elseif ($recibo === 'Atrasos') {
            [$L, $av] = self::atrasos($w, $anio, $m);
        } elseif ($recibo === 'Sal. tramitación') {
            $L[] = ['id' => 'stm', 'cod' => '24', 'concepto' => 'Salarios de tramitación', 'importe' => 0, 'clase' => 'salarial', 'origen' => 'calculo', 'conv' => null];
        } elseif ($recibo === 'Finiquito') {
            [$L, $av] = self::finiquito($w, $anio, $m, $P, $sb, $pl, $pagas, $pro);
        }

        return ['lineas' => $L, 'deducciones' => [], 'tipos' => [], 'recibo' => $recibo, 'avisosCal' => $av ?? []];
    }

    /**
     * Atrasos: para cada mes anterior del año con la nómina emitida, se recalculan sus conceptos automáticos con el convenio y las condiciones
     * que rigen hoy para ese mes (revisión salarial con efectos retroactivos) y se paga la diferencia frente a lo emitido. [lineas, avisos]
     */
    private static function atrasos(array $w, int $anio, int $m): array
    {
        $L = [];
        $av = [['info', 'Los atrasos cotizan en una liquidación complementaria de los meses a los que corresponden, no en la del mes de pago.']];
        $auto = fn ($l) => in_array($l['origen'] ?? '', ['convenio', 'ficha', 'calendario'], true) || in_array($l['id'] ?? '', ['sb', 'pl', 'ant'], true);
        $porConcepto = fn ($ls) => collect($ls)->filter($auto)->groupBy(fn ($l) => preg_replace('/ \(del \d+ al \d+\)$/', '', $l['concepto']))->map(fn ($g) => round($g->sum(fn ($l) => (float) $l['importe']), 2));
        for ($mes = 1; $mes < $m; $mes++) {
            $g = ! empty($w['id']) ? self::guardada((int) $w['id'], $anio, $mes) : null;
            if (! $g?->emitida()) {
                continue;
            }
            $antes = $porConcepto($g->datos['calc']['lineas'] ?? $g->datos['lineas'] ?? []);
            $inc = ! empty($w['_cid']) ? (LaboralMes::where('cliente_id', $w['_cid'])->where('anio', $anio)->where('mes', $mes)->first()?->incidencias[$w['id']] ?? []) : [];
            $ahora = $porConcepto(self::conceptos($w, $mes, $inc, null, $anio)['lineas']);
            $cambios = [];
            $neto = 0.0;
            foreach ($antes->keys()->merge($ahora->keys())->unique() as $c) {
                $d = round(($ahora[$c] ?? 0) - ($antes[$c] ?? 0), 2);
                if (abs($d) >= 0.01) {
                    $cambios[] = $c.': '.Formato::num($antes[$c] ?? 0).' → '.Formato::num($ahora[$c] ?? 0).' ('.($d > 0 ? '+' : '').Formato::num($d).')';
                    $neto += $d;
                }
            }
            $neto = round($neto, 2);
            if ($neto > 0) {
                $L[] = ['id' => 'atr'.$mes, 'cod' => '23', 'concepto' => 'Atrasos de '.mb_strtolower(self::MESES[$mes - 1]).' de '.$anio, 'importe' => $neto, 'clase' => 'salarial', 'origen' => 'calculo', 'conv' => null,
                    'expl' => implode(' · ', $cambios), 'regla' => 'Diferencia entre la nómina emitida y el cálculo con el convenio y las condiciones vigentes para ese mes'];
            } elseif ($neto < 0) {
                $av[] = ['aviso', mb_strtolower(self::MESES[$mes - 1]).': con las condiciones de hoy saldría '.Formato::eur(-$neto).' menos de lo pagado. No se descuenta solo: revísalo.'];
            }
        }
        if (! $L) {
            $av[] = ['info', 'No hay diferencias a favor del trabajador en las nóminas emitidas de este año anteriores a este mes.'];
        }

        return [$L, $av];
    }

    /** Finiquito: vacaciones no disfrutadas, partes proporcionales de las pagas y la indemnización que fija el ET según la causa de la baja */
    private static function finiquito(array $w, int $anio, int $m, ?array $P, float $sb, float $pl, int $pagas, bool $pro): array
    {
        $fin = Carbon::parse($w['baja'] ?: Carbon::create($anio, $m, 1)->endOfMonth()->toDateString())->startOfDay();
        $alta = Carbon::parse($w['alta'] ?: $fin->toDateString())->startOfDay();
        $anual = self::n($w['sal'] ?? 0) ?: (self::diario($w) ? self::anual($w) : ($sb + $pl) * $pagas);
        $diario = round($anual / 365, 2);
        $L = [];
        // Vacaciones: días del convenio (30 naturales si no dice otra cosa) en proporción a lo trabajado en el año, menos las disfrutadas
        $ini = $alta->greaterThan($fin->copy()->startOfYear()) ? $alta->copy() : $fin->copy()->startOfYear();
        $vacAnio = (float) ($P['conv']['vacaciones'] ?? 30) ?: 30;
        $devengadas = $vacAnio * ($ini->diffInDays($fin) + 1) / 365;
        $disfrutadas = ! empty($w['id']) ? JornadaAusencia::where('trabajador_id', $w['id'])->where('tipo', 'Vacaciones')->where('estado', '!=', 'descartada')->get()
            ->sum(function ($a) use ($ini, $fin) {
                $d = $a->desde->greaterThan($ini) ? $a->desde : $ini;
                $h = $a->hasta->lessThan($fin) ? $a->hasta : $fin;

                return $h->greaterThanOrEqualTo($d) ? $d->diffInDays($h) + 1 : 0;
            }) : 0;
        $pend = round(max(0, $devengadas - $disfrutadas), 2);
        $L[] = ['id' => 'vac', 'cod' => '22', 'concepto' => 'Vacaciones no disfrutadas', 'cant' => $pend, 'precio' => $diario, 'importe' => round($pend * $diario, 2), 'clase' => 'salarial', 'origen' => 'calculo', 'conv' => null];
        // Partes proporcionales de las pagas que no se cobran prorrateadas (devengo anual hasta su mes de cobro)
        $av = [];
        if (empty($w['prorr'])) {
            foreach (self::extras($w, $fin->year, $fin->month) as [$id, $cod, $nom, $mc, $imp, $dev]) {
                // Periodo de devengo en curso a la fecha de baja (el de la próxima paga) y lo ya pagado de ese periodo
                $cy = $fin->month <= $mc ? $fin->year : $fin->year + 1;
                [$di, $df] = self::periodoDevengo($dev, $cy, $mc);
                if ($di->greaterThan($fin)) {
                    [$di, $df] = self::periodoDevengo($dev, $cy - 1, $mc);
                }
                [$tot, $cuenta, $dsc] = self::diasDevengo($w, $di, $df, $fin);
                $deveng = round($imp * $cuenta / $tot, 2);
                $pagado = 0.0;
                if (! empty($w['id'])) {
                    foreach (NominaMes::where('trabajador_id', $w['id'])->where('estado', 'Emitida')->whereIn('recibo', ['Nómina', 'Extra'])->get() as $g) {
                        $f = Carbon::create($g->anio, $g->mes, 1);
                        if ($f->between($di->copy()->startOfMonth(), $df)) {
                            $pagado += collect($g->datos['calc']['lineas'] ?? $g->datos['lineas'] ?? [])->where('id', $id)->sum(fn ($l) => (float) $l['importe']);
                        }
                    }
                }
                $pend = round($deveng - $pagado, 2);
                if ($pend < 0) {
                    $av[] = ['aviso', $nom.': ya se pagó '.Formato::eur($pagado).' y solo se han devengado '.Formato::eur($deveng).' hasta la baja: hay '.Formato::eur(-$pend).' cobrados de más. No se descuenta solo.'];
                }
                $L[] = ['id' => 'pp'.$id, 'cod' => $cod, 'concepto' => 'Parte proporcional '.mb_strtolower($nom), 'cant' => $cuenta, 'precio' => round($imp / $tot, 4),
                    'importe' => max(0.0, $pend), 'clase' => 'extra', 'cobro' => 'en_su_mes', 'origen' => 'calculo', 'conv' => null,
                    'expl' => Formato::num($imp).' € × '.$cuenta.' / '.$tot.' días devengados del '.Formato::fecha($di->toDateString()).' al '.Formato::fecha($fin->toDateString())
                        .($dsc ? ' (descontados '.$dsc.' de IT o ausencia no retribuida)' : '').' = '.Formato::num($deveng).' €'.($pagado ? ' − '.Formato::num($pagado).' € ya pagados en nóminas emitidas' : ''),
                    'regla' => 'Convenio: pagas extraordinarias, periodo de devengo'];
            }
        }
        // Indemnización legal según la causa de la baja (art. 49.1.c, 53.1.b y 56 ET)
        $causa = ! empty($w['id']) ? (string) LaboralMovimiento::where('trabajador_id', $w['id'])->where('tipo', 'Baja')->orderByDesc('fecha')->value('detalle') : '';
        $dpa = ['Fin de contrato' => 12, 'Despido objetivo' => 20][$causa] ?? 0;
        if ($dpa) {
            $anios = ($alta->diffInDays($fin) + 1) / 365;
            $imp = round($diario * $dpa * $anios, 2);
            if ($dpa === 20) {
                $imp = min($imp, round($anual, 2)); // tope de 12 mensualidades
            }
            $L[] = ['id' => 'ind', 'cod' => $dpa === 12 ? '61' : '60', 'concepto' => 'Indemnización por '.mb_strtolower($causa).' ('.$dpa.' días por año)', 'cant' => round($dpa * $anios, 2), 'precio' => $diario,
                'importe' => $imp, 'clase' => 'exento', 'origen' => 'calculo', 'conv' => null];
        }

        return [$L, $av];
    }

    /** Avisos del calendario del mes: cambios de condiciones dentro del mes, propuestas sin revisar y ausencias que afectan a la cotización */
    private static function avisosCalendario(array $w, array $cal): array
    {
        $av = [];
        foreach (! empty($w['_tramo']) ? [] : ($w['_cambiosMes'] ?? []) as $f) {
            $av[] = ['aviso', 'Las condiciones del contrato cambian el '.Formato::fecha($f).'.'];
        }
        if ($cal['pendientes']) {
            $av[] = ['aviso', $cal['pendientes'].' '.($cal['pendientes'] === 1 ? 'ausencia del control horario está pendiente' : 'ausencias del control horario están pendientes').' de revisar: no cuentan en esta nómina hasta que las confirmes en la pestaña Calendario.'];
        }
        if ($cal['noRetrib']) {
            $av[] = ['aviso', $cal['noRetrib'].' días de ausencia no retribuida: se descuentan del salario. Revisa la cotización de esos días según cómo se comuniquen a la Seguridad Social (permiso sin sueldo, huelga…); la base mínima se aplica sobre los días en alta.'];
        }
        if ($cal['aus']->contains(fn ($a) => $a->tipo === 'Asuntos propios')) {
            $av[] = ['info', 'Asuntos propios se tratan como retribuidos: compruébalo con el convenio.'];
        }

        return $av;
    }

    /** Avisos de lo que no sale del convenio o de la ley */
    public static function avisos(array $w, array $doc, ?float $ipAuto): array
    {
        $out = [];
        foreach ($doc['lineas'] ?? [] as $i => $l) {
            if (($l['activo'] ?? true) === false) {
                continue;
            }
            if (in_array($l['origen'] ?? '', ['manual', 'fijo'], true)) {
                $out[$i] = ($l['origen'] === 'fijo' ? 'Concepto fijo añadido a mano' : 'Concepto añadido a mano').': no es por convenio.';
            } elseif (isset($l['conv']) && $l['conv'] !== null && abs((float) $l['importe'] - (float) $l['conv']) > 0.005) {
                $out[$i] = 'No es por convenio: el convenio fija '.Formato::eur((float) $l['conv']).'.';
            }
        }
        $info = self::esAutonomo($w) ? ['Trabajador en el RETA: esta nómina no lleva cotización al Régimen General (ni del trabajador ni de la empresa) y no va a los seguros sociales; solo retención de IRPF. Si la empresa le paga la cuota de autónomos, añádela como concepto salarial: es rendimiento del trabajo.'] : [];
        $g = [];
        $errores = [];
        $calAv = [];
        $des = (self::temporal($w) ? self::TIPOS['des_tmp'][0] : self::TIPOS['des_ind'][0]) * 100;
        if (isset($doc['tipos']['des_t']) && $doc['tipos']['des_t'] !== null && $doc['tipos']['des_t'] !== '' && abs((float) $doc['tipos']['des_t'] - $des) > 0.0001) {
            $g[] = 'Desempleo al '.Formato::num((float) $doc['tipos']['des_t']).' %: el tipo legal es el '.Formato::num($des).' %.';
        }
        if ($ipAuto !== null && isset($doc['tipos']['irpf']) && $doc['tipos']['irpf'] !== null && $doc['tipos']['irpf'] !== '' && abs((float) $doc['tipos']['irpf'] - $ipAuto) > 0.0001) {
            $g[] = 'IRPF al '.Formato::num((float) $doc['tipos']['irpf']).' %: el que corresponde (ficha del trabajador o algoritmo de la AEAT) es el '.Formato::num($ipAuto).' %.';
        }

        foreach ($doc['avisosCal'] ?? [] as [$nv, $t]) {
            $nv === 'error' ? $errores[] = $t : ($nv === 'aviso' ? $calAv[] = $t : $info[] = $t);
        }

        return ['lineas' => $out, 'generales' => $g, 'info' => $info, 'errores' => $errores, 'calendario' => $calAv];
    }

    private static function escala(float $b): float
    {
        $p = 0;
        $x = 0;
        foreach (self::ESCALA as [$l, $r]) {
            if ($b <= $p) {
                break;
            }
            $x += (min($b, $l) - $p) * $r / 100;
            $p = $l;
        }

        return $x;
    }

    /** Tipo anual de retención (irpfCalc) */
    public static function irpfAnual(array $w, float $RT, float $SS, bool $temporal, int $anio): array
    {
        $M = $w['m145'] ?? [];
        $hijos = array_values(array_filter($M['hijos'] ?? [], fn ($h) => (int) ($h['anio'] ?? 0) > 1900 && (int) $h['anio'] <= $anio));
        $asc = array_values(array_filter($M['asc'] ?? [], fn ($a) => (int) ($a['anio'] ?? 0) > 1900));
        $sit = (int) ($M['sit'] ?? 3) ?: 3;
        $nh = min(count($hijos), 2);
        $lim = (self::LIMITES[$sit] ?? self::LIMITES[3])[$nh] ?: self::LIMITES[3][$nh];
        if ($RT <= $lim) {
            return ['tipo' => $temporal && $RT > 0 ? 2 : 0, 'cuota' => 0, 'lim' => $lim, 'RN' => $RT - $SS, 'red' => 0, 'gastos' => 0, 'base' => 0, 'minimo' => 0, 'exento' => true];
        }
        $RN = max(0, $RT - $SS);
        $red = $RN <= 14852 ? 7302 : ($RN <= 17673.52 ? 7302 - 1.75 * ($RN - 14852) : ($RN <= 19747.5 ? 2364.34 - 1.14 * ($RN - 17673.52) : 0));
        $disc = $w['disc'] ?? 'Sin discapacidad';
        $gastos = 2000 + (! empty($M['movil']) ? 2000 : 0) + ($disc === '33 % a 64 %' ? 3500 : ($disc === '65 % o más' ? 7750 : 0));
        $base = max(0, $RN - $red - $gastos - self::n($M['pension'] ?? 0));
        $edad = ! empty($w['fnac']) ? $anio - (int) substr($w['fnac'], 0, 4) : 40;
        $minimo = 5550 + ($edad >= 65 ? 1150 : 0) + ($edad >= 75 ? 1400 : 0) + ($disc === '33 % a 64 %' ? 3000 : ($disc === '65 % o más' ? 12000 : 0));
        usort($hijos, fn ($a, $b) => (int) $a['anio'] <=> (int) $b['anio']);
        foreach ($hijos as $i => $h) {
            $v = [2400, 2700, 4000][$i] ?? 4500;
            if ($anio - (int) $h['anio'] < 3) {
                $v += 2800;
            }
            $hd = $h['disc'] ?? 'No';
            $v += $hd === '33 % a 64 %' ? 3000 : ($hd === '65 % o más' ? 9000 : 0);
            $minimo += ($h['entero'] ?? true) === false ? $v / 2 : $v;
        }
        foreach ($asc as $a) {
            $e = $anio - (int) $a['anio'];
            $ad = $a['disc'] ?? 'No';
            $v = ($e >= 65 || $ad !== 'No') ? 1150 : 0;
            if ($e >= 75) {
                $v += 1400;
            }
            $v += $ad === '33 % a 64 %' ? 3000 : ($ad === '65 % o más' ? 9000 : 0);
            $conv = (int) ($a['conv'] ?? 1) ?: 1;
            $minimo += $conv > 1 ? $v / $conv : $v;
        }
        $cuota = max(0, self::escala($base) - self::escala($minimo));
        $cuota = min($cuota, 0.43 * ($RT - $lim));
        if (! empty($M['vivienda']) && $RT < 33007.2) {
            $cuota = max(0, $cuota - $RT * 0.02);
        }
        $tipo = floor($cuota / $RT * 10000) / 100;
        if ($temporal && $tipo < 2) {
            $tipo = 2;
        }

        return compact('tipo', 'cuota', 'lim', 'RN', 'red', 'gastos', 'base', 'minimo') + ['exento' => false];
    }

    /** Tipo de IRPF del mes $k con regularización de lo ya retenido (irpfOf) */
    public function tipoIrpf(array $w, int $anio, int $k, array $incAnio): array
    {
        $key = ($w['id'] ?? 'n').'|'.$anio.'|'.$k;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        if (($w['irpfModo'] ?? 'auto') === 'fijo') {
            return $this->memo[$key] = ['tipo' => self::n($w['irpf'] ?? 0), 'fijo' => true];
        }
        $inc = fn ($x) => $incAnio[$x][$w['id'] ?? ''] ?? [];
        $months = array_values(array_filter(range(1, 12), fn ($x) => self::activo($w, $anio, $x)));
        $RT = $SS = $devPrev = $retPrev = 0;
        foreach ($months as $x) {
            $n0 = self::calcular($w, $x, $x <= $k ? $inc($x) : [], 0);
            $RT += $n0['baseIrpf'];
            $SS += $n0['ssT'];
            if ($x < $k) {
                $devPrev += $n0['baseIrpf'];
            }
        }
        $r = self::irpfAnual($w, $RT, $SS, self::temporal($w), $anio);
        foreach ($months as $x) {
            if ($x < $k) {
                $retPrev += self::calcular($w, $x, $inc($x), $this->tipoIrpf($w, $anio, $x, $incAnio)['tipo'])['irpf'];
            }
        }
        $pend = $RT - $devPrev;
        $tipo = $r['tipo'];
        if ($months && $k > $months[0] && $pend > 0) {
            $tipo = max(self::temporal($w) ? 2 : 0, floor(max(0, $r['cuota'] - $retPrev) / $pend * 10000) / 100);
        }

        return $this->memo[$key] = $r + ['tipo' => $tipo, 'tipoAnual' => $r['tipo'], 'RT' => $RT, 'SS' => $SS, 'retPrev' => $retPrev, 'fijo' => false];
    }

    /** Previsión del IRPF de una ficha (sin regularización), para la sección «IRPF · modelo 145» */
    public static function previsionIrpf(array $w, int $anio, array $incAnio): array
    {
        $RT = $SS = 0;
        foreach (range(1, 12) as $x) {
            if (self::activo($w, $anio, $x)) {
                $n0 = self::calcular($w, $x, $incAnio[$x][$w['id'] ?? ''] ?? [], 0);
                $RT += $n0['baseIrpf'];
                $SS += $n0['ssT'];
            }
        }

        return self::irpfAnual($w, $RT, $SS, self::temporal($w), $anio) + ['RT' => $RT, 'SS' => $SS];
    }

    private static array $guardadas = [];

    /** Recibo editado (borrador o emitido) de un trabajador y mes, si existe */
    public static function guardada(int $trabajadorId, int $anio, int $m, string $recibo = 'Nómina'): ?NominaMes
    {
        $k = $trabajadorId.'|'.$anio;
        self::$guardadas[$k] ??= NominaMes::where('trabajador_id', $trabajadorId)->where('anio', $anio)->get()->keyBy(fn ($g) => $g->mes.'|'.$g->recibo)->all();

        return self::$guardadas[$k][$m.'|'.$recibo] ?? null;
    }

    /** Recibos aparte (paga extra, atrasos, finiquito…) guardados para un trabajador y mes */
    public static function otrosRecibos(int $trabajadorId, int $anio, int $m): array
    {
        self::guardada($trabajadorId, $anio, $m);

        return collect(self::$guardadas[$trabajadorId.'|'.$anio])->filter(fn ($g) => $g->mes === $m && $g->recibo !== 'Nómina')
            ->sortBy(fn ($g) => array_search($g->recibo, array_keys(self::RECIBOS), true))->pluck('recibo')->values()->all();
    }

    public static function olvidarGuardadas(): void
    {
        self::$guardadas = [];
        self::$retenidos = [];
    }

    /** Nómina (o recibo aparte) del mes: la editada en el Integral de nóminas si existe; si no, la automática */
    public function nomina(array $w, int $anio, int $m, array $incAnio, float $at, string $recibo = 'Nómina'): array
    {
        $w = self::paraPeriodo($w, $anio, $m);
        $ip = (float) $this->tipoIrpf($w, $anio, $m, $incAnio)['tipo'];
        $g = ! empty($w['id']) ? self::guardada((int) $w['id'], $anio, $m, $recibo) : null;
        if ($g && $g->emitida() && ! empty($g->datos['calc'])) {
            return $g->datos['calc'] + ['estado' => $g->estado, 'ipAuto' => $ip];
        }
        if ($g) {
            [$pd, $ph, $pdias, $pmes] = self::periodo($w, $anio, $m);
            // Una nómina emitida conserva los datos con los que se calculó; un borrador toma el periodo actual
            $doc = $g->emitida() ? ($g->datos ?? []) + ['recibo' => $recibo, 'dias' => $pmes, 'diasMes' => $pmes]
                : array_replace($g->datos ?? [], ['recibo' => $recibo, 'dias' => $pdias, 'diasMes' => $pmes, 'desde' => $pd, 'hasta' => $ph]);
            $ipG = isset($doc['tipos']['irpf']) && $doc['tipos']['irpf'] !== null && $doc['tipos']['irpf'] !== '' ? (float) $doc['tipos']['irpf'] : $ip;

            return self::computar($w, $doc, $ipG, $at) + ['estado' => $g->estado, 'ipAuto' => $ip];
        }
        $doc = $recibo === 'Nómina'
            ? self::conceptos($w, $m, $incAnio[$m][$w['id'] ?? ''] ?? [], null, $anio, ! empty($w['id']) && self::guardada((int) $w['id'], $anio, $m, 'Extra') !== null)
            : self::conceptosRecibo($w, $recibo, $anio, $m);

        return self::computar($w, $doc + ['recibo' => $recibo], $ip, $at) + ['estado' => null, 'ipAuto' => $ip];
    }

    /** Totales de varios recibos del mismo mes (para pagos, contabilidad y modelos 111 y 190) */
    public static function sumar(array $ns): array
    {
        $t = [];
        foreach (['dev', 'ssT', 'ssE', 'irpf', 'liq', 'baseIrpf', 'ded', 'ant', 'emb', 'die', 'km', 'base', 'baseAT', 'hx', 'esp', 'exe'] as $k) {
            $t[$k] = round(array_sum(array_map(fn ($n) => (float) ($n[$k] ?? 0), $ns)), 2);
        }

        return $t + ['ip' => (float) ($ns[0]['ip'] ?? 0)];
    }

    /** Todos los recibos de un trabajador en un mes: la nómina y los recibos aparte guardados */
    public function recibosMes(array $w, int $anio, int $m, array $incAnio, float $at): array
    {
        $n = $this->nomina($w, $anio, $m, $incAnio, $at);
        $otros = [];
        foreach (! empty($w['id']) ? self::otrosRecibos((int) $w['id'], $anio, $m) : [] as $r) {
            $otros[] = ['recibo' => $r, 'n' => $this->nomina($w, $anio, $m, $incAnio, $at, $r)];
        }

        return ['n' => $n, 'otros' => $otros, 't' => self::sumar(array_merge([$n], array_column($otros, 'n')))];
    }

    /** Nóminas del mes de los trabajadores en alta: [[w, n], ...] */
    public function mes(Cliente $c, int $anio, int $m, ?Collection $plantilla = null, ?array $incAnio = null, ?float $at = null): array
    {
        $plantilla ??= self::plantilla($c); // todos los trabajadores en alta, también los del RETA
        $incAnio ??= self::incidenciasAnio($c, $anio);
        $at ??= (float) self::config($c)['at'];

        return $plantilla->filter(fn ($w) => self::activo($w, $anio, $m))
            ->map(fn ($w) => ['w' => $w] + $this->recibosMes($w, $anio, $m, $incAnio, $at))->values()->all();
    }

    // -------------------------------------------------------------- contratos
    /** Cláusulas disponibles: las del diseño más las añadidas en Parámetros de contrato. [k => [título, texto]] */
    public static function clausulas(array $P): array
    {
        $o = self::CLAUSULAS;
        foreach ($P['clausExtra'] ?? [] as $x) {
            $o[$x['k']] = [$x['l'], $x['txt']];
        }

        return $o;
    }

    /** Cláusulas por defecto de una modalidad */
    public static function clausulasDe(array $P, string $codigo): array
    {
        return $P['contratos'][$codigo]['claus'] ?? $P['claus'];
    }

    /** Plantilla del cuerpo del contrato para una modalidad */
    public static function plantillaDe(array $P, string $codigo): string
    {
        $t = trim((string) ($P['contratos'][$codigo]['texto'] ?? ''));

        return $t !== '' ? $t : self::PLANTILLA_CONTRATO;
    }

    public static function valoresContrato(array $w, Cliente $c, array $P): array
    {
        $temp = self::temporal($w);
        $conv = $P['conv']['nombre'] ?: 'el convenio colectivo aplicable';

        return ['empresa' => $c->nombre, 'cif' => (string) $c->nif, 'ccc' => (string) $w['ccc'], 'trabajador' => (string) $w['nombre'], 'nif' => (string) $w['nif'], 'naf' => (string) $w['naf'],
            'categoria' => (string) ($w['convCat'] ?: $w['cat']), 'grupo' => (string) $w['grupo'], 'centro' => (string) ($w['centro'] ?: 'indicado'), 'inicio' => Formato::fecha($w['alta']), 'fin' => $temp ? Formato::fecha($w['fin']) : 'Indefinido',
            'duracion' => $temp ? 'El contrato se concierta por duración determinada desde el '.Formato::fecha($w['alta']).' hasta el '.Formato::fecha($w['fin']).' por la siguiente causa: '.$w['causa'].' (art. 15 ET).' : 'El contrato se concierta por tiempo indefinido desde el '.Formato::fecha($w['alta']).'.',
            'causa' => (string) $w['causa'], 'horas' => (string) $w['horas'], 'jornada' => Formato::num($w['jor'], 0).' %', 'horario' => (string) $w['horario'], 'retribucion' => Formato::eur(self::anual($w)), 'salario' => self::diario($w) ? Formato::eur(self::n($w['salDia'])).' diarios' : Formato::eur(self::n($w['salBase'])),
            'pagas' => (string) $w['pagas'], 'convenio' => $conv, 'prueba' => (string) $w['prueba'], 'vacaciones' => (string) $w['vac'], 'municipio' => (string) ($c->municipio ?: $c->provincia), 'contrato' => (string) $w['contrato']];
    }

    /** Texto del contrato a HTML: escapa, sustituye {marcadores}, **negrita** y párrafos. Devuelve [html, nº de cláusulas numeradas] */
    public static function renderContrato(string $texto, array $vals): array
    {
        $h = e($texto);
        $h = preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $vals) ? e($vals[$m[1]] !== '' ? $vals[$m[1]] : '—') : $m[0], $h);
        $n = preg_match_all('/\*\*[^*]+\*\*/', $texto);
        $h = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $h);
        $ps = preg_split('/\R{2,}/', trim($h));

        return [implode('', array_map(fn ($p) => '<p>'.nl2br(trim($p)).'</p>', $ps)), $n];
    }

    // ------------------------------------------------------ comprobación legal
    public static function comprobar(array $d, array $P, int $plantilla, string $hoy): array
    {
        $code = self::codigo((string) $d['contrato']);
        $temp = self::temporal($d);
        $tp = self::parcial($d);
        $jor = self::n($d['jor']);
        $anual = self::anual($d);
        $cat = collect($P['conv']['cats'])->firstWhere('cat', $d['convCat'] ?? '');
        $edad = ! empty($d['fnac']) ? (strtotime($hoy) - strtotime($d['fnac'])) / 31557600 : null;
        $meses = ! empty($d['fin']) && ! empty($d['alta']) ? (strtotime($d['fin']) - strtotime($d['alta'])) / 2629800 : 0;
        $pruebaM = ['Sin periodo de prueba' => 0, '15 días' => 0.5, '1 mes' => 1, '2 meses' => 2, '3 meses' => 3, '6 meses' => 6][$d['prueba']] ?? 0;
        $max = $temp && $meses <= 6 ? 1 : ((int) $d['grupo'] <= 2 ? 6 : ($plantilla < 25 ? 3 : 2));
        $l = [
            [self::dni((string) $d['nif'], (string) $d['tipoDoc']), 'Documento de identidad válido'],
            [(bool) preg_match('/^\d{12}$/', preg_replace('/\D/', '', (string) $d['naf'])), 'Número de afiliación de 12 dígitos'],
            [$edad === null || $edad >= 16, $edad === null ? 'Indica la fecha de nacimiento (edad mínima 16 años)' : 'Edad mínima de 16 años'],
            [! empty($d['ccc']), 'Código de cuenta de cotización asignado'],
            [$tp ? ($jor < 100 && $jor > 0) : $jor == 100, $tp ? 'Jornada parcial inferior al 100 %' : 'Jornada completa al 100 %'],
        ];
        if ($temp) {
            $l[] = [! empty($d['fin']) && $d['fin'] > $d['alta'], 'Fecha de fin posterior a la de inicio'];
            $l[] = [mb_strlen(trim((string) $d['causa'])) >= 20, 'Causa de la temporalidad descrita con precisión (art. 15 ET)'];
        }
        if (preg_match('/^(402|502)/', $code)) {
            $l[] = [$meses <= 6.05, 'Duración máxima de 6 meses (ampliable a 12 por convenio)'];
        }
        if (preg_match('/^(410|510)/', $code)) {
            $l[] = [mb_strlen(trim((string) $d['sustituido'])) > 5, 'Identificada la persona sustituida'];
        }
        if (preg_match('/^(42|52)/', $code)) {
            $l[] = [$meses >= 5.95 && $meses <= 12.05, 'Duración entre 6 meses y 1 año (art. 11.3 ET)'];
            $l[] = [array_search($d['nivForm'], self::NIVEL_FORMATIVO, true) >= 4, 'Titulación de FP o universitaria'];
        }
        // Categoría: solo es correcta si existe en las tablas del convenio (nunca un texto de relleno)
        if (! ($P['conv']['cats'] ?? [])) {
            $l[] = [false, 'El convenio no tiene tablas salariales: no se puede comprobar la categoría ni el salario mínimo', 'aviso'];
        } elseif (($d['convCat'] ?? '') === '') {
            $l[] = [false, 'Falta la categoría del convenio'];
        } elseif (! $cat) {
            $l[] = [false, 'La categoría «'.$d['convCat'].'» no existe en el convenio'];
        } elseif ($cat['base'] === null) {
            $l[] = [false, 'La categoría «'.$cat['cat'].'» no tiene salario base en el convenio: no se puede comprobar el mínimo', 'aviso'];
        } else {
            $esDia = ($cat['unidad'] ?? 'mes') === 'dia';
            $mio = $esDia ? self::n($d['salDia'] ?? 0) : (self::diario($d) ? self::n($d['salDia'] ?? 0) * 30 : self::n($d['salBase']));
            $l[] = [$mio + 0.001 >= (float) $cat['base'] * ($esDia ? 1 : self::n($d['jor']) / 100), 'Salario base igual o superior al del convenio para «'.$cat['cat'].'» ('.Formato::eur((float) $cat['base']).($esDia ? ' al día' : ' al mes a jornada completa').')'];
        }
        $l[] = [$anual + 0.01 >= self::SMI_ANUAL * $jor / 100, 'Retribución anual igual o superior al SMI de 2026 proporcional a la jornada'];
        $l[] = [$pruebaM <= $max, 'Periodo de prueba dentro del máximo legal ('.($max === 1 ? '1 mes' : $max.' meses').')'];
        $l[] = [! $tp || mb_strlen(trim((string) $d['horario'])) > 5, 'Distribución del horario indicada'];
        $l[] = [in_array($code, $P['hab'], true), 'Modalidad habilitada para la empresa'];

        return array_map(fn ($x) => ['ok' => (bool) $x[0], 'l' => $x[1], 'nivel' => $x[0] ? 'ok' : ($x[2] ?? 'error')], $l);
    }
}
