<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Registro Mercantil: lo hecho en un ejercicio (plantillas, libros, depósito). */
class RmEstado extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'rm_estados';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array'];
    }
}
