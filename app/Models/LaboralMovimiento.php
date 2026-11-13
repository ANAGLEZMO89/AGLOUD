<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento de afiliación (alta, baja o variación) pendiente o comunicado a la TGSS. */
class LaboralMovimiento extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'laboral_movimientos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'comunicado_at' => 'datetime'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }
}
