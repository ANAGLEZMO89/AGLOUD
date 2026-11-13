<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Parámetros fiscales de una empresa (cifrados). */
class FiscalConfig extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'fiscal_config';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'encrypted:array'];
    }
}
