<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** Datos internos: un usuario del portal no ve ninguna fila (defensa en profundidad). */
trait SoloDespacho
{
    public static function bootSoloDespacho(): void
    {
        static::addGlobalScope('solo_despacho', function (Builder $q) {
            $u = auth()->user();
            if ($u && $u->esPortal()) {
                $q->whereRaw('1 = 0');
            }
        });
    }
}
