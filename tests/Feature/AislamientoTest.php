<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-tenant: un usuario del portal NUNCA puede ver datos de otro cliente,
 * se comprueba en el backend (no solo en la interfaz).
 */
class AislamientoTest extends TestCase
{
    use RefreshDatabase;

    private Cliente $a;

    private Cliente $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->cliente('Empresa A SL', 'B21890124');
        $this->b = $this->cliente('Empresa B SL', 'B21112230');
    }

    public function test_cliente_no_puede_descargar_ni_ver_documento_de_otro_cliente(): void
    {
        $docB = $this->documento($this->b, ['compartido' => true]);
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);

        $this->actingAs($uA)->get(route('documentos.descargar', $docB->id))->assertNotFound();
        $this->actingAs($uA)->get(route('documentos.ver', $docB->id))->assertNotFound();
        $this->actingAs($uA)->post(route('documentos.renombrar', $docB->id), ['nombre' => 'hack.pdf'])->assertNotFound();
        $this->actingAs($uA)->post(route('documentos.papelera', $docB->id))->assertNotFound();
        $this->assertSame(0, Documento::withoutGlobalScopes()->whereNotNull('eliminado_at')->count());
    }

    public function test_el_filtro_global_limita_las_consultas_al_cliente_del_usuario(): void
    {
        $this->documento($this->a, ['compartido' => true]);
        $this->documento($this->b, ['compartido' => true]);
        $this->documento($this->b, ['compartido' => true]);

        $this->actingAs($this->usuario(User::CLIENTE_ADMIN, $this->a));
        $this->assertSame(1, Documento::count());
        $this->assertSame([$this->a->id], Cliente::pluck('id')->all());
        $this->assertNull(Cliente::find($this->b->id));
    }

    public function test_usuario_del_portal_no_puede_crear_documentos_en_otro_cliente(): void
    {
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $this->actingAs($uA);
        $d = $this->documento($this->b); // intenta forzar cliente_id de B
        $this->assertSame($this->a->id, $d->fresh()->cliente_id);
    }

    public function test_cliente_admin_no_ve_lo_no_compartido_de_su_propia_empresa(): void
    {
        $privado = $this->documento($this->a, ['compartido' => false]);
        $compartido = $this->documento($this->a, ['compartido' => true]);
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);

        $this->actingAs($uA)->get(route('documentos.descargar', $privado->id))->assertNotFound();
        $this->actingAs($uA)->get(route('documentos.descargar', $compartido->id))->assertOk();
    }

    public function test_empleado_solo_ve_lo_que_ha_subido_el(): void
    {
        $emp = $this->usuario(User::CLIENTE_EMPLEADO, $this->a);
        $suyo = $this->documento($this->a, ['origen' => 'Portal del cliente', 'subido_por' => $emp->id, 'compartido' => true]);
        $deLaEmpresa = $this->documento($this->a, ['compartido' => true]);

        $this->actingAs($emp)->get(route('documentos.descargar', $suyo->id))->assertOk();
        $this->actingAs($emp)->get(route('documentos.descargar', $deLaEmpresa->id))->assertNotFound();
        $this->actingAs($emp)->get(route('portal.documentos'))->assertOk()->assertSee($suyo->nombre)->assertDontSee($deLaEmpresa->nombre);
    }

    public function test_usuario_del_portal_no_accede_a_ag_cloud(): void
    {
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        foreach ([route('escritorio'), route('clientes.index'), route('clientes.show', $this->a), route('clientes.edit', $this->a), route('documentos.index'), route('admin.equipo'), route('admin.auditoria')] as $url) {
            $this->actingAs($uA)->get($url)->assertForbidden();
        }
        // La ficha de OTRO cliente ni siquiera existe para él
        $this->assertContains($this->actingAs($uA)->get(route('clientes.show', $this->b))->status(), [403, 404]);
        $this->assertContains($this->actingAs($uA)->post(route('escritorio.seleccionar', $this->b))->status(), [403, 404]);
        $this->actingAs($uA)->post(route('escritorio.seleccionar', $this->a))->assertForbidden();
    }

    public function test_asesor_no_accede_a_administracion_y_el_despacho_no_al_portal(): void
    {
        $as = $this->usuario(User::ASESOR);
        $this->actingAs($as)->get(route('admin.equipo'))->assertForbidden();
        $this->actingAs($as)->get(route('admin.auditoria'))->assertForbidden();
        $this->actingAs($as)->get(route('portal.inicio'))->assertForbidden();
        $this->actingAs($as)->get(route('clientes.show', $this->b))->assertOk();
    }

    public function test_el_despacho_ve_todos_los_clientes(): void
    {
        $docB = $this->documento($this->b);
        $as = $this->usuario(User::ASESOR);
        $this->actingAs($as)->get(route('documentos.descargar', $docB->id))->assertOk();
        $this->actingAs($as)->get(route('clientes.index'))->assertSee('Empresa A SL')->assertSee('Empresa B SL');
    }

    public function test_un_cliente_no_puede_compartir_ni_marcar_revisado(): void
    {
        $uA = $this->usuario(User::CLIENTE_ADMIN, $this->a);
        $d = $this->documento($this->a, ['origen' => 'Portal del cliente', 'compartido' => true, 'por_revisar' => true]);
        $this->actingAs($uA)->post(route('documentos.revisado', $d->id))->assertForbidden();
        $this->actingAs($uA)->post(route('documentos.compartir', $d->id))->assertForbidden();
        $this->assertTrue($d->fresh()->por_revisar);
    }

    public function test_sin_sesion_todo_redirige_al_login(): void
    {
        $d = $this->documento($this->a);
        $this->get(route('documentos.descargar', $d->id))->assertRedirect(route('login'));
        $this->get(route('clientes.index'))->assertRedirect(route('login'));
        $this->get(route('portal.documentos'))->assertRedirect(route('login'));
    }

    public function test_selector_de_entorno_no_permite_redirecciones_externas(): void
    {
        $as = $this->usuario(User::ASESOR);
        foreach (['//evil.com', '/\\evil.com', 'https://evil.com', 'javascript:alert(1)'] as $v) {
            $this->actingAs($as)->post(route('escritorio.seleccionar', $this->a), ['volver' => $v])->assertRedirect(route('escritorio'));
        }
        $this->actingAs($as)->post(route('escritorio.seleccionar', $this->a), ['volver' => '/documentos?f=fiscal'])->assertRedirect('/documentos?f=fiscal');
    }
}
