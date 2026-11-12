<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CuotaTarifaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_tarifa_con_lineas_y_factura_de_la_cuota_automatica(): void
    {
        $desp = Cliente::create(['tipo' => 'Sociedad', 'nombre' => 'GA AGOPYME SL', 'nif' => 'B21890124', 'estado' => 'Activo', 'provincia' => 'Huelva']);
        $desp->forceFill(['es_despacho' => true])->save();
        $c = $this->cliente('Bar El Puerto SL', 'B21345673', ['domicilio' => 'C/ Mayor 1', 'cp' => '21001', 'municipio' => 'Huelva']);
        $otra = $this->cliente('Otra SL', 'B21987656');
        $as = $this->usuario(User::ASESOR);
        // SEPA sin cuenta del cliente → error; con su cuenta, se guarda
        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Domiciliación SEPA',
            'cuota_lineas' => [['d' => 'Honorarios', 'q' => '1', 'p' => '100', 'iva' => '21']]])->assertSessionHasErrors('form');
        $c->setSeccion('bancos', [['iban' => 'ES9121000418450200051332', 'entidad' => 'CaixaBank', 'sepa' => 'MAND-1', 'principal' => true]]);
        $c->save();
        $desp->setSeccion('bancos', [['iban' => 'ES7921000813610123456789', 'entidad' => 'CaixaBank', 'principal' => true], ['iban' => 'ES6000491500051234567892', 'entidad' => 'Santander', 'principal' => false]]);
        $desp->save();

        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_tarifa' => 'Sociedad completa', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Domiciliación SEPA', 'cuota_cuenta' => '0',
            'cuota_incluye' => "Contabilidad\nModelos trimestrales", 'cuota_lineas' => [
                ['d' => 'Honorarios de asesoría', 'q' => '1', 'p' => '120,00', 'iva' => '21'],
                ['d' => 'Nóminas', 'q' => '2', 'p' => '15', 'iva' => '21'],
                ['d' => '', 'q' => '1', 'p' => '', 'iva' => '21'],
            ]])->assertSessionHas('ok');
        $c->refresh();
        $this->assertSame('Sociedad completa', $c->cuota_tarifa);
        $this->assertCount(2, $c->cuota_lineas);
        $this->assertEqualsWithDelta(150, (float) $c->cuota_importe, 0.001);
        $this->assertEqualsWithDelta(181.5, $c->cuotaConIva(), 0.001);

        // Línea sin concepto con precio → error
        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Transferencia', 'cuota_lineas' => [['d' => '', 'q' => '1', 'p' => '10', 'iva' => '21']]])->assertSessionHasErrors('form');

        // Trabajando con otra empresa, «Facturar la cuota» cambia al despacho y rellena la factura
        $url = route('facturacion.create', ['cuota' => $c->id]);
        $this->actingAs($as)->withSession(['cliente_activo' => $otra->id])->get($url)->assertRedirect($url)->assertSessionHas('cliente_activo', $desp->id);
        $this->actingAs($as)->withSession(['cliente_activo' => $desp->id])->get($url)->assertOk()
            ->assertSee('value="Bar El Puerto SL"', false)->assertSee('value="B21345673"', false)
            ->assertSee('value="Nóminas"', false)->assertSee('value="120,00"', false)
            ->assertSee('Periodo facturado: noviembre 2026. Incluye: Contabilidad, Modelos trimestrales', false)
            ->assertSee('value="ES9121000418450200051332"', false);

        // Transferencia: sin elegir cuenta del despacho → error; con la segunda cuenta, la factura sale con ella y sin la del cliente
        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Transferencia',
            'cuota_lineas' => [['d' => 'Honorarios', 'q' => '1', 'p' => '100', 'iva' => '21']]])->assertSessionHasErrors('form');
        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Transferencia', 'cuota_cuenta' => '0', 'cuota_cuenta_despacho' => '1',
            'cuota_lineas' => [['d' => 'Honorarios', 'q' => '1', 'p' => '100', 'iva' => '21']]])->assertSessionHas('ok');
        $c->refresh();
        $this->assertNull($c->cuota_cuenta);
        $this->assertSame('1', (string) $c->cuota_cuenta_despacho);
        $html = $this->actingAs($as)->withSession(['cliente_activo' => $desp->id])->get($url)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="ES6000491500051234567892"\s+selected/', $html);
        $this->assertStringNotContainsString('name="iban" value="ES9121000418450200051332"', $html);
        // Ficha del despacho: sin sección de cuota
        $this->actingAs($as)->withSession(['cliente_activo' => $desp->id])->get(route('clientes.edit', [$desp, 'sec' => 'general']))->assertOk()->assertDontSee('<span>Cuota</span>', false)->assertSee('Datos bancarios');
    }
}
