<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Corrección de un fichaje con su motivo (trazabilidad del registro de jornada). */
class JornadaCorreccion extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'jornada_correcciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }
}
