<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cuenta;
use App\Models\Evento;
use App\Models\Expediente;
use App\Models\Tarea;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Contabilidad;
use App\Services\Facturacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** La plataforma trabaja sobre la empresa elegida en el Escritorio: el despacho (con todos sus clientes) o un cliente (solo lo suyo). */
class EmpresaElegidaTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $desp;

    private Cliente $a;

    private Cliente $b;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        $this->desp = $this->cliente('GA AGOPYME SL', 'B21000001');
        $this->desp->forceFill(['es_despacho' => true])->save();
        $this->a = $this->cliente('Café Las Colonias SL', 'B21890124');
        $this->b = $this->cliente('Otra SL', 'B21345673');
        $this->as = $this->usuario(User::ASESOR);
    }

    private function con(Cliente $c)
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $c->id]);
    }

    public function test_sin_empresa_elegida_los_modulos_mandan_al_escritorio(): void
    {
        foreach (['/clientes', '/proveedores', '/calendario', '/tareas', '/notificaciones', '/expedientes', '/laboral', '/contabilidad'] as $u) {
            $this->actingAs($this->as)->withSession(['cliente_activo' => 0])->get($u)->assertRedirect(route('escritorio', ['cambiar' => 1, 'volver' => $u]));
        }
        $this->actingAs($this->as)->withSession(['cliente_activo' => 0])->get('/escritorio')->assertOk();
    }

    public function test_ventas_del_despacho_son_sus_clientes_y_las_de_un_cliente_sus_propios_clientes(): void
    {
        // Despacho: los clientes de la gestoría
        $this->con($this->desp)->get('/clientes')->assertOk()->assertSee('Café Las Colonias SL')->assertSee('Otra SL')->assertSee('+ Nuevo cliente');
        // Cliente: sus propios clientes (subcuentas 430)
        $this->con($this->a)->get('/clientes')->assertOk()->assertDontSee('Otra SL')->assertSee('Subcuentas 430')->assertSee('+ Nuevo cliente');
        $this->con($this->a)->post('/ventas/clientes', ['nombre' => 'Hotel Costa Luz SL', 'nif' => 'B21345673', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'cuenta_modo' => 'auto'])
            ->assertRedirect(route('clientes.index'));
        $t = Tercero::where('clase', 'cliente')->firstOrFail();
        $this->assertSame($this->a->id, $t->cliente_id);
        $this->assertSame('430000001', $t->cuenta);
        $this->con($this->a)->get('/clientes')->assertSee('Hotel Costa Luz SL');
        // No se ve desde otra empresa
        $this->con($this->b)->get('/clientes')->assertDontSee('Hotel Costa Luz SL');
        // Sale en Facturación y la contabilidad usa su subcuenta
        $this->con($this->a)->get('/facturacion/nueva')->assertSee('Hotel Costa Luz SL');
        $this->assertSame('430000001', app(Contabilidad::class)->cuentaCliente($this->a, 'B21345673', 'Hotel Costa Luz SL'));
        $this->assertSame('430000002', Tercero::siguienteCuenta($this->a->id, 'cliente'));
        // Clientes que solo constan en sus facturas: se dan de alta con un clic, reutilizando la subcuenta 430 de la contabilidad
        Cuenta::create(['cliente_id' => $this->a->id, 'codigo' => '430000007', 'nombre' => 'Academia Columbus SL', 'nif' => 'B21987656']);
        app(Facturacion::class)->emitir($this->a, ['serie' => 'A', 'tf' => 'F1', 'fecha' => now()->toDateString(), 'receptor_nombre' => 'Academia Columbus SL', 'receptor_nif' => 'B21987656',
            'lineas' => [['d' => 'Desayunos', 'q' => 1, 'p' => 100, 'iva' => 10, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->as->id);
        $this->con($this->a)->get('/clientes')->assertSee('Añadir 1 cliente de sus facturas');
        $this->con($this->a)->post('/ventas/clientes/importar')->assertRedirect(route('clientes.index'));
        $this->assertSame('430000007', Tercero::where('nif', 'B21987656')->value('cuenta'));
        $this->con($this->a)->get('/clientes')->assertDontSee('de sus facturas')->assertSee('Academia Columbus SL');
    }

    public function test_calendario_y_tareas_segun_la_empresa_elegida(): void
    {
        Tarea::create(['titulo' => 'Tarea del café', 'cliente_id' => $this->a->id, 'responsable_id' => $this->as->id, 'fecha' => now()->toDateString(), 'hecha' => false, 'created_by' => $this->as->id]);
        Tarea::create(['titulo' => 'Tarea de otra', 'cliente_id' => $this->b->id, 'responsable_id' => $this->as->id, 'fecha' => now()->toDateString(), 'hecha' => false, 'created_by' => $this->as->id]);
        Evento::create(['fecha' => now()->toDateString(), 'tipo' => 'cita', 'titulo' => 'Cita del café', 'cliente_id' => $this->a->id, 'created_by' => $this->as->id]);
        Evento::create(['fecha' => now()->toDateString(), 'tipo' => 'cita', 'titulo' => 'Cita de otra', 'cliente_id' => $this->b->id, 'created_by' => $this->as->id]);
        $this->con($this->desp)->get('/tareas')->assertSee('Tarea del café')->assertSee('Tarea de otra');
        $this->con($this->a)->get('/tareas')->assertSee('Tarea del café')->assertDontSee('Tarea de otra');
        $this->con($this->desp)->get('/calendario?vista=agenda')->assertSee('Cita del café')->assertSee('Cita de otra');
        $this->con($this->a)->get('/calendario?vista=agenda')->assertSee('Cita del café')->assertDontSee('Cita de otra');
    }

    public function test_con_un_cliente_elegido_lo_demas_queda_bloqueado(): void
    {
        $admin = $this->usuario(User::ADMIN);
        $ver = fn ($c) => $this->actingAs($admin)->withSession(['cliente_activo' => $c->id]);
        // Su propia ficha sí; la de otro cliente o la del despacho, no
        $ver($this->a)->get(route('clientes.show', $this->a))->assertOk();
        $ver($this->a)->get(route('clientes.show', $this->b))->assertNotFound();
        $ver($this->a)->get(route('clientes.edit', $this->desp))->assertNotFound();
        $exp = Expediente::create(['cliente_id' => $this->b->id, 'ref' => 'EXP-1', 'tramite' => 'Arraigo', 'categoria' => 'Extranjería', 'estado' => 'Abierto', 'apertura' => now()->toDateString()]);
        $ver($this->a)->get(route('expedientes.show', $exp))->assertNotFound();
        $ver($this->desp)->get(route('expedientes.show', $exp))->assertOk();
        // La administración del despacho solo con el despacho elegido
        $ver($this->a)->get(route('admin.equipo'))->assertRedirect();
        $ver($this->desp)->get(route('admin.equipo'))->assertOk();
        // El menú deja claro en qué área se está y no muestra la administración
        $ver($this->a)->get('/contabilidad')->assertSee('Área del cliente')->assertSee('Café Las Colonias SL')->assertDontSee('Equipo y accesos');
        $ver($this->desp)->get('/contabilidad')->assertSee('Área del despacho')->assertSee('Equipo y accesos');
        // Elegir otra empresa siempre se puede
        $ver($this->a)->post(route('escritorio.seleccionar', $this->b))->assertRedirect();
    }
}
