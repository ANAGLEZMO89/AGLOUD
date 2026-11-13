<?php

namespace App\Console\Commands;

use App\Services\Auditoria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Despliegue automático (Hostinger + GitHub): cuando llega una versión nueva
 * (archivo VERSION distinto del último aplicado), aplica las migraciones y
 * limpia cachés. Lo lanza la tarea programada cada minuto: no hace falta SSH.
 */
class AplicarVersion extends Command
{
    protected $signature = 'agcloud:aplicar-version';

    protected $description = 'Aplica migraciones y limpia cachés si se ha desplegado una versión nueva';

    public function handle(): int
    {
        $vFile = base_path('VERSION');
        if (! is_file($vFile)) {
            return self::SUCCESS; // entorno de desarrollo
        }
        $nueva = trim((string) file_get_contents($vFile));
        $marca = storage_path('app/version-aplicada');
        $actual = is_file($marca) ? trim((string) file_get_contents($marca)) : '';
        if ($nueva === '' || $nueva === $actual) {
            return self::SUCCESS;
        }
        // Aún no instalada (falta el .env o las tablas): no hace nada
        try {
            if (! Schema::hasTable('users')) {
                return self::SUCCESS;
            }
        } catch (Throwable) {
            return self::SUCCESS;
        }
        try {
            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');
            file_put_contents($marca, $nueva);
            Auditoria::registrar('despliegue', 'Versión aplicada: '.$nueva);
            $this->info('Versión aplicada: '.$nueva);

            return self::SUCCESS;
        } catch (Throwable $e) {
            report($e);
            Auditoria::registrar('despliegue.error', 'Fallo al aplicar la versión '.$nueva.': '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
