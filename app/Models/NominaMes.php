<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Nómina editada de un trabajador en un mes (borrador o emitida). */
class NominaMes extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'nominas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'encrypted:array', 'fecha_pago' => 'date', 'emitida_at' => 'datetime'];
    }

    public function emitida(): bool
    {
        return $this->estado === 'Emitida';
    }
}
