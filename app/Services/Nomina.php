<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\LaboralConfig;
use App\Models\LaboralMes;
use App\Models\Trabajador;
use App\Support\Formato;
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

    public const BASE_MAX = 5101.20;

    /** SMI anual de 2026 (14 pagas) usado en la comprobación legal del contrato */
    public const SMI_ANUAL = 17094.0;

    /** Tipos de cotización 2026 en tanto por uno: [trabajador, empresa] */
    public const TIPOS = ['cc' => [0.047, 0.236], 'mei' => [0.0015, 0.0075], 'des_ind' => [0.0155, 0.055], 'des_tmp' => [0.016, 0.067], 'fp' => [0.001, 0.006], 'fog' => [0, 0.002]];

    public const GRUPOS = ['1 · Ingenieros y licenciados', '2 · Ingenieros técnicos y peritos', '3 · Jefes administrativos y de taller', '4 · Ayudantes no titulados', '5 · Oficiales administrativos', '6 · Subalternos', '7 · Auxiliares administrativos'];

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
            'salBase' => 0, 'plus' => 0, 'pagas' => 14, 'prorr' => false, 'irpfModo' => 'auto', 'irpf' => 2,
            'm145' => ['sit' => '3', 'nifCony' => '', 'movil' => false, 'pension' => '', 'alimentos' => '', 'vivienda' => false, 'fecha' => '', 'hijos' => [], 'asc' => []],
            'claus' => ['conf', 'datos', 'desc', 'reg'], 'docs' => [], 'nombre' => '', 'cat' => '',
            'regimen' => 'General', 'autTipo' => self::TIPOS_AUTONOMO[0], 'autBase' => 0, 'autCuota' => 0, 'autRend' => 0, 'autMutua' => '', 'autCese' => true,
            'autTarifa' => false, 'autTarifaFin' => '', 'iae' => '',
        ], $w);
    }

    public static function anual(array $w): float
    {
        return self::n($w['salBase'] ?? 0) * ((int) ($w['pagas'] ?? 14) ?: 14) + self::n($w['plus'] ?? 0) * 12;
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

    public static function config(Cliente $c): array
    {
        $d = LaboralConfig::where('cliente_id', $c->id)->first()?->datos ?? [];
        $def = self::defectoConfig($c);
        $P = array_replace($def, $d);
        foreach (['conv', 'red', 'sepe'] as $k) {
            $P[$k] = array_replace($def[$k], $d[$k] ?? []);
        }
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
    /** Nómina de un mes con el tipo de IRPF $ip (lbNom2) */
    public static function calcular(array $w, int $m, array $inc, float $ip, float $at = 0): array
    {
        $pagas = (int) ($w['pagas'] ?? 14) ?: 14;
        $pro = ! empty($w['prorr']) && $pagas === 14;
        $sb = self::n($w['salBase'] ?? 0) ?: round(self::n($w['sal'] ?? 0) / $pagas, 2);
        $pl = self::n($w['plus'] ?? 0);
        $g = fn ($k) => self::n($inc[$k] ?? 0);
        [$hx, $die, $km, $otr, $ant, $emb] = [$g('hx'), $g('dietas'), $g('km'), $g('otros'), $g('anticipo'), $g('embargo')];
        $extra = $pagas === 14 && ! $pro && ($m === 6 || $m === 12) ? $sb : 0;
        $prorB = $pagas === 14 ? $sb * 2 / 12 : 0;
        $mensual = $sb + $pl;
        $dev = $mensual + $extra + ($pro ? $prorB : 0) + $hx + $otr + $die + $km;
        $jor = self::n($w['jor'] ?? 100) ?: 100;
        $minB = (self::BASE_MIN[(int) ($w['grupo'] ?? 7)] ?? self::BASE_MIN[7]) * $jor / 100;
        $base = min(max($mensual + $prorB + $hx + $otr, $minB), self::BASE_MAX);
        $tmp = self::temporal($w);
        $des = $tmp ? self::TIPOS['des_tmp'] : self::TIPOS['des_ind'];
        $T = ['cc' => $base * self::TIPOS['cc'][0], 'mei' => $base * self::TIPOS['mei'][0], 'des' => $base * $des[0], 'fp' => $base * self::TIPOS['fp'][0]];
        $E = ['cc' => $base * self::TIPOS['cc'][1], 'mei' => $base * self::TIPOS['mei'][1], 'des' => $base * $des[1], 'fog' => $base * self::TIPOS['fog'][1], 'fp' => $base * self::TIPOS['fp'][1], 'at' => $base * $at / 100];
        $ssT = array_sum($T);
        $baseIrpf = $dev - $die - $km;
        $irpf = round($baseIrpf * $ip / 100, 2);

        return compact('sb', 'pl', 'mensual', 'extra', 'prorB', 'hx', 'otr', 'die', 'km', 'ant', 'emb', 'dev', 'base', 'T', 'E', 'ssT', 'ip', 'baseIrpf', 'irpf', 'tmp')
            + ['pror' => $pro ? $prorB : 0, 'liq' => $dev - $ssT - $irpf - $ant - $emb, 'ssE' => array_sum($E)];
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

    public function nomina(array $w, int $anio, int $m, array $incAnio, float $at): array
    {
        $ip = $this->tipoIrpf($w, $anio, $m, $incAnio)['tipo'];

        return self::calcular($w, $m, $incAnio[$m][$w['id'] ?? ''] ?? [], (float) $ip, $at);
    }

    /** Nóminas del mes de los trabajadores en alta: [[w, n], ...] */
    public function mes(Cliente $c, int $anio, int $m, ?Collection $plantilla = null, ?array $incAnio = null, ?float $at = null): array
    {
        $plantilla = ($plantilla ?? self::plantilla($c))->reject(fn ($w) => self::esAutonomo($w));
        $incAnio ??= self::incidenciasAnio($c, $anio);
        $at ??= (float) self::config($c)['at'];

        return $plantilla->filter(fn ($w) => self::activo($w, $anio, $m))
            ->map(fn ($w) => ['w' => $w, 'n' => $this->nomina($w, $anio, $m, $incAnio, $at)])->values()->all();
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
            'causa' => (string) $w['causa'], 'horas' => (string) $w['horas'], 'jornada' => Formato::num($w['jor'], 0).' %', 'horario' => (string) $w['horario'], 'retribucion' => Formato::eur(self::anual($w)), 'salario' => Formato::eur(self::n($w['salBase'])),
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
        $l[] = [! $cat || self::n($d['salBase']) + 0.001 >= (float) $cat['base'], $cat ? 'Salario base igual o superior al del convenio ('.Formato::eur((float) $cat['base']).')' : 'Categoría del convenio asignada'];
        $l[] = [$anual + 0.01 >= self::SMI_ANUAL * $jor / 100, 'Retribución anual igual o superior al SMI de 2026 proporcional a la jornada'];
        $l[] = [$pruebaM <= $max, 'Periodo de prueba dentro del máximo legal ('.($max === 1 ? '1 mes' : $max.' meses').')'];
        $l[] = [! $tp || mb_strlen(trim((string) $d['horario'])) > 5, 'Distribución del horario indicada'];
        $l[] = [in_array($code, $P['hab'], true), 'Modalidad habilitada para la empresa'];

        return array_map(fn ($x) => ['ok' => (bool) $x[0], 'l' => $x[1]], $l);
    }
}
