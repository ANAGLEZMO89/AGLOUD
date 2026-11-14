<?php

namespace Tests\Feature;

use App\Models\Cliente;
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
        $this->assertSame(['resumen', 'resumen', 'resumen', 'pendiente'], ParametroNormativo::orderByRaw("case clave when 'cotizacion' then 1 when 'smi' then 2 when 'irpf' then 3 else 4 end")->pluck('estado')->all());
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
            'tipos' => collect($v['tipos'])->map(fn ($x) => [(string) ($x[0] * 100), (string) ($x[1] * 100)])->all(), 'sol' => ['1,20', '1,30', '1,50']]];
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
}
