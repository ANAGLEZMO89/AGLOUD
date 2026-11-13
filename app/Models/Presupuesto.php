<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use App\Support\Plazos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Presupuesto extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const COLORES = ['Borrador' => ['#ECECE7', '#3C3C3C'], 'Enviado' => ['#FCEBCB', '#6E4300'], 'Aceptado' => ['#E6F6A8', '#2E3A00'], 'Facturado' => ['#0B0B0B', '#C8F031'], 'Rechazado' => ['#FDE7E7', '#8A1C1C'], 'Caducado' => ['#FDE7E7', '#8A1C1C']];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'lineas' => 'array', 'base' => 'decimal:2', 'cuota' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class);
    }

    public function vence(): CarbonImmutable
    {
        return $this->fecha->addDays($this->validez);
    }

    public function estadoVisible(): string
    {
        return $this->estado === 'Enviado' && $this->vence()->lt(Plazos::hoy()) ? 'Caducado' : $this->estado;
    }
}
