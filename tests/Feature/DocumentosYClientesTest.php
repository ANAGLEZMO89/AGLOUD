<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use App\Notifications\BienvenidaAcceso;
use App\Services\Auditoria;
use App\Services\CopiaSeguridad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use LogicException;
use Tests\TestCase;
use ZipArchive;

class DocumentosYClientesTest extends TestCase
{
    use RefreshDatabase;

    public function test_subida_clasificacion_cifrado_y_descarga(): void
    {
        $c = $this->cliente('Café Las Colonias SL', 'B21890124');
        $as = $this->usuario(User::ASESOR);
        $contenido = '%PDF-1.4 '.str_repeat('factura de prueba ', 9000); // > 64 KB: varios bloques
        $f = UploadedFile::fake()->createWithContent('Factura Endesa 2026-10-05.pdf', $contenido);

        $this->actingAs($as)->withSession(['cliente_activo' => $c->id])->post(route('documentos.subir'), ['archivos' => [$f]])->assertRedirect();
        $d = Documento::first();
        $this->assertTrue($d->borrador);
        $this->assertSame('contable/recibidas', $d->carpeta);
        $this->assertSame('20261005_fra_factura-endesa.pdf', $d->nombre);

        // En disco NO está en claro
        $raw = file_get_contents($this->docsDir.'/'.$d->ruta);
        $this->assertStringNotContainsString('factura de prueba', $raw);

        $this->actingAs($as)->withSession(['cliente_activo' => $c->id])->post(route('documentos.confirmar'), ['docs' => [$d->id => ['nombre' => $d->nombre, 'carpeta' => 'contable/recibidas']]])->assertRedirect();
        $this->assertFalse($d->fresh()->borrador);

        $r = $this->actingAs($as)->get(route('documentos.descargar', $d->id));
        $r->assertOk();
        $this->assertSame($contenido, $r->streamedContent());
        $this->assertTrue(AuditLog::where('accion', 'documento.descarga')->where('objeto_id', $d->id)->exists());
    }

    public function test_subida_desde_el_portal_queda_por_revisar(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $u = $this->usuario(User::CLIENTE_ADMIN, $c);
        $this->actingAs($u)->post(route('portal.documentos.subir'), ['archivos' => [UploadedFile::fake()->createWithContent('nomina octubre.pdf', 'x')]]);
        $d = Documento::withoutGlobalScopes()->first();
        $this->actingAs($u)->post(route('portal.documentos.confirmar'), ['docs' => [$d->id => ['nombre' => $d->nombre, 'carpeta' => 'laboral/nominas']]])->assertRedirect();
        $d->refresh();
        $this->assertSame('Portal del cliente', $d->origen);
        $this->assertTrue($d->por_revisar);
        $this->assertSame($c->id, $d->cliente_id);
    }

    public function test_no_se_aceptan_ejecutables(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $this->actingAs($this->usuario(User::ASESOR))->withSession(['cliente_activo' => $c->id])
            ->post(route('documentos.subir'), ['archivos' => [UploadedFile::fake()->createWithContent('shell.php', '<?php echo 1;')]])->assertSessionHas('aviso');
        $this->assertSame(0, Documento::count());
    }

    public function test_papelera_avisa_del_plazo_de_conservacion_y_se_restaura(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $as = $this->usuario(User::ASESOR);
        $d = $this->documento($c, ['carpeta' => 'fiscal/modelos', 'fecha' => now()->toDateString()]);
        $this->actingAs($as)->withSession(['cliente_activo' => $c->id])->post(route('documentos.papelera', $d->id))->assertSessionHas('aviso');
        $this->assertNotNull($d->fresh()->eliminado_at);
        $this->actingAs($as)->withSession(['cliente_activo' => $c->id])->post(route('documentos.restaurar', $d->id));
        $this->assertNull($d->fresh()->eliminado_at);
    }

