<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Expediente;
use App\Models\Notificacion;
use App\Models\User;
use App\Notifications\ResumenNotificaciones;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificacionesExpedientesTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $desp;

    private Cliente $a;

    private Cliente $b;

    private User $as;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        CarbonImmutable::setTestNow('2026-11-10 10:00:00');
        $this->desp = $this->cliente('GA AGOPYME SL', 'B21000000');
        $this->desp->es_despacho = true;
        $this->desp->save();
        $this->a = $this->cliente('Empresa A SL', 'B21890124', ['telefono' => '600111222']);
        $this->b = $this->cliente('Empresa B SL', 'B21112230');
        $this->as = $this->usuario(User::ASESOR);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function registrar(array $extra = [])
    {
        return $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])->post(route('notificaciones.store'), $extra + [
            'cliente_id' => $this->a->id, 'plantilla' => '0', 'organismo' => 'AEAT', 'canal' => 'Sede AEAT', 'tipo' => 'Requerimiento de información',
            'asunto' => 'Libros IVA 2T', 'puesta' => '2026-11-09', 'plazo_n' => 10, 'plazo_tipo' => 'h', 'accion' => 'Requerimiento',
        ]);
    }

    public function test_registrar_abrir_y_calcular_vencimiento(): void
    {
        $this->registrar()->assertRedirect();
        $n = Notificacion::first();
        $this->assertSame('Pendiente', $n->estado);
        $this->assertSame('2026-11-19', $n->caducidad()->toDateString());
        $this->assertNull($n->vence);

        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])->post(route('notificaciones.abrir', $n), ['fecha' => '2026-11-10'])->assertRedirect();
        $n->refresh();
        $this->assertSame('Aceptada', $n->estado);
        $this->assertSame('2026-11-24', $n->vence->toDateString()); // 10 hábiles desde el 10/11

        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])->get(route('notificaciones.index', ['chip' => 1, 'sel' => $n->id]))
            ->assertOk()->assertSee('Requerimiento de información')->assertSee('24/11/2026')->assertSee('wa.me/34600111222', false);
        $this->actingAs($this->as)->get(route('notificaciones.ics', $n))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->assertSee('DTSTART;VALUE=DATE:20261124', false);
    }

    public function test_no_se_puede_abrir_con_fecha_futura_ni_tras_la_caducidad(): void
    {
        $this->registrar();
        $n = Notificacion::first();
        $this->actingAs($this->as)->post(route('notificaciones.abrir', $n), ['fecha' => '2026-11-11'])->assertSessionHasErrors('fecha');
        $this->assertSame('Pendiente', $n->fresh()->estado);
    }

    public function test_caducidad_automatica_a_los_diez_dias(): void
    {
        $this->registrar(['puesta' => '2026-10-20']); // ya caducada al registrar
        $n = Notificacion::first();
        $this->assertSame('Rechazada', $n->estado);
        $this->assertTrue($n->caducada);
        $this->assertSame('2026-10-30', $n->acceso->toDateString());

        $this->registrar(['puesta' => '2026-11-05']);
        $n2 = Notificacion::latest('id')->first();
        $this->assertSame('Pendiente', $n2->estado);
        Carbon::setTestNow('2026-11-16 08:00:00');
        CarbonImmutable::setTestNow('2026-11-16 08:00:00');
        $this->artisan('agcloud:resumen-notificaciones')->assertSuccessful();
        $n2->refresh();
        $this->assertSame('Rechazada', $n2->estado);
        $this->assertSame('2026-11-15', $n2->acceso->toDateString());
        $this->assertSame('2026-11-27', $n2->vence->toDateString()); // 10 hábiles desde el 15/11
    }

    public function test_el_portal_no_ve_notificaciones_y_solo_ve_expedientes_visibles_de_su_empresa(): void
    {
        $this->registrar();
        $n = Notificacion::first();
        $visible = Expediente::withoutGlobalScopes()->create(['cliente_id' => $this->a->id, 'ref' => 'EXP-2026-0001', 'tramite' => 'Alta en RETA', 'categoria' => 'Autónomos', 'apertura' => '2026-11-10', 'pasos' => [['l' => 'Uno', 'ok' => false]], 'docs' => [['l' => 'DNI', 'ok' => false]], 'portal' => true]);
        Expediente::withoutGlobalScopes()->create(['cliente_id' => $this->a->id, 'ref' => 'EXP-2026-0002', 'tramite' => 'Transferencia DGT', 'categoria' => 'Tráfico', 'apertura' => '2026-11-10', 'pasos' => [], 'docs' => [], 'portal' => false]);
        Expediente::withoutGlobalScopes()->create(['cliente_id' => $this->b->id, 'ref' => 'EXP-2026-0003', 'tramite' => 'Licencia de apertura', 'categoria' => 'Licencias', 'apertura' => '2026-11-10', 'pasos' => [], 'docs' => [], 'portal' => true]);
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($uA)->get(route('notificaciones.index'))->assertForbidden();
        $this->assertContains($this->actingAs($uA)->get(route('notificaciones.ics', $n))->status(), [403, 404]);
        $this->actingAs($uA);
        $this->assertSame(0, Notificacion::count());

        $this->assertSame([$visible->id], Expediente::pluck('id')->all());
        $this->actingAs($uA)->get(route('portal.inicio'))->assertOk()->assertSee('Tus trámites')->assertSee('Alta en RETA')->assertSee('Nos falta: DNI')->assertDontSee('Transferencia DGT')->assertDontSee('Licencia de apertura');
        $this->assertContains($this->actingAs($uA)->get(route('expedientes.show', $visible))->status(), [403, 404]);
    }

    public function test_expediente_desde_plantilla_y_vinculado_a_notificacion(): void
    {
        $this->registrar();
        $n = Notificacion::first();
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])
            ->get(route('expedientes.index', ['nuevo' => 1, 'cli' => $this->a->id, 'notif' => $n->id]))->assertOk()->assertSee('Requerimiento AEAT')->assertSee('Abrir expediente');

        $this->actingAs($this->as)->post(route('expedientes.store'), ['cliente_id' => $this->a->id, 'tramite' => 'Requerimiento AEAT', 'plazo' => '2026-11-24', 'honorarios' => '120,00', 'notificacion_id' => $n->id])->assertRedirect();
        $e = Expediente::first();
        $this->assertSame('EXP-2026-0001', $e->ref);
        $this->assertCount(5, $e->pasos);
        $this->assertSame('120.00', $e->honorarios);
        $this->assertSame($e->id, $n->fresh()->expediente_id);

        $this->actingAs($this->as)->post(route('expedientes.paso', [$e, 0]));
        $this->actingAs($this->as)->post(route('expedientes.estado', $e), ['estado' => 'Presentado']);
        $this->actingAs($this->as)->post(route('expedientes.nota', $e), ['texto' => 'Llamado el cliente']);
        $e->refresh();
        $this->assertTrue($e->pasos[0]['ok']);
        $this->assertSame('Presentado', $e->estado);
        $this->actingAs($this->as)->get(route('expedientes.show', $e))->assertOk()->assertSee('Listo para facturar')->assertSee('Llamado el cliente')->assertSee('Paso completado: Lectura y análisis')->assertSee('1/5');
        $this->actingAs($this->as)->withSession(['cliente_activo' => $this->desp->id])->get(route('expedientes.index'))->assertOk()->assertSee('120 €');

        $this->actingAs($this->as)->post(route('expedientes.store'), ['cliente_id' => $this->b->id, 'tramite' => 'Alta en RETA']);
        $this->assertSame('EXP-2026-0002', Expediente::latest('id')->first()->ref);
    }

    public function test_solo_admin_elimina_registros(): void
    {
        $this->registrar();
        $n = Notificacion::first();
        $this->actingAs($this->as)->delete(route('notificaciones.destroy', $n))->assertForbidden();
        $admin = $this->usuario(User::ADMIN);
        $this->actingAs($admin)->delete(route('notificaciones.destroy', $n))->assertRedirect();
        $this->assertSame(0, Notificacion::count());
    }

    public function test_resumen_por_correo_al_responsable(): void
    {
        Notification::fake();
        $this->a->asesor_id = $this->as->id;
        $this->a->save();
        $this->registrar();
        $this->artisan('agcloud:resumen-notificaciones')->assertSuccessful();
        Notification::assertSentTo($this->as, ResumenNotificaciones::class);
    }

    public function test_insignia_del_menu(): void
    {
        $this->registrar();
        $this->actingAs($this->as)->get(route('clientes.index'))->assertOk()->assertSee('<span class="nbadge">1</span>', false);
        $this->assertSame(1, Plazos::diasHasta('2026-11-11'));
    }
}
