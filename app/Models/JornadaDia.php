<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Registro de jornada de un trabajador de una empresa cliente en un día: tramos de entrada y salida. */
class JornadaDia extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'jornada_dias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tramos' => 'array', 'fecha' => 'date'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }
}
