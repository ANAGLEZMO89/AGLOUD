<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Factura emitida. Es INALTERABLE: una vez emitida solo cambian el cobro y
 * el paso a «Anulada» (que genera su registro de anulación encadenado).
 */
class Factura extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const TIPOS = ['F1' => 'Factura', 'F2' => 'Factura simplificada', 'R1' => 'Factura rectificativa'];

    public const FORMAS_PAGO = ['Transferencia', 'Domiciliación SEPA', 'Tarjeta', 'Bizum', 'Efectivo'];

    private const MODIFICABLES = ['cobrada', 'cobrada_at', 'cobro_medio', 'cobro_asiento_id', 'estado', 'asiento_id', 'updated_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha' => 'immutable_date', 'cobrada_at' => 'immutable_date', 'lineas' => 'array', 'ivas' => 'array',
            'cobrada' => 'boolean', 'datos_fe' => 'array', 'iban' => 'encrypted', 'iban_emisor' => 'encrypted',
            'base' => 'decimal:2', 'cuota' => 'decimal:2', 'retencion' => 'decimal:2', 'suplidos' => 'decimal:2', 'total' => 'decimal:2', 'ret_pct' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $f) {
            $cambios = array_diff(array_keys($f->getDirty()), self::MODIFICABLES);
            if ($cambios) {
                throw new LogicException('Una factura emitida no se puede modificar: se corrige con una rectificativa.');
            }
            if ($f->isDirty('estado') && ! ($f->getOriginal('estado') === 'Emitida' && $f->estado === 'Anulada')) {
                throw new LogicException('Cambio de estado no permitido.');
            }
        });
        static::deleting(fn () => throw new LogicException('Las facturas emitidas no se borran: se anulan (obligación de conservación).'));
    }

    public function registros(): HasMany
    {
        return $this->hasMany(RegistroFacturacion::class)->orderBy('id');
    }

    public function rectificada(): BelongsTo
    {
        return $this->belongsTo(self::class, 'rectifica_id');
    }

    public function tipoTexto(): string
    {
        return self::TIPOS[$this->tipo] ?? 'Factura';
    }

    public function vencimiento(): ?CarbonImmutable
    {
        return $this->pago_dias ? $this->fecha->addDays($this->pago_dias) : null;
    }

    /** [texto, fondo, color] */
    public function estadoChip(): array
    {
        return match (true) {
            $this->estado === 'Anulada' => ['Anulada', '#ECECE7', '#6A6A65'],
            $this->cobrada => ['Cobrada', '#E6F6A8', '#2E3A00'],
            default => ['Pendiente', '#FCEBCB', '#6E4300'],
        };
    }
}
