<?php

namespace App\Console\Commands;

use App\Models\Documento;
use App\Services\AlmacenDocumentos;
use Illuminate\Console\Command;

/** Borra subidas que se quedaron sin clasificar (borradores de más de 24 h). */
class Limpieza extends Command
{
    protected $signature = 'agcloud:limpiar';

    protected $description = 'Elimina subidas abandonadas sin clasificar';

    public function handle(AlmacenDocumentos $almacen): int
    {
        $n = 0;
        Documento::withoutGlobalScopes()->where('borrador', true)->where('created_at', '<', now()->subDay())->each(function ($d) use ($almacen, &$n) {
            $almacen->borrar($d->ruta);
            $d->delete();
            $n++;
        });
        $this->info($n.' subidas abandonadas eliminadas.');

        return self::SUCCESS;
    }
}
