<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\LaboralConfig;
use App\Models\ParametroNormativo;
use App\Models\User;
use App\Services\Nomina;
use App\Services\Normativa;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Parámetros normativos con fecha de efecto y fuente: la nómina usa los de su periodo, avisa si faltan y calcula la cotización de solidaridad */
class NormativaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-20 10:00:00');
        CarbonImmutable::setTestNow('2026-12-20 10:00:00');
        $this->a = $this->cliente('Construcciones Uno SL', 'B21890124');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function w(array $d = []): array
    {
        return Nomina::rellenar(array_merge(['id' => 0, 'contrato' => Nomina::CONTRATOS[0], 'grupo' => 3, 'salBase' => 1800, 'pagas' => 14, 'jor' => 100, 'alta' => '2020-01-01', 'prorr' => true], $d), $this->a);
    }

    private function calc(array $w, int $anio, int $m): array
    {
        return Nomina::computar($w, Nomina::conceptos($w, $m, [], null, $anio) + ['recibo' => 'Nómina'], 0);
    }

    public function test_valores_de_2026_cargados_con_su_fuente(): void
    {
        $c = Normativa::get('cotizacion', '2026-11-01');
        $this->assertSame([5101.2, 1989.3, 0.236, true], [(float) $c['valor']['base_max'], (float) $c['valor']['base_min']['1'], (float) $c['valor']['tipos']['cc'][1], $c['exacto']]);
        $this->assertStringContainsString('Orden PJC/297/2026', $c['fuente']);
        $this->assertSame(17094.0, (float) Normativa::v('smi', '2026-06-01')['anual']);
        $this->assertStringContainsString('Real Decreto 126/2026', Normativa::get('smi', '2026-06-01')['fuente']);
        // El IRPF, cotejado con el algoritmo oficial de la AEAT 2026; la tarifa de AT y EP, pendiente de cargar
        $this->assertEqualsCanonicalizing(['cotizacion' => 'resumen', 'smi' => 'resumen', 'irpf' => 'verificado', 'it' => 'pendiente', 'atep' => 'pendiente'], ParametroNormativo::pluck('estado', 'clave')->sortKeys()->only(['cotizacion', 'smi', 'irpf', 'it', 'atep'])->all() + []);
        $this->assertSame(33.62, (float) Normativa::v('cotizacion', '2026-06-01')['temporal_corto']);
    }

    public function test_cotizacion_adicional_de_solidaridad_por_tramos(): void
    {
        // 7.000 € + prorrata 1.166,66 € = 8.166,66 €; exceso sobre 5.101,20 € = 3.065,46 €
        $n = $this->calc($this->w(['salBase' => 7000]), 2026, 11);
        $this->assertSame(5101.2, (float) $n['base']);
        $tot = 510.12 * 0.0115 + (2550.60 - 510.12) * 0.0125 + (3065.46 - 2550.60) * 0.0146;
        $this->assertEqualsWithDelta($tot * 4.7 / 28.3, $n['T']['sol'], 0.011);
        $this->assertEqualsWithDelta($tot * 23.6 / 28.3, $n['E']['sol'], 0.011);
        $this->assertStringContainsString('Cotización adicional de solidaridad sobre 3.065,46 €', implode(' ', $n['baseExpl']));
        // Por debajo de la base máxima no hay solidaridad
        $this->assertArrayNotHasKey('sol', $this->calc($this->w(), 2026, 11)['T']);
    }

    public function test_cada_periodo_con_sus_parametros_y_aviso_si_faltan(): void
    {
        // 2027 sin cargar: usa los de 2026 y lo dice
        $n = $this->calc($this->w(['salBase' => 7000]), 2027, 3);
        $this->assertSame(5101.2, (float) $n['base']);
        $this->assertStringContainsString('no hay parámetros cargados para esta fecha', implode(' ', $n['baseExpl']));
        // La administración crea 2027 (copia) y cambia la base máxima; las asesoras no pueden
        $as = $this->usuario(User::ASESOR);
        $admin = $this->usuario(User::ADMIN);
        $this->actingAs($as)->withSession(['cliente_activo' => $this->a->id])->get(route('laboral.normativa'))->assertOk()->assertSee('Orden PJC/297/2026')->assertDontSee('Nuevo periodo desde');
        $this->actingAs($as)->post(route('laboral.normativa.nuevo'), ['clave' => 'cotizacion', 'desde' => '2027-01-01'])->assertForbidden();
        $this->actingAs($admin)->withSession(['cliente_activo' => $this->a->id])->post(route('laboral.normativa.nuevo'), ['clave' => 'cotizacion', 'desde' => '2027-01-01'])->assertSessionHasNoErrors();
        $p = ParametroNormativo::where('clave', 'cotizacion')->whereDate('desde', '2027-01-01')->firstOrFail();
        $this->assertSame('pendiente', $p->estado);
        $this->assertSame('2026-12-31', ParametroNormativo::where('clave', 'cotizacion')->whereDate('desde', '2026-01-01')->first()->hasta->toDateString());
        $v = $p->valor;
        $form = ['fuente' => 'Orden de cotización 2027 (BOE de prueba)', 'v' => [
            'base_max' => '5.300,00', 'base_min' => array_map(fn ($x) => (string) $x, $v['base_min']), 'base_min_dia' => array_map(fn ($x) => (string) $x, $v['base_min_dia']),
            'tipos' => collect($v['tipos'])->map(fn ($x) => [(string) ($x[0] * 100), (string) ($x[1] * 100)])->all(), 'sol' => ['1,20', '1,30', '1,50'], 'temporal_corto' => '34,50']];
        // Sin dato: no se guarda (no se pone a cero)
        $mal = $form;
        $mal['v']['base_max'] = '';
        $this->actingAs($admin)->post(route('laboral.normativa.guardar', $p->id), $mal)->assertSessionHasErrors('v.base_max');
        $this->actingAs($admin)->post(route('laboral.normativa.guardar', $p->id), $form + ['verificado' => '1'])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertSame(['verificado', 5300.0], [$p->estado, (float) $p->valor['base_max']]);
        Normativa::olvidar();
        $this->assertSame(5300.0, (float) $this->calc($this->w(['salBase' => 7000]), 2027, 3)['base']);
        $this->assertSame(5101.2, (float) $this->calc($this->w(['salBase' => 7000]), 2026, 11)['base']);
        $this->assertStringNotContainsString('no hay parámetros cargados', implode(' ', $this->calc($this->w(), 2027, 3)['baseExpl']));
    }

    public function test_contrato_temporal_de_menos_de_30_dias(): void
    {
        $w = $this->w(['contrato' => Nomina::contratoCompleto('402'), 'alta' => '2026-11-03', 'fin' => '2026-11-20', 'salBase' => 1500]);
        $n = $this->calc($w, 2026, 11);
        $this->assertSame(33.62, (float) $n['E']['temp30']);
        $this->assertStringContainsString('Contrato de duración determinada de 18 días', implode(' ', $n['baseExpl']));
        // Sustitución, 30 días o más, o que no termina este mes: no
        $this->assertArrayNotHasKey('temp30', $this->calc(array_merge($w, ['contrato' => Nomina::contratoCompleto('410')]), 2026, 11)['E']);
        $this->assertArrayNotHasKey('temp30', $this->calc(array_merge($w, ['alta' => '2026-10-15']), 2026, 11)['E']);
        $this->assertArrayNotHasKey('temp30', $this->calc(array_merge($w, ['fin' => '2026-12-10', 'alta' => '2026-11-20']), 2026, 11)['E']);
        $this->assertSame(33.62, (float) $this->calc(array_merge($w, ['fin' => '2026-12-10', 'alta' => '2026-11-20']), 2026, 12)['E']['temp30']);
    }

    public function test_tarifa_de_at_y_ep_por_cnae_u_ocupacion(): void
    {
        $admin = $this->usuario(User::ADMIN);
        $p = ParametroNormativo::where('clave', 'atep')->firstOrFail();
        // Sin tarifa: el tipo a mano de la empresa, avisado
        $w = $this->w();
        $this->assertStringContainsString('indicado a mano', implode(' ', $this->calc($w, 2026, 11)['baseExpl']));
        // Filas mal escritas o que no suman: no se guarda nada
        $this->actingAs($admin)->post(route('laboral.normativa.guardar', $p->id), ['fuente' => 'Tarifa de prueba del BOE (DA 61.ª LGSS)', 'tabla' => "4121 Construcción de edificios 3,35 3,35 7,00\nesto no es una fila"])
            ->assertSessionHasErrors('tabla');
        $this->assertSame([], (array) $p->fresh()->valor['cnae']);
        // Tabla de prueba (formato del BOE): actividad 41 y ocupación «a»
        $this->actingAs($admin)->post(route('laboral.normativa.guardar', $p->id), ['fuente' => 'Tarifa de prueba del BOE (DA 61.ª LGSS)', 'modo' => 'sustituir',
            'tabla' => "41 Construcción de edificios 3,35 3,35 6,70\na Personal en trabajos exclusivos de oficina 0,65 0,85 1,50\n01.11 Cultivo de prueba 1,00 1,00 2,00"])->assertSessionHasNoErrors();
        $this->assertEquals(['41' => ['Construcción de edificios', 3.35, 3.35], '0111' => ['Cultivo de prueba', 1, 1]], $p->fresh()->valor['cnae']);
        // La empresa tiene CNAE 4121 en su CCC: se aplica la división 41
        LaboralConfig::updateOrCreate(['cliente_id' => $this->a->id], ['datos' => ['cccsDet' => [['ccc' => '21123456789', 'denom' => 'Obra', 'regimen' => 'General', 'cnae' => '4121', 'at' => '']], 'at' => 1.5]]);
        Nomina::olvidarAT();
        Normativa::olvidar();
        $n = $this->calc($this->w(['ccc' => '21 123456789', 'salBase' => 2000, 'prorr' => false]), 2026, 11);
        $this->assertStringContainsString('6,70 % (IT 3,35 + IMS 3,35), cuadro I, CNAE 41', implode(' ', $n['baseExpl']));
        $this->assertEqualsWithDelta(round($n['baseAT'] * 0.067, 2), $n['E']['at'], 0.001);
        // El administrativo de la obra cotiza por la ocupación del cuadro II
        $o = $this->calc($this->w(['ccc' => '21 123456789', 'epig' => 'a · Personal en trabajos exclusivos de oficina']), 2026, 11);
        $this->assertStringContainsString('1,50 % (IT 0,65 + IMS 0,85), cuadro II', implode(' ', $o['baseExpl']));
    }

    public function test_irpf_minimos_desde_la_tabla_con_asistencia_por_discapacidad(): void
    {
        $base = $this->w(['salBase' => 2500, 'fnac' => '1980-01-01', 'm145' => ['sit' => '3', 'hijos' => [['anio' => '2015', 'entero' => true, 'disc' => 'No']], 'asc' => []]]);
        $sin = Nomina::irpfAnual($base, 35000, 2200, false, 2026);
        $this->assertSame(5550.0 + 2400.0, (float) $sin['minimo']);
        // Hijo con discapacidad del 65 %: + 9.000 y + 3.000 de asistencia (algoritmo AEAT 2026)
        $con = Nomina::irpfAnual(array_replace_recursive($base, ['m145' => ['hijos' => [['anio' => '2015', 'entero' => true, 'disc' => '65 % o más']]]]), 35000, 2200, false, 2026);
        $this->assertSame(5550.0 + 2400.0 + 9000.0 + 3000.0, (float) $con['minimo']);
        // Trabajador con discapacidad del 33 % y movilidad reducida: gastos de 7.750 y asistencia de 3.000
        $mov = Nomina::irpfAnual(array_replace_recursive($base, ['disc' => '33 % a 64 %', 'm145' => ['movRed' => true]]), 35000, 2200, false, 2026);
        $this->assertSame([2000.0 + 7750.0, 5550.0 + 2400.0 + 3000.0 + 3000.0], [(float) $mov['gastos'], (float) $mov['minimo']]);
    }
}
