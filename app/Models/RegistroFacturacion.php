<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Registro de facturación encadenado: solo se añade; nunca se modifica ni se borra. */
class RegistroFacturacion extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const UPDATED_AT = null;

    protected $table = 'registros_facturacion';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'cuota' => 'decimal:2', 'total' => 'decimal:2', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Los registros de facturación no se modifican.'));
        static::deleting(fn () => throw new LogicException('Los registros de facturación no se borran.'));
    }
}
