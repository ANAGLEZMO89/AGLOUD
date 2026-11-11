<?php

namespace Tests\Feature;

use App\Models\Asiento;
use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Ejercicio;
use App\Models\Factura;
use App\Models\FacturaRecibida;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Contabilidad;
use App\Services\Facturacion;
use App\Support\Parametros;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ContabilidadTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private Cliente $b;

    private User $as;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->b = $this->cliente('Empresa B SL', 'B21112230');
        $this->as = $this->usuario(User::ASESOR);
        $this->admin = $this->usuario(User::ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function en(?User $u = null, ?Cliente $c = null)
    {
        return $this->actingAs($u ?? $this->as)->withSession(['cliente_activo' => ($c ?? $this->a)->id]);
    }

    private function emitida(array $extra = []): Factura
    {
        return app(Facturacion::class)->emitir($this->a, $extra + ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-10-01', 'receptor_nombre' => 'Hotel Costa SL', 'receptor_nif' => 'B21345673',
            'lineas' => [['d' => 'Servicio', 'q' => 1, 'p' => 1000, 'iva' => 21, 'sup' => false], ['d' => 'Suplido', 'q' => 1, 'p' => 50, 'iva' => 0, 'sup' => true]], 'ret_pct' => 15, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->admin->id);
    }

    private function recibida(string $clase = 'acreedor', array $t = [], array $f = []): FacturaRecibida
    {
        $ter = Tercero::create($t + ['cliente_id' => $this->a->id, 'clase' => $clase, 'cuenta' => Tercero::siguienteCuenta($this->a->id, $clase), 'nombre' => 'Asesoría Jurídica', 'tipo' => 'Profesional', 'retencion' => 15, 'forma_pago' => 'Transferencia', 'pago_dias' => 0]);

        return FacturaRecibida::create($f + ['cliente_id' => $this->a->id, 'tercero_id' => $ter->id, 'clase' => $clase === 'acreedor' ? 'gasto' : 'compra', 'num' => 'F-1', 'fecha' => '2026-10-05', 'concepto' => 'Honorarios',
            'base' => 500, 'iva_pct' => 21, 'cuota' => 105, 'ret_pct' => 15, 'retencion' => 75, 'total' => 530, 'deducible' => true, 'forma_pago' => 'Transferencia', 'vencimiento' => '2026-10-05']);
    }

    private function apuntes(Asiento $a): array
    {
        return $a->apuntes()->get()->map(fn ($p) => [$p->cuenta, (float) $p->debe, (float) $p->haber])->all();
    }

    public function test_bandeja_y_contabilizacion_de_facturas(): void
    {
        $f = $this->emitida();
        $r = $this->recibida();
        $this->en()->get(route('contabilidad.bandeja'))->assertOk()->assertSee($f->num)->assertSee('F-1')->assertSee('623000000');
        $this->en()->post(route('contabilidad.contabilizar'), ['k' => ['e'.$f->id, 'r'.$r->id]])->assertSessionHas('ok');
        $ae = Asiento::where('origen', 'factura')->first();
        $this->assertSame([['430000001', 1110.0, 0.0], ['473000000', 150.0, 0.0], ['705000000', 0.0, 1000.0], ['477000000', 0.0, 210.0], ['555000000', 0.0, 50.0]], $this->apuntes($ae));
        $this->assertSame('Hotel Costa SL', Cuenta::where('codigo', '430000001')->value('nombre'));
        $ar = Asiento::where('origen', 'recibida')->first();
        $this->assertSame([['623000000', 500.0, 0.0], ['472000000', 105.0, 0.0], ['475100000', 0.0, 75.0], ['410000001', 0.0, 530.0]], $this->apuntes($ar));
        $this->assertSame($ae->id, $f->fresh()->asiento_id);
        $this->en()->get(route('contabilidad.bandeja'))->assertSee('Bandeja vacía');
        // Una segunda factura al mismo cliente reutiliza su subcuenta 430
        $f2 = $this->emitida(['fecha' => '2026-10-02']);
        app(Contabilidad::class)->contabilizarEmitida($f2);
        $this->assertSame('430000001', Asiento::where('origen_id', $f2->id)->first()->apuntes()->first()->cuenta);
        // La factura recibida contabilizada no se puede editar ni borrar
        $this->en()->delete(route('acreedores.facturas.destroy', $r))->assertSessionHas('aviso');
        // Borrar el asiento devuelve la factura a la bandeja
        $this->en()->delete(route('contabilidad.borrar', $ar))->assertSessionHas('ok');
        $this->assertNull($r->fresh()->asiento_id);
    }

    public function test_iva_no_deducible_compra_y_cuenta_elegida(): void
    {
        $r = $this->recibida('proveedor', ['nombre' => 'Mayorista', 'tipo' => null, 'retencion' => 0], ['base' => 200, 'cuota' => 42, 'retencion' => 0, 'ret_pct' => 0, 'total' => 242, 'deducible' => false]);
        $this->en()->post(route('contabilidad.contabilizar'), ['k' => ['r'.$r->id], 'cta' => ['r'.$r->id => '602']]);
        $a = Asiento::first();
        $this->assertSame([['602000000', 242.0, 0.0], ['400000001', 0.0, 242.0]], $this->apuntes($a));
        $r2 = $this->recibida('proveedor', ['nombre' => 'Otro', 'cuenta' => '400000009'], ['num' => 'X']);
        $this->en()->post(route('contabilidad.contabilizar'), ['k' => ['r'.$r2->id], 'cta' => ['r'.$r2->id => '705']])->assertSessionHas('aviso');
        $this->assertNull($r2->fresh()->asiento_id);
    }

    public function test_asiento_manual_debe_cuadrar_y_cuentas_validas(): void
    {
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-11-01', 'concepto' => 'Aportación de capital', 'l' => [['c' => '572', 'd' => '3.000,00'], ['c' => '100', 'h' => '3.000,00']]])->assertRedirect();
        $a = Asiento::first();
        $this->assertSame(1, $a->numero);
        $this->assertSame([['572000000', 3000.0, 0.0], ['100000000', 0.0, 3000.0]], $this->apuntes($a));
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-11-01', 'concepto' => 'Mal', 'l' => [['c' => '572', 'd' => '10'], ['c' => '100', 'h' => '9']]])->assertSessionHasErrors('form');
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-11-01', 'concepto' => 'Mal', 'l' => [['c' => '999', 'd' => '10'], ['c' => '100', 'h' => '10']]])->assertSessionHasErrors('form');
        // Editar
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['editar' => $a->id, 'fecha' => '2026-11-02', 'concepto' => 'Aportación', 'l' => [['c' => '572', 'd' => '3500'], ['c' => '100', 'h' => '3500']]])->assertRedirect();
        $this->assertSame(1, Asiento::count());
        $this->assertSame(3500.0, (float) $a->fresh()->apuntes()->first()->debe);
        // Fecha de bloqueo
        $ej = Ejercicio::first();
        $this->en()->post(route('contabilidad.ejercicios.bloqueo', $ej), ['bloqueo' => '2026-09-30'])->assertSessionHas('ok');
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-09-15', 'concepto' => 'Tarde', 'l' => [['c' => '572', 'd' => '1'], ['c' => '100', 'h' => '1']]])->assertSessionHasErrors('form');
        // Aislamiento: otra empresa no lo ve ni lo borra
        $this->en(null, $this->b)->get(route('contabilidad.diario'))->assertOk()->assertDontSee('Aportación');
        $this->en(null, $this->b)->delete(route('contabilidad.borrar', $a))->assertNotFound();
    }

    public function test_informes_y_cierre_con_apertura(): void
    {
        $this->en()->post(route('contabilidad.apuntes.guardar'), ['fecha' => '2026-01-02', 'concepto' => 'Capital', 'l' => [['c' => '572', 'd' => '3000'], ['c' => '100', 'h' => '3000']]]);
        $f = $this->emitida();
        $r = $this->recibida();
        $this->en()->post(route('contabilidad.contabilizar'), ['k' => ['e'.$f->id, 'r'.$r->id]]);
        $ej = Ejercicio::first();
        $res = app(Contabilidad::class)->resultado($ej);
        $this->assertSame(['ingresos' => 1000.0, 'gastos' => 500.0, 'resultado' => 500.0], $res);
        $this->en()->get(route('contabilidad.index'))->assertOk()->assertSee('500 €');
        $this->en()->get(route('contabilidad.sumas', ['nivel' => 3]))->assertOk()->assertSee('Prestaciones de servicios')->assertSee('1.000,00');
        $this->en()->get(route('contabilidad.mayor', ['c' => '430']))->assertOk()->assertSee('1.110,00');
        $this->en()->get(route('contabilidad.anuales'))->assertOk()->assertSee('A.4) RESULTADO DEL EJERCICIO')->assertSee('cuadran');
        $this->en()->get(route('contabilidad.diario', ['exportar' => 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        // Solo la administración cierra
        $this->en()->post(route('contabilidad.ejercicios.cerrar', $ej))->assertForbidden();
        $this->en($this->admin)->post(route('contabilidad.ejercicios.cerrar', $ej))->assertSessionHas('ok');
        $ej->refresh();
        $this->assertTrue($ej->cerrado());
        $reg = Asiento::where('tipo', 'Regularización')->first();
        $this->assertSame(500.0, (float) $reg->apuntes()->where('cuenta', '129000000')->value('haber'));
        $sig = Ejercicio::where('anio', 2027)->first();
        $ap = Asiento::where('ejercicio_id', $sig->id)->where('tipo', 'Apertura')->first();
        $this->assertNotNull($ap);
        $this->assertSame(500.0, (float) $ap->apuntes()->where('cuenta', '129000000')->value('haber'));
        $this->assertSame(3000.0 + 530.0 - 530.0 + 0, (float) $ap->apuntes()->where('cuenta', '572000000')->value('debe') + 0 * 1); // 572: 3.000
        // Todas las cuentas a cero tras el cierre
        $s = app(Contabilidad::class)->sumas($ej);
        $this->assertTrue($s->every(fn ($v) => abs($v['d'] - $v['h']) < 0.01));
        // Cerrado: no admite asientos
        $this->en()->post(route('contabilidad.apuntes.guardar', ['ej' => 2026]), ['fecha' => '2026-12-30', 'concepto' => 'Tarde', 'l' => [['c' => '572', 'd' => '1'], ['c' => '100', 'h' => '1']]])->assertSessionHasErrors('form');
        // Reabrir
        $this->en($this->admin)->post(route('contabilidad.ejercicios.reabrir', $ej))->assertSessionHas('ok');
        $this->assertFalse($ej->fresh()->cerrado());
        $this->assertSame(0, Asiento::whereIn('tipo', ['Regularización', 'Cierre', 'Apertura'])->count());
    }

    public function test_anular_factura_contabilizada_genera_contraasiento(): void
    {
        $f = $this->emitida(['fecha' => '2026-11-11']);
        app(Contabilidad::class)->contabilizarEmitida($f);
        $this->en()->withSession(['cliente_activo' => $this->a->id])->post(route('facturacion.anular', $f))->assertSessionHas('ok');
        $c = Asiento::where('tipo', 'Anulación')->first();
        $this->assertNotNull($c);
        $this->assertSame(1110.0, (float) $c->apuntes()->where('cuenta', '430000001')->value('haber'));
        $s = app(Contabilidad::class)->sumas(Ejercicio::first());
        $this->assertSame(0.0, round($s['430000001']['d'] - $s['430000001']['h'], 2));
    }

    public function test_portal_no_entra_y_pantallas_cargan(): void
    {
        $u = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($u)->get(route('contabilidad.index'))->assertForbidden();
        foreach (['contabilidad.index', 'contabilidad.bandeja', 'contabilidad.diario', 'contabilidad.apuntes', 'contabilidad.mayor', 'contabilidad.sumas', 'contabilidad.anuales', 'contabilidad.ejercicios'] as $r) {
            $this->en()->get(route($r))->assertOk()->assertSee('Parámetros');
        }
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'contabilidad'), ['ventas' => '700', 'compras' => '600', 'gasto_servicios' => '629', 'gasto_profesional' => '623', 'gasto_arrendamiento' => '621',
            'iva_repercutido' => '477', 'iva_soportado' => '472', 'ret_soportada' => '473', 'ret_practicada' => '4751', 'suplidos' => '555', 'banco' => '572'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'contabilidad'), ['ventas' => '999', 'compras' => '600', 'gasto_servicios' => '629', 'gasto_profesional' => '623', 'gasto_arrendamiento' => '621',
            'iva_repercutido' => '477', 'iva_soportado' => '472', 'ret_soportada' => '473', 'ret_practicada' => '4751', 'suplidos' => '555', 'banco' => '572'])->assertSessionHasErrors('ventas');
        Parametros::limpiar();
        $f = $this->emitida();
        app(Contabilidad::class)->contabilizarEmitida($f);
        $this->assertSame(1000.0, (float) Asiento::first()->apuntes()->where('cuenta', '700000000')->value('haber'));
    }
}
