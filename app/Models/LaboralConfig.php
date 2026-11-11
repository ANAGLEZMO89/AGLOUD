<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Parámetros de laboral de una empresa: convenio, modelo de nómina, conexión RED/SEPE, contratos y cláusulas (cifrados). */
class LaboralConfig extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'laboral_config';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'encrypted:array'];
    }
}
