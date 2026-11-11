<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use App\Support\Plazos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Factura recibida: de un proveedor (compra) o de un acreedor (gasto). */
class FacturaRecibida extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'facturas_recibidas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'vencimiento' => 'immutable_date', 'pagada_at' => 'immutable_date', 'deducible' => 'boolean',
            'base' => 'decimal:2', 'cuota' => 'decimal:2', 'retencion' => 'decimal:2', 'total' => 'decimal:2', 'iva_pct' => 'decimal:2', 'ret_pct' => 'decimal:2'];
    }

    public function tercero(): BelongsTo
    {
        return $this->belongsTo(Tercero::class);
    }

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }

    public function vencida(): bool
    {
        return ! $this->pagada_at && $this->vencimiento && $this->vencimiento->lt(Plazos::hoy());
    }

    public function estadoChip(): array
    {
        return match (true) {
            (bool) $this->pagada_at => ['Pagada', '#E6F6A8', '#2E3A00'],
            $this->vencida() => ['Vencida', '#FDE7E7', '#8A1C1C'],
            default => ['Pendiente', '#FCEBCB', '#6E4300'],
        };
    }

    /** Calcula cuota, retención y total a pagar */
    public static function importes(float $base, float $iva, float $ret): array
    {
        $cuota = round($base * $iva / 100, 2);
        $r = round($base * $ret / 100, 2);

        return ['cuota' => $cuota, 'retencion' => $r, 'total' => round($base + $cuota - $r, 2)];
    }
}
