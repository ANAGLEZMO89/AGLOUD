<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versión de un convenio: vigencia del texto, efectos económicos de sus tablas y reglas (pagas, antigüedad, IT…) */
class ConvenioVersion extends Model
{
    use SoloDespacho;

    protected $table = 'convenio_versiones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['vigencia_desde' => 'date', 'vigencia_hasta' => 'date', 'efectos_desde' => 'date', 'efectos_hasta' => 'date', 'aprobada_at' => 'datetime',
            'reglas' => 'array', 'referencias' => 'array'];
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }

    public function categorias(): HasMany
    {
        return $this->hasMany(ConvenioCategoria::class, 'version_id')->orderBy('orden')->orderBy('id');
    }

    public function conceptos(): HasMany
    {
        return $this->hasMany(ConvenioConcepto::class, 'version_id')->orderBy('orden')->orderBy('id');
    }

    /** ¿Aplica en esta fecha? Sin fecha de efectos se considera aplicable siempre (con advertencia) */
    public function aplicaEn(string $fecha): bool
    {
        return (! $this->efectos_desde || $this->efectos_desde->toDateString() <= $fecha) && (! $this->efectos_hasta || $this->efectos_hasta->toDateString() >= $fecha);
    }
}
