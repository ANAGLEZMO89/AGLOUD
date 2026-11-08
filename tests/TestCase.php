<?php

namespace Tests;

use App\Models\Cliente;
use App\Models\Documento;
use App\Models\User;
use App\Services\AlmacenDocumentos;
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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->docsDir);
        parent::tearDown();
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
