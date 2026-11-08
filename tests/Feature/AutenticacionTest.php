<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\RestablecerContrasena;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_correcto_redirige_segun_rol_y_queda_auditado(): void
    {
        $u = $this->usuario(User::ASESOR, null, ['email' => 'ana@test.es']);
        $this->post('/login', ['email' => 'ana@test.es', 'password' => 'Secreta123'])->assertRedirect('/');
        $this->get('/')->assertRedirect(route('escritorio'));
        $this->assertDatabaseHas('audit_logs', ['user_id' => $u->id, 'accion' => 'login']);
        $this->assertNotNull($u->fresh()->ultimo_acceso_at);
    }

    public function test_cliente_va_al_portal(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $u = $this->usuario(User::CLIENTE_ADMIN, $c);
        $this->actingAs($u)->get('/')->assertRedirect(route('portal.inicio'));
    }

    public function test_las_contrasenas_se_guardan_con_hash(): void
    {
        $u = $this->usuario(User::ASESOR);
        $this->assertNotSame('Secreta123', $u->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Secreta123', $u->password));
    }

    public function test_login_fallido_se_registra(): void
    {
        $this->usuario(User::ASESOR, null, ['email' => 'x@test.es']);
        $this->post('/login', ['email' => 'x@test.es', 'password' => 'mala'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertTrue(AuditLog::where('accion', 'login.fallido')->exists());
    }

    public function test_usuario_desactivado_no_entra(): void
    {
        $this->usuario(User::ASESOR, null, ['email' => 'off@test.es', 'activo' => false]);
        $this->post('/login', ['email' => 'off@test.es', 'password' => 'Secreta123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_cliente_de_baja_no_entra_al_portal(): void
    {
        $c = $this->cliente('Baja SL', 'B21890124', ['estado' => 'Baja']);
        $this->usuario(User::CLIENTE_ADMIN, $c, ['email' => 'baja@test.es']);
        $this->post('/login', ['email' => 'baja@test.es', 'password' => 'Secreta123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_bloqueo_tras_cinco_intentos(): void
    {
        $this->usuario(User::ASESOR, null, ['email' => 'b@test.es']);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'b@test.es', 'password' => 'mala']);
        }
        $this->post('/login', ['email' => 'b@test.es', 'password' => 'Secreta123'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_contrasena_provisional_obliga_a_cambiarla(): void
    {
        $u = $this->usuario(User::ASESOR, null, ['must_change_password' => true]);
        $this->actingAs($u)->get(route('clientes.index'))->assertRedirect(route('cuenta.nueva-contrasena'));
        $this->actingAs($u)->post(route('cuenta.nueva-contrasena.guardar'), ['password' => 'corta', 'password_confirmation' => 'corta'])->assertSessionHasErrors('password');
        $this->actingAs($u)->post(route('cuenta.nueva-contrasena.guardar'), ['password' => 'Secreta123', 'password_confirmation' => 'Secreta123'])->assertSessionHasErrors('password');
        $this->actingAs($u)->post(route('cuenta.nueva-contrasena.guardar'), ['password' => 'NuevaClave99', 'password_confirmation' => 'NuevaClave99'])->assertRedirect('/');
        $this->assertFalse($u->fresh()->must_change_password);
        $this->actingAs($u->fresh())->get(route('clientes.index'))->assertOk();
    }

    public function test_recuperacion_de_contrasena_por_correo(): void
    {
        Notification::fake();
        $u = $this->usuario(User::ASESOR, null, ['email' => 'r@test.es']);
        $this->post('/forgot-password', ['email' => 'r@test.es'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($u, RestablecerContrasena::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->post('/reset-password', ['token' => $token, 'email' => 'r@test.es', 'password' => 'OtraClave77', 'password_confirmation' => 'OtraClave77'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('OtraClave77', $u->fresh()->password));
    }

    public function test_recuperacion_no_revela_si_el_correo_existe(): void
    {
        $r = $this->post('/forgot-password', ['email' => 'noexiste@test.es']);
        $r->assertSessionHasNoErrors();
    }

    public function test_no_hay_registro_publico(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'x', 'email' => 'y@test.es', 'password' => 'Secreta123', 'password_confirmation' => 'Secreta123'])->assertNotFound();
    }

    public function test_dos_factores_pide_codigo_al_entrar(): void
    {
        $u = $this->usuario(User::ADMIN, null, ['email' => 'dos@test.es']);
        $u->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_recovery_codes' => encrypt(json_encode(['codigo-uno-1234'])), 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => 'dos@test.es', 'password' => 'Secreta123'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['recovery_code' => 'codigo-uno-1234'])->assertRedirect('/');
        $this->assertAuthenticatedAs($u);
    }
}
