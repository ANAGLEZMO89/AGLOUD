<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Condiciones del contrato (salario, jornada, categoría…) desde una fecha de efecto: el histórico que usa cada mes de nómina */
class TrabajadorCondicion extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'trabajador_condiciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['efectos_desde' => 'date', 'datos' => 'encrypted:array'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
