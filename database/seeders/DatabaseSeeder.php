<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Producción: NO crea datos. La puesta en marcha se hace con
 *   php artisan agcloud:instalar
 * Datos de prueba (solo en local/pruebas):
 *   php artisan db:seed --class=DemoSeeder
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('Sin datos de ejemplo. Usa «php artisan agcloud:instalar» o, en pruebas, --class=DemoSeeder.');
    }
}
