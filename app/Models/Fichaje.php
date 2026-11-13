<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Marca del registro de jornada (art. 34.9 ET). No se modifica ni se borra: se conserva 4 años. */
class Fichaje extends Model
{
    use SoloDespacho;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['momento' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('El registro de jornada no se modifica: se corrige con una solicitud.'));
        static::deleting(fn () => throw new LogicException('El registro de jornada no se borra.'));
    }
}
