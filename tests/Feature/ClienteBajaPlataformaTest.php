<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClienteBajaPlataformaTest extends TestCase
{
    use RefreshDatabase;

    public function test_inhabilitar_plataforma_eliminar_y_recuperar(): void
    {
        $admin = $this->usuario(User::ADMIN);
        $as = $this->usuario(User::ASESOR);
        $c = $this->cliente('Bar El Puerto SL', 'B21345673');
        $u = $this->usuario(User::CLIENTE_ADMIN, $c);

        $this->actingAs($as)->get(route('clientes.show', $c))->assertOk()->assertSee('Plataforma habilitada')->assertSee('Inhabilitar plataforma')->assertDontSee('Eliminar cliente');
        $this->actingAs($as)->post(route('clientes.plataforma', $c), ['habilitar' => 0])->assertSessionHas('ok');
        $this->assertFalse($u->fresh()->activo);
        $this->post('/logout');
        $this->post('/login', ['email' => $u->email, 'password' => 'Secreta123'])->assertSessionHasErrors();
        $this->actingAs($as)->get(route('clientes.show', $c))->assertSee('Plataforma inhabilitada')->assertSee('Habilitar plataforma');
        $this->actingAs($as)->post(route('clientes.plataforma', $c), ['habilitar' => 1])->assertSessionHas('ok');
        $this->assertTrue($u->fresh()->activo);

        // Eliminar: solo administración; se puede recuperar
        $this->actingAs($as)->delete(route('clientes.destroy', $c))->assertForbidden();
        $this->actingAs($admin)->delete(route('clientes.destroy', $c))->assertRedirect(route('clientes.index'));
        $this->assertSoftDeleted('clientes', ['id' => $c->id]);
        $this->assertFalse($u->fresh()->activo);
        $this->actingAs($admin)->get(route('clientes.index'));   // consume el mensaje
        $this->actingAs($admin)->get(route('clientes.index'))->assertDontSee('Bar El Puerto SL');
        $this->actingAs($admin)->get(route('clientes.index', ['estado' => 'Eliminados']))->assertSee('Bar El Puerto SL')->assertSee('Recuperar');
        $this->actingAs($admin)->post(route('clientes.recuperar', $c->id))->assertRedirect(route('clientes.show', $c));
        $this->assertNotSoftDeleted('clientes', ['id' => $c->id]);
        $this->assertSame('Activo', Cliente::find($c->id)->estado);
    }
}
