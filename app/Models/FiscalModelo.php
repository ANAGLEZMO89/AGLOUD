<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Borrador de un modelo tributario y su marca de presentado. */
class FiscalModelo extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'fiscal_modelos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['casillas' => 'array', 'borrador_at' => 'immutable_datetime', 'presentado_at' => 'immutable_date'];
    }
}
