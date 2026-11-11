<?php

namespace Tests;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use App\Services\AlmacenDocumentos;
use App\Support\Parametros;
use App\Support\Plazos;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    protected string $docsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->docsDir = sys_get_temp_dir().'/agcloud-test-'.getmypid().'-'.uniqid();
        config(['agcloud.docs_path' => $this->docsDir]);
        Parametros::limpiar();
        Plazos::limpiarCache();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsDir);
        parent::tearDown();
    }

    /**
     * Personal del despacho: si la prueba no ha elegido empresa, entra con el despacho elegido
     * (en la aplicación se elige en el Escritorio antes de abrir cualquier módulo).
     */
    public function actingAs($user, $guard = null)
    {
        parent::actingAs($user, $guard);
        if ($user instanceof User && $user->esDespacho() && ! session()->has('cliente_activo')) {
            $d = Cliente::where('es_despacho', true)->first();
            if (! $d) {
                $d = Cliente::create(['tipo' => 'Sociedad', 'nombre' => 'Despacho de pruebas SL', 'nif' => 'B00000000', 'estado' => 'Activo', 'provincia' => 'Huelva']);
                $d->forceFill(['es_despacho' => true])->save();
            }
            $this->withSession(['cliente_activo' => $d->id]);
        }

        return $this;
    }

    protected function usuario(string $rol, ?Cliente $c = null, array $extra = []): User
    {
        static $n = 0;
        $n++;

        return User::create($extra + ['name' => 'Usuario '.$n, 'email' => "u$n-".uniqid().'@test.es', 'password' => 'Secreta123', 'role' => $rol, 'cliente_id' => $c?->id]);
    }

    protected function cliente(string $nombre, string $nif, array $extra = []): Cliente
    {
        return Cliente::create($extra + ['tipo' => 'Sociedad', 'nombre' => $nombre, 'nif' => $nif, 'estado' => 'Activo', 'provincia' => 'Huelva']);
    }

    protected function documento(Cliente $c, array $extra = [], string $contenido = '%PDF-1.4 prueba'): Documento
    {
        $tmp = tempnam(sys_get_temp_dir(), 'agt');
        file_put_contents($tmp, $contenido);
        $g = app(AlmacenDocumentos::class)->guardar(new UploadedFile($tmp, 'x.pdf', 'application/pdf', null, true), $c->id);
        @unlink($tmp);

        return Documento::withoutGlobalScopes()->create($extra + [
            'cliente_id' => $c->id, 'carpeta' => 'fiscal/modelos', 'tipo' => 'mod', 'nombre' => 'doc-'.uniqid().'.pdf', 'nombre_original' => 'x.pdf',
            'fecha' => '2026-10-20', 'mime' => 'application/pdf', 'tamano' => $g['tamano'], 'ruta' => $g['ruta'], 'sha256' => $g['sha256'],
            'origen' => 'Despacho', 'compartido' => false,
        ]);
    }
}
