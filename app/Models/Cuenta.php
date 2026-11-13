<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Subcuenta propia de una empresa (9 dígitos). */
class Cuenta extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $guarded = ['id'];
}
