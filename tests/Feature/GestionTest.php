<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Evento;
use App\Models\FacturaRecibida;
use App\Models\Fichaje;
use App\Models\Solicitud;
use App\Models\Tarea;
use App\Models\Tercero;
use App\Models\User;
use App\Services\Facturacion;
use App\Support\Parametros;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use LogicException;
use Tests\TestCase;

class GestionTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $desp;

    private Cliente $a;

    private Cliente $b;

    private User $as;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-11 10:00:00');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->desp = $this->cliente('GA AGOPYME SL', 'B21000005', ['domicilio' => 'C/ Uno', 'cp' => '21001', 'municipio' => 'Huelva']);
        $this->desp->es_despacho = true;
        $this->desp->save();
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

    private function en(Cliente $c, ?User $u = null)
    {
        return $this->actingAs($u ?? $this->as)->withSession(['cliente_activo' => $c->id]);
    }

    private function alta(string $mod, array $extra = [], ?Cliente $c = null)
    {
        return $this->en($c ?? $this->a)->post(route($mod.'.store'), $extra + [
            'nombre' => 'Suministros Odiel SL', 'nif' => 'B21345673', 'cp' => '21001', 'municipio' => 'Huelva', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'cuenta_modo' => 'auto',
        ]);
    }

    public function test_menu_gestion_sin_expedientes_y_con_el_resto(): void
    {
        $r = $this->en($this->a)->get(route('clientes.index'))->assertOk();
        foreach (['proveedores.index', 'acreedores.index', 'calendario', 'tareas.index'] as $ruta) {
            $r->assertSee(route($ruta), false);
        }
        $this->assertStringNotContainsString('<span>Expedientes</span>', $r->getContent());
        $this->en($this->a)->get(route('expedientes.index'))->assertOk(); // sigue funcionando
        $r->assertSee('Facturas de clientes');
    }

    public function test_proveedores_con_cuenta_automatica_y_aislados_por_empresa(): void
    {
        $this->alta('proveedores')->assertRedirect();
        $this->alta('proveedores', ['nombre' => 'Papelería Colón', 'nif' => '29331847Q'])->assertRedirect();
        $this->assertSame(['400000001', '400000002'], Tercero::orderBy('id')->pluck('cuenta')->all());
        $this->assertSame('Huelva', Tercero::first()->provincia);
        $this->alta('proveedores', ['nombre' => 'Otro', 'nif' => '', 'cuenta_modo' => 'manual', 'cuenta' => '400000002'])->assertSessionHasErrors('form');
        $this->alta('proveedores', ['nombre' => 'Mal', 'nif' => 'B21345678'])->assertSessionHasErrors('form');
        $this->alta('acreedores', ['nombre' => 'Asesoría Jurídica Pérez', 'tipo' => 'Profesional', 'retencion' => '15'])->assertRedirect();
        $this->assertSame('410000001', Tercero::where('clase', 'acreedor')->value('cuenta'));
        $this->en($this->a)->get(route('proveedores.index'))->assertOk()->assertSee('Suministros Odiel SL')->assertSee('400000001');
        $this->en($this->b)->get(route('proveedores.index'))->assertOk()->assertDontSee('Suministros Odiel SL');
        // Otra empresa no puede editar ni borrar un proveedor ajeno
        $t = Tercero::first();
        $this->en($this->b)->post(route('proveedores.update', $t), ['nombre' => 'X', 'forma_pago' => 'Transferencia', 'pago_dias' => '30', 'cuenta_modo' => 'auto'])->assertNotFound();
        $this->en($this->b)->delete(route('proveedores.destroy', $t))->assertNotFound();
    }

    public function test_facturas_de_proveedores_y_de_acreedores(): void
    {
        $this->alta('proveedores');
        $this->alta('acreedores', ['nombre' => 'Gestoría Laboral SL', 'nif' => 'B21987656', 'tipo' => 'Profesional', 'retencion' => '15', 'pago_dias' => '0']);
        $p = Tercero::where('clase', 'proveedor')->first();
        $ac = Tercero::where('clase', 'acreedor')->first();
        $pdf = UploadedFile::fake()->createWithContent('fra.pdf', '%PDF-1.4 prueba');
        $this->en($this->a)->post(route('proveedores.facturas.store'), ['tercero_id' => $p->id, 'num' => 'F-77', 'fecha' => '2026-10-15', 'concepto' => 'Café en grano', 'base' => '1.000,00', 'iva_pct' => '10', 'forma_pago' => 'Transferencia', 'archivo' => $pdf])->assertRedirect();
        $f = FacturaRecibida::first();
        $this->assertSame('100.00', $f->cuota);
        $this->assertSame('1100.00', $f->total);
        $this->assertSame('2026-11-14', $f->vencimiento->toDateString()); // 30 días
        $this->assertNotNull($f->documento_id);
        $this->assertSame('contable/recibidas', $f->documento->carpeta);
        // Duplicada
        $this->en($this->a)->post(route('proveedores.facturas.store'), ['tercero_id' => $p->id, 'num' => 'F-77', 'fecha' => '2026-10-15', 'concepto' => 'x', 'base' => '1', 'iva_pct' => '21', 'forma_pago' => 'Transferencia'])->assertSessionHasErrors('form');
        // Gasto con retención y sin IVA deducible
        $this->en($this->a)->post(route('acreedores.facturas.store'), ['tercero_id' => $ac->id, 'num' => '2026/12', 'fecha' => '2026-11-02', 'concepto' => 'Honorarios', 'base' => '500', 'iva_pct' => '21', 'ret_pct' => '15', 'forma_pago' => 'Transferencia'])->assertRedirect();
        $g = FacturaRecibida::where('clase', 'gasto')->first();
        $this->assertSame('75.00', $g->retencion);
        $this->assertSame('530.00', $g->total);
        $this->assertFalse($g->deducible);
        $this->en($this->a)->get(route('acreedores.facturas'))->assertOk()->assertSee('Retenciones practicadas')->assertSee('75,00');
        $this->en($this->a)->post(route('proveedores.facturas.pagar', $f))->assertRedirect();
        $this->assertNotNull($f->fresh()->pagada_at);
        $this->en($this->a)->get(route('proveedores.facturas', ['per' => '4T']))->assertOk()->assertSee('F-77')->assertSee('Pagada');
        $this->en($this->a)->get(route('proveedores.facturas', ['per' => '1T']))->assertOk()->assertDontSee('F-77');
        $this->en($this->b)->post(route('proveedores.facturas.pagar', $f))->assertNotFound();
        // Un proveedor con facturas no se borra
        $this->en($this->a)->delete(route('proveedores.destroy', $p))->assertSessionHas('aviso');
        $this->en($this->a)->get(route('proveedores.facturas', ['exportar' => 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_facturas_de_clientes_y_pestana_en_la_ficha(): void
    {
        app(Facturacion::class)->emitir($this->desp, ['serie' => 'A', 'tf' => 'F1', 'fecha' => '2026-09-01', 'receptor_nombre' => 'Empresa A SL', 'receptor_nif' => 'B21890124',
            'lineas' => [['d' => 'Cuota septiembre', 'q' => 1, 'p' => 100, 'iva' => 21, 'sup' => false]], 'ret_pct' => 0, 'forma_pago' => 'Transferencia', 'pago_dias' => 30], $this->admin->id);
        $this->en($this->desp)->get(route('clientes.facturas'))->assertOk()->assertSee('A-2026-0001')->assertSee('Vencida');
        $this->en($this->desp)->get(route('clientes.facturas', ['chip' => 3]))->assertOk()->assertDontSee('A-2026-0001');
        $this->en($this->a)->get(route('clientes.facturas'))->assertOk()->assertDontSee('A-2026-0001');
        $this->actingAs($this->as)->get(route('clientes.show', [$this->a, 'tab' => 'fact']))->assertOk()->assertSee('A-2026-0001')->assertSee('Cuota septiembre')->assertSee('121,00');
        $this->actingAs($this->as)->get(route('clientes.show', [$this->b, 'tab' => 'fact']))->assertOk()->assertDontSee('A-2026-0001');
    }

    public function test_calendario_reune_eventos_de_todas_las_fuentes(): void
    {
        Tarea::create(['titulo' => 'Revisar 303 de Empresa A', 'responsable_id' => $this->as->id, 'fecha' => '2026-11-20', 'prioridad' => 'Alta']);
        $this->actingAs($this->as)->post(route('calendario.store'), ['fecha' => '2026-11-13', 'hora' => '10:30', 'tipo' => 'cita', 'titulo' => 'Reunión con Empresa A', 'cliente_id' => $this->a->id])->assertRedirect();
        $this->actingAs($this->as)->get(route('calendario', ['m' => '2026-11']))->assertOk()->assertSee('Revisar 303 de Empresa A')->assertSee('Reunión con Empresa A')->assertSee('Festivo');
        $this->actingAs($this->as)->get(route('calendario', ['m' => '2026-11', 'vista' => 'agenda', 't' => ['cita']]))->assertOk()->assertSee('Reunión con Empresa A')->assertDontSee('Revisar 303 de Empresa A');
        $this->actingAs($this->as)->delete(route('calendario.destroy', Evento::first()))->assertRedirect();
        $this->assertSame(0, Evento::count());
    }

    public function test_tareas_recurrentes_y_registro_de_jornada(): void
    {
        $this->actingAs($this->as)->post(route('tareas.store'), ['titulo' => 'Presentar IVA trimestral', 'responsable_id' => $this->as->id, 'fecha' => '2026-11-20', 'prioridad' => 'Alta', 'repetir' => 'trimestral'])->assertRedirect();
        $t = Tarea::first();
        $this->actingAs($this->as)->post(route('tareas.hecha', $t))->assertRedirect();
        $this->assertTrue($t->fresh()->hecha);
        $this->assertSame('2027-02-20', Tarea::where('hecha', false)->first()->fecha->toDateString());
        $this->actingAs($this->as)->get(route('tareas.index'))->assertOk()->assertSee('Presentar IVA trimestral')->assertSee('Cada trimestre');

        // Fichaje: entrada 9:00, pausa 11:00-11:30, salida 15:00 → 5 h 30 min
        foreach ([['entrada', '09:00'], ['pausa', '11:00'], ['reanuda', '11:30'], ['salida', '15:00']] as [$tipo, $h]) {
            Carbon::setTestNow('2026-11-11 '.$h.':00');
            CarbonImmutable::setTestNow('2026-11-11 '.$h.':00');
            $this->actingAs($this->as)->post(route('tareas.fichar'), ['tipo' => $tipo])->assertSessionHas('ok');
        }
        $this->actingAs($this->as)->post(route('tareas.fichar'), ['tipo' => 'salida'])->assertSessionHas('aviso');
        $this->actingAs($this->as)->get(route('tareas.horario'))->assertOk()->assertSee('5 h 30 min')->assertSee('09:00')->assertSee('15:00');
        $this->expectException(LogicException::class);
        try {
            Fichaje::first()->update(['tipo' => 'salida']);
        } finally {
            $this->actingAs($this->as)->get(route('tareas.exportar', ['m' => '2026-11']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            // Solicitudes: la resuelve la administración
            $this->actingAs($this->as)->post(route('tareas.solicitar'), ['tipo' => 'Vacaciones', 'desde' => '2026-12-21', 'hasta' => '2026-12-24'])->assertRedirect();
            $s = Solicitud::first();
            $this->actingAs($this->as)->post(route('tareas.resolver', $s), ['estado' => 'Aprobada'])->assertForbidden();
            $this->actingAs($this->admin)->post(route('tareas.resolver', $s), ['estado' => 'Aprobada'])->assertRedirect();
            $this->assertSame('Aprobada', $s->fresh()->estado);
        }
    }

    public function test_el_portal_no_entra_en_gestion(): void
    {
        $u = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        foreach (['proveedores.index', 'acreedores.facturas', 'clientes.facturas', 'calendario', 'tareas.index', 'tareas.horario'] as $r) {
            $this->actingAs($u)->get(route($r))->assertForbidden();
        }
    }

    public function test_pantallas_y_parametros_de_gestion(): void
    {
        foreach (['proveedores.index', 'proveedores.facturas', 'acreedores.index', 'acreedores.facturas', 'clientes.facturas', 'calendario', 'tareas.index', 'tareas.horario'] as $r) {
            $this->en($this->a)->get(route($r))->assertOk()->assertSee('Parámetros');
        }
        $this->en($this->a)->get(route('proveedores.index', ['nuevo' => 1]))->assertOk()->assertSee('400000001');
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'tareas'), ['jornada_semanal' => '37,5', 'jornada_diaria' => '7.5', 'vacaciones' => '23', 'prioridad' => 'Alta'])->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'tareas'), ['jornada_semanal' => '37.5', 'jornada_diaria' => '7.5', 'vacaciones' => '23', 'prioridad' => 'Alta'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'acreedores'), ['forma_pago' => 'Transferencia', 'pago_dias' => '15', 'tipo' => 'Arrendador', 'retencion' => '19'])->assertRedirect();
        $this->actingAs($this->admin)->post(route('parametros.guardar', 'calendario'), ['vista' => 'agenda', 'tareas' => '1'])->assertRedirect();
        Parametros::limpiar();
        $this->en($this->a)->get(route('acreedores.index', ['nuevo' => 1]))->assertOk()->assertSee('<option selected>Arrendador</option>', false);
        $this->actingAs($this->as)->get(route('tareas.horario'))->assertOk()->assertSee('7 h 30 min');
    }
}
