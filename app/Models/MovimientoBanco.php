<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Movimiento de un extracto bancario importado. */
class MovimientoBanco extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'movimientos_banco';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'importe' => 'float', 'saldo' => 'float'];
    }

    public function apunte(): BelongsTo
    {
        return $this->belongsTo(Apunte::class);
    }
}
