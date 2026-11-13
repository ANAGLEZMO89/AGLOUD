<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ausencia de un trabajador (vacaciones, incapacidad temporal, permisos). */
class JornadaAusencia extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'jornada_ausencias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'hospitalizacion' => 'boolean', 'base_reguladora' => 'decimal:4', 'revisada_at' => 'datetime'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }
}
