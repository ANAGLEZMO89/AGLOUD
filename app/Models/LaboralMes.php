<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Mes de nóminas de una empresa: variables por trabajador, confirmación de la liquidación y asiento. */
class LaboralMes extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'laboral_meses';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['incidencias' => 'encrypted:array', 'afi' => 'encrypted:array', 'ss_confirmada_at' => 'datetime', 'remesa_at' => 'datetime'];
    }
}
