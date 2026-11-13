<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Horas de un día que van a la nómina: extraordinarias, complementarias o de un concepto del convenio por hora (nocturnas, festivas…) */
class LaboralHora extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'laboral_horas';

    protected $guarded = ['id'];

    public const TIPOS = ['extra' => 'Horas extraordinarias', 'complementaria' => 'Horas complementarias', 'concepto' => 'Horas de un concepto del convenio'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'horas' => 'decimal:2'];
    }

    public function trabajador(): BelongsTo
    {
        return $this->belongsTo(Trabajador::class);
    }
}
