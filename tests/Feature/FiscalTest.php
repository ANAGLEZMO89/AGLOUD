<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\FacturaRecibida;
use App\Models\FiscalModelo;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Contabilidad;
use App\Services\Facturacion;
use App\Services\Fiscal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FiscalTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en(?Cliente $c = null)
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => ($c ?? $this->a)->id]);
    }

    /** Factura emitida (1.000 € al 21 % + 200 € al 10 %) y factura de un profesional (500 € + 21 % − 15 %), contabilizadas en el 4T */
    private function movimientos(): void
    {
        $conta = app(Contabilidad::class);
        $f = app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-10-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 1000, 'iva' => 21, 'sup' => false], ['d' => 'Comida', 'q' => 1, 'p' => 200, 'iva' => 10, 'sup' => false]],
            'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $conta->contabilizarEmitida($f, $this->as->id);
        $ter = Tercero::create(['cliente_id' => $this->a->id, 'clase' => 'acreedor', 'cuenta' => Tercero::siguienteCuenta($this->a->id, 'acreedor'), 'nombre' => 'Asesoría Jurídica', 'nif' => 'B21987656',
            'tipo' => 'Profesional', 'retencion' => 15, 'forma_pago' => 'Transferencia', 'pago_dias' => 0]);
        $r = FacturaRecibida::create(['cliente_id' => $this->a->id, 'tercero_id' => $ter->id, 'clase' => 'gasto', 'num' => 'F-1', 'fecha' => '2026-10-05', 'concepto' => 'Honorarios',
            'base' => 500, 'iva_pct' => 21, 'cuota' => 105, 'ret_pct' => 15, 'retencion' => 75, 'total' => 530, 'deducible' => true, 'forma_pago' => 'Transferencia', 'vencimiento' => '2026-10-05']);
        $conta->contabilizarRecibida($r, $this->as->id);
    }

    private function valor(array $calc, string $id): float
    {
        foreach ($calc['filas'] as $f) {
            if (($f['id'] ?? null) === $id) {
                return (float) $f['v'];
            }
        }
        $this->fail('Casilla '.$id.' no encontrada');
    }

    public function test_plazos_pasan_al_siguiente_dia_habil(): void
    {
        $this->assertSame('2026-04-20', Fiscal::plazo('303', 2026, '1T')->toDateString());
        $this->assertSame('2027-02-01', Fiscal::plazo('303', 2026, '4T')->toDateString());   // 30/01/2027 es sábado
        $this->assertSame('2027-01-20', Fiscal::plazo('111', 2026, '4T')->toDateString());
        $this->assertSame('2027-03-01', Fiscal::plazo('347', 2026, '0A')->toDateString());   // 28/02/2027 es domingo
        $this->assertSame('2026-07-27', Fiscal::plazo('200', 2025, '0A')->toDateString());   // 25/07/2026 es sábado
        $this->assertSame('2026-12-21', Fiscal::plazo('202', 2026, '3P')->toDateString());   // 20/12/2026 es domingo
        $this->assertNull(Fiscal::plazo('036', 2026, 'E'));
    }

    public function test_303_y_111_salen_del_libro_diario(): void
    {
        $this->movimientos();
        $fis = app(Fiscal::class);
        $c = $fis->calcular($this->a, '303', 2026, '4T');
        $this->assertEqualsWithDelta(1000, $this->valor($c, '07'), 0.001);
        $this->assertEqualsWithDelta(210, $this->valor($c, '09'), 0.001);
        $this->assertEqualsWithDelta(200, $this->valor($c, '04'), 0.001);
        $this->assertEqualsWithDelta(20, $this->valor($c, '06'), 0.001);
        $this->assertEqualsWithDelta(230, $this->valor($c, '27'), 0.001);
        $this->assertEqualsWithDelta(500, $this->valor($c, '28'), 0.001);
        $this->assertEqualsWithDelta(105, $this->valor($c, '29'), 0.001);
        $this->assertEqualsWithDelta(125, $c['resV'], 0.001);
        $this->assertSame('A ingresar', $c['resLbl']);
        // En el 3T no hay movimientos
        $this->assertEqualsWithDelta(0, app(Fiscal::class)->calcular($this->a, '303', 2026, '3T')['resV'], 0.001);

        $r = app(Fiscal::class)->calcular($this->a, '111', 2026, '4T');
        $this->assertEqualsWithDelta(1, $this->valor($r, '07'), 0.001);
        $this->assertEqualsWithDelta(500, $this->valor($r, '08'), 0.001);
        $this->assertEqualsWithDelta(75, $r['resV'], 0.001);
    }

    public function test_deteccion_de_modelos_segun_tipo_y_contabilidad(): void
    {
        $fis = app(Fiscal::class);
        $R = Fiscal::detectar($this->a, $fis->parametros($this->a));
        $this->assertArrayHasKey('303', $R);
        $this->assertArrayHasKey('200', $R);
        $this->assertArrayNotHasKey('130', $R);
        $this->assertArrayNotHasKey('111', $R);
        $this->movimientos();
        $this->assertArrayHasKey('111', Fiscal::detectar($this->a, app(Fiscal::class)->parametros($this->a)));

        $aut = $this->cliente('Lucía Pérez Gómez', '29400137Y', ['tipo' => 'Autónomo']);
        $Ra = Fiscal::detectar($aut, app(Fiscal::class)->parametros($aut));
        $this->assertArrayHasKey('130', $Ra);
        $this->assertArrayHasKey('100', $Ra);
        $this->assertArrayNotHasKey('200', $Ra);
        $part = $this->cliente('Juan Ruiz', '29400138F', ['tipo' => 'Particular']);
        $this->assertArrayNotHasKey('303', Fiscal::detectar($part, app(Fiscal::class)->parametros($part)));
    }

    public function test_pantallas_borrador_y_presentado(): void
    {
        $this->movimientos();
        $this->en()->get(route('fiscal.index'))->assertOk()->assertSee('modelos detectados para Empresa A SL')->assertSee('303')->assertSee('Pendiente 4T 2026');
        $this->en()->get(route('fiscal.index', ['ver' => 'todos', 'q' => '347']))->assertOk()->assertSee('Operaciones con terceras personas')->assertDontSee('IVA · autoliquidación');
        $this->en()->get(route('fiscal.index', ['parametros' => 1]))->assertOk()->assertSee('Parámetros fiscales')->assertSee('Modelos que tendrá que presentar');
        $this->en()->get(route('fiscal.modelo', ['codigo' => '303', 'anio' => 2026, 'per' => '4T']))->assertOk()->assertSee('125,00 €')->assertSee('Presentar en Hacienda');
        foreach (array_keys(Fiscal::MODELOS) as $code) {
            $this->en()->get(route('fiscal.modelo', ['codigo' => $code]))->assertOk();
        }

        // Cálculo al momento: corregir la casilla 29 (IVA deducible) a 100 → resultado 130
        $base = ['anio' => 2026, 'per' => '4T', 'cas' => ['29' => '100,00 €'], 'orig' => ['29' => '105,00 €']];
        $this->en()->postJson(route('fiscal.calcular', '303'), $base)->assertOk()->assertJsonPath('res', '130,00 €');

        $this->en()->post(route('fiscal.borrador', '303'), $base + ['tipo_decl' => 'I'])->assertSessionHas('ok');
        $m = FiscalModelo::where('modelo', '303')->first();
        $this->assertSame(['29' => '100,00 €'], $m->casillas);
        $this->assertEqualsWithDelta(130, app(Fiscal::class)->calcular($this->a, '303', 2026, '4T')['resV'], 0.001);

        $this->en()->post(route('fiscal.recalcular', '303'), ['anio' => 2026, 'per' => '4T'])->assertSessionHas('ok');
        $this->assertNull($m->fresh()->casillas);

        $this->en()->post(route('fiscal.presentado', '303'), ['anio' => 2026, 'per' => '4T', 'fecha' => '2026-12-01'])->assertSessionHasErrors('fecha');
        $this->en()->post(route('fiscal.presentado', '303'), ['anio' => 2026, 'per' => '4T', 'fecha' => '2026-11-10'])->assertSessionHas('ok');
        $this->assertSame('2026-11-10', $m->fresh()->presentado_at->toDateString());
        $this->en()->get(route('fiscal.index'))->assertSee('Pendiente 1T 2027', false);

        // Calendario: el 111 del 4T aparece en enero; el 303 del 4T ya presentado no
        $this->en()->get(route('calendario', ['m' => '2027-01']))->assertOk()->assertSee('Modelo 111 · 4T 2026')->assertDontSee('Modelo 303 · 4T 2026');

        $this->en()->get(route('fiscal.exportar', ['codigo' => '303', 'anio' => 2026, 'per' => '4T', 'formato' => 'csv']))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->en()->get(route('fiscal.exportar', ['codigo' => '303', 'anio' => 2026, 'per' => '4T', 'formato' => 'pdf']))->assertOk()->assertSee('Borrador de trabajo');
    }

    public function test_parametros_por_empresa_cambian_los_modelos(): void
    {
        $this->en()->post(route('fiscal.parametros'), ['ivaReg' => 'Exento', 'tipoIS' => '25', 'modal202' => 'Artículo 40.3', 'trab' => 2, 'formaPago' => 'Ingreso', 'iban' => ''])->assertSessionHas('ok');
        $R = Fiscal::detectar($this->a, app(Fiscal::class)->parametros($this->a));
        $this->assertArrayNotHasKey('303', $R);
        $this->assertArrayHasKey('202', $R);
        $this->assertArrayHasKey('111', $R);
        $this->en()->post(route('fiscal.parametros'), ['iban' => 'ES00 1234'])->assertSessionHasErrors('iban');
    }

    public function test_portal_y_sin_empresa(): void
    {
        $this->actingAs($this->as)->get(route('fiscal.index'))->assertRedirect();
        $portal = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($portal)->get(route('fiscal.index'))->assertForbidden();
    }
}
