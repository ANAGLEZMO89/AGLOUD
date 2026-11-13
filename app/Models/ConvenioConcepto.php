<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Concepto retributivo de una versión del convenio, con su naturaleza, unidad y tratamiento de cotización e IRPF */
class ConvenioConcepto extends Model
{
    use SoloDespacho;

    public const NATURALEZAS = ['salarial' => 'Salarial', 'extrasalarial' => 'Extrasalarial', 'especie' => 'En especie', 'mejora_it' => 'Mejora de IT', 'deduccion' => 'Deducción'];

    public const UNIDADES = ['mes' => 'por mes', 'dia_natural' => 'por día natural', 'dia_trabajado' => 'por día trabajado', 'dia_efectivo' => 'por día efectivo', 'hora' => 'por hora', 'anio' => 'por año', 'pago' => 'por pago'];

    protected $table = 'convenio_conceptos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['valor' => 'decimal:4', 'automatico' => 'boolean', 'prop_jornada' => 'boolean', 'prop_periodo' => 'boolean', 'en_pagas' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ConvenioVersion::class, 'version_id');
    }

    public function importes(): HasMany
    {
        return $this->hasMany(ConvenioConceptoImporte::class, 'concepto_id');
    }
}
