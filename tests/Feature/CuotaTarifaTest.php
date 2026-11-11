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

        $this->actingAs($as)->put(route('clientes.update', $c), ['sec' => 'cuota', 'cuota_tarifa' => 'Sociedad completa', 'cuota_periodicidad' => 'Mensual', 'cuota_forma_cobro' => 'Domiciliación SEPA',
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
            ->assertSee('Periodo facturado: noviembre 2026. Incluye: Contabilidad, Modelos trimestrales', false);
    }
}
