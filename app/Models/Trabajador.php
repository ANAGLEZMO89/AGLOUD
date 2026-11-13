<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Trabajador por cuenta ajena de una empresa cliente. La ficha completa va cifrada en «datos». */
class Trabajador extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'trabajadores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'encrypted:array', 'alta' => 'date', 'baja' => 'date'];
    }

    protected static function booted(): void
    {
        // Las reglas del convenio se leen siempre de la configuración de la empresa: no se copian en la ficha
        static::saving(function (self $t) {
            $d = $t->datos ?? [];
            if (array_key_exists('_conv', $d)) {
                unset($d['_conv']);
                $t->datos = $d;
            }
        });
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(LaboralMovimiento::class);
    }

    /** Ficha con el id y el estado de la fila */
    public function ficha(): array
    {
        return ['id' => $this->id, 'estado' => $this->estado, 'baja' => $this->baja?->toDateString()] + ($this->datos ?? []);
    }
}
