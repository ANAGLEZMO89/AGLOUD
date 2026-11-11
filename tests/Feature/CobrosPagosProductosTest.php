<?php

namespace Tests\Feature;

use App\Models\Apunte;
use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Factura;
use App\Models\FacturacionConfig;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Facturacion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CobrosPagosProductosTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-12 10:00:00');
        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en()
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->a->id]);
    }

    private function apuntes(int $asientoId): array
    {
        return Apunte::where('asiento_id', $asientoId)->orderBy('orden')->get()->map(fn ($p) => [$p->cuenta, (float) $p->debe, (float) $p->haber])->all();
    }

    public function test_cobro_con_un_clic_y_asiento_por_banco(): void
    {
        $f = app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-10-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 1000, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $this->en()->get(route('clientes.cobros'))->assertOk()->assertSee('Hotel Costa SL')->assertSee('Marcar cobrado');

        // Enviar a contabilidad sin marcar → aviso
        $this->en()->post(route('cobros.contabilizar', $f), ['medio' => 'banco'])->assertSessionHas('aviso');
        $this->en()->post(route('cobros.marcar', $f), ['fecha' => '2026-11-05'])->assertSessionHas('ok');
        $f->refresh();
        $this->assertTrue($f->cobrada);
        $this->assertSame('2026-11-05', $f->cobrada_at->toDateString());

        // La factura no estaba contabilizada: se contabiliza y después el cobro
        $this->en()->post(route('cobros.contabilizar', $f), ['medio' => 'banco'])->assertSessionHas('ok');
        $f->refresh();
        $this->assertNotNull($f->asiento_id);
        $this->assertSame('banco', $f->cobro_medio);
        $this->assertSame([['572000000', 1210.0, 0.0], ['430000001', 0.0, 1210.0]], $this->apuntes($f->cobro_asiento_id));
        $this->assertSame('2026-11-05', Asiento::find($f->cobro_asiento_id)->fecha->toDateString());

        // Con el cobro contabilizado no se puede desmarcar
        $this->en()->post(route('cobros.marcar', $f))->assertSessionHas('aviso');
        $this->assertTrue($f->fresh()->cobrada);
        $this->en()->get(route('clientes.cobros', ['chip' => 2]))->assertOk()->assertSee('Contabilizado · Banco');
    }

    public function test_pagos_por_caja_y_en_lote_y_gastos_no_deducibles(): void
    {
        $ter = Tercero::create(['cliente_id' => $this->a->id, 'clase' => 'acreedor', 'cuenta' => Tercero::siguienteCuenta($this->a->id, 'acreedor'), 'nombre' => 'Restaurante El Faro', 'tipo' => 'Servicios', 'retencion' => 0, 'forma_pago' => 'Efectivo', 'pago_dias' => 0]);
        // Gasto con IVA no deducible desde su pestaña: la casilla viene desmarcada
        $this->en()->get(route('acreedores.nodeducibles', ['nuevo' => 1]))->assertOk()->assertSee('Nuevo gasto con IVA no deducible')->assertSee('Gastos no deducibles IVA');
        $this->en()->post(route('acreedores.facturas.store'), ['tercero_id' => $ter->id, 'num' => 'T-77', 'fecha' => '2026-11-02', 'concepto' => 'Comida con cliente', 'base' => '100',
            'iva_pct' => '10', 'ret_pct' => '0', 'forma_pago' => 'Efectivo', 'volver' => 'nd'])->assertRedirect(route('acreedores.nodeducibles'));
        $g = FacturaRecibida::firstWhere('num', 'T-77');
        $this->assertFalse($g->deducible);
        $this->en()->get(route('acreedores.nodeducibles'))->assertSee('T-77');
        $this->en()->get(route('acreedores.facturas', ['chip' => 1]))->assertDontSee('T-77');

        $this->en()->get(route('acreedores.pagos'))->assertOk()->assertSee('Restaurante El Faro');
        $this->en()->post(route('acreedores.pagos.marcar', $g))->assertSessionHas('ok');
        // En lote: el medio por defecto sale de la forma de pago (efectivo → caja)
        $this->en()->post(route('acreedores.pagos.contabilizarTodos'))->assertSessionHas('ok', '1 pagos contabilizados.');
        $g->refresh();
        $this->assertSame('caja', $g->pago_medio);
        $this->assertSame([[$ter->cuenta, 110.0, 0.0], ['570000000', 0.0, 110.0]], $this->apuntes($g->pago_asiento_id));
        // El gasto lleva el IVA no deducible dentro
        $this->assertSame(110.0, (float) Apunte::where('asiento_id', $g->asiento_id)->where('cuenta', 'like', '6%')->value('debe'));
    }

    public function test_productos_de_la_empresa(): void
    {
        $this->en()->get(route('productos.index'))->assertOk()->assertSee('Aún no hay productos');
        $this->en()->post(route('productos.guardar'), ['tipo' => 'Servicio', 'nombre' => 'Hora de consultoría', 'precio' => '45,50', 'iva' => '21', 'ref' => 'S-01'])->assertSessionHas('ok');
        $this->en()->post(route('productos.guardar'), ['tipo' => 'Producto', 'nombre' => 'hora de consultoría', 'precio' => '1', 'iva' => '21'])->assertSessionHasErrors('form');
        $cat = FacturacionConfig::de($this->a)->catalogo;
        $this->assertEquals(45.5, $cat[0]['precio']);
        $this->assertSame('S-01', $cat[0]['ref']);
        // Aparece al facturar
        $this->en()->get(route('facturacion.create'))->assertOk()->assertSee('Hora de consultoría');
        $this->en()->post(route('productos.guardar'), ['i' => 0, 'tipo' => 'Servicio', 'nombre' => 'Hora de consultoría', 'precio' => '50', 'iva' => '21'])->assertSessionHas('ok');
        $this->assertEquals(50.0, FacturacionConfig::de($this->a)->catalogo[0]['precio']);
        $this->en()->delete(route('productos.borrar', 0))->assertSessionHas('ok');
        $this->assertSame([], FacturacionConfig::de($this->a)->catalogo);
    }

    public function test_menu_ventas_compras_gastos(): void
    {
        $r = $this->en()->get(route('proveedores.index'))->assertOk();
        foreach (['Ventas', 'Compras', 'Gastos', 'Productos'] as $l) {
            $r->assertSee('<span>'.$l.'</span>', false);
        }
        $r->assertDontSee('<span>Acreedores</span>', false);
        $this->en()->get(route('acreedores.index'))->assertSee('Gastos no deducibles IVA')->assertSee('Pagos');
    }
}
