<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AplicarVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplica_una_version_nueva_una_sola_vez(): void
    {
        $v = base_path('VERSION');
        $marca = storage_path('app/version-aplicada');
        @unlink($marca);
        file_put_contents($v, 'abc123-prueba');
        try {
            $this->artisan('agcloud:aplicar-version')->assertSuccessful();
            $this->assertSame('abc123-prueba', trim(file_get_contents($marca)));
            $this->assertSame(1, AuditLog::where('accion', 'despliegue')->count());
            $this->artisan('agcloud:aplicar-version')->assertSuccessful();
            $this->assertSame(1, AuditLog::where('accion', 'despliegue')->count());
        } finally {
            @unlink($v);
            @unlink($marca);
        }
    }
}