    public function test_alta_de_cliente_valida_nif_y_envia_acceso_al_portal(): void
    {
        Notification::fake();
        $as = $this->usuario(User::ASESOR);
        $base = ['tipo' => 'Sociedad', 'nombre' => 'Bar El Puerto SL', 'asesor_id' => $as->id, 'provincia' => 'Huelva'];

        $this->actingAs($as)->post(route('clientes.store'), $base + ['nif' => 'B21890123'])->assertSessionHasErrors('form');
        $this->assertSame(0, Cliente::clientes()->count());

        // El NIF tiene que cuadrar con el tipo de cliente
        $this->actingAs($as)->post(route('clientes.store'), $base + ['nif' => '12345678Z'])->assertSessionHasErrors('form');
        $this->actingAs($as)->post(route('clientes.store'), ['tipo' => 'Autónomo'] + $base + ['nif' => 'B21890124'])->assertSessionHasErrors('form');
        $this->assertSame(0, Cliente::clientes()->count());

        // Sin email se guarda igual; el acceso al portal se crea solo al indicar el correo
        $this->actingAs($as)->post(route('clientes.store'), $base + ['nif' => 'b-21890124'])->assertRedirect()->assertSessionHas('ok', fn ($m) => str_contains($m, 'Sin email'));
        $c = Cliente::clientes()->first();
        $this->assertSame(0, User::where('cliente_id', $c->id)->count());
        $this->actingAs($as)->put(route('clientes.update', $c), $base + ['nif' => 'B21890124', 'email' => 'bar@test.es'])->assertRedirect();

        $c->refresh();
        $this->assertSame('B21890124', $c->nif);
        $this->assertSame('Activo', $c->estado);
        $u = User::where('email', 'bar@test.es')->first();
        $this->assertSame(User::CLIENTE_ADMIN, $u->role);
        $this->assertSame($c->id, $u->cliente_id);
        $this->assertTrue($u->must_change_password);
        Notification::assertSentTo($u, BienvenidaAcceso::class);
        // Volver a guardar no crea otro acceso
        $this->actingAs($as)->put(route('clientes.update', $c), $base + ['nif' => 'B21890124', 'email' => 'bar@test.es'])->assertRedirect();
        $this->assertSame(1, User::where('cliente_id', $c->id)->count());

        // NIF duplicado
        $this->actingAs($as)->post(route('clientes.store'), $base + ['nif' => 'B21890124'])->assertSessionHasErrors('form');
    }

    public function test_seccion_bancos_valida_iban_y_se_guarda_cifrada(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $as = $this->usuario(User::ASESOR);
        $this->actingAs($as)->post(route('clientes.seccion.guardar', [$c, 'bancos']), ['d' => ['iban' => 'ES00 2100 0418 4502 0005 1332']])->assertSessionHasErrors('sub');
        $this->actingAs($as)->post(route('clientes.seccion.guardar', [$c, 'bancos']), ['d' => ['iban' => 'ES91 2100 0418 4502 0005 1332', 'principal' => '1']])->assertRedirect();
        $c->refresh();
        $this->assertSame('CaixaBank', $c->seccion('bancos')[0]['entidad']);
        $this->assertSame('CAIXESBBXXX', $c->seccion('bancos')[0]['bic']);
        // Caja rural (registro del Banco de España): la entidad y el BIC mandan sobre lo escrito a mano
        $this->actingAs($as)->post(route('clientes.seccion.guardar', [$c, 'bancos']), ['d' => ['iban' => 'ES44 3187 0000 0000 0000 0000', 'entidad' => 'CaixaBank', 'bic' => 'CAIXESBBXXX']])->assertRedirect();
        $b = $c->fresh()->seccion('bancos')[1];
        $this->assertSame('BCOEESMM187', $b['bic']);
        $this->assertStringContainsString('Caja R. del Sur', $b['entidad']);
        $this->assertStringNotContainsString('ES9121000418', (string) \DB::table('clientes')->where('id', $c->id)->value('datos'));
    }

    public function test_representantes_no_superan_el_100_por_cien(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $as = $this->usuario(User::ASESOR);
        $this->actingAs($as)->post(route('clientes.seccion.guardar', [$c, 'reps']), ['d' => ['nif' => '29400137L', 'nombre' => 'Uno', 'pct' => '60']])->assertRedirect();
        $this->actingAs($as)->post(route('clientes.seccion.guardar', [$c, 'reps']), ['d' => ['nif' => '29331847Q', 'nombre' => 'Dos', 'pct' => '50,5']])->assertSessionHasErrors('sub');
        $this->assertCount(1, $c->fresh()->seccion('reps'));
    }

    public function test_el_registro_de_auditoria_no_se_puede_modificar(): void
    {
        $this->actingAs($this->usuario(User::ASESOR));
        Auditoria::registrar('prueba', 'x');
        $l = AuditLog::first();
        $this->expectException(LogicException::class);
        $l->update(['descripcion' => 'manipulado']);
    }

    public function test_copia_de_seguridad_cifrada_con_bd_y_documentos(): void
    {
        $c = $this->cliente('Cli SL', 'B21890124');
        $this->documento($c);
        $r = app(CopiaSeguridad::class)->crear();
        $this->assertFileExists($r['ruta']);
        $this->assertSame(1, $r['documentos']);

        $z = new ZipArchive;
        $z->open($r['ruta']);
        $this->assertFalse($z->getFromName('base-de-datos.sql')); // sin contraseña no se lee
        $z->setPassword('clave-de-pruebas-123');
        $sql = $z->getFromName('base-de-datos.sql');
        $this->assertStringContainsString('Cli SL', $sql);
        $this->assertSame(ZipArchive::EM_AES_256, $z->statName('base-de-datos.sql')['encryption_method']);
        $z->close();
        @unlink($r['ruta']);
    }

    public function test_cabeceras_de_seguridad(): void
    {
        $r = $this->get('/login');
        $r->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $r->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("script-src 'self'", $r->headers->get('Content-Security-Policy'));
    }
}
