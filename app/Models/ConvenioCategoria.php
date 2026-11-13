<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Categoría de una versión del convenio: grupo profesional y nivel (del convenio) separados del grupo de cotización */
class ConvenioCategoria extends Model
{
    use SoloDespacho;

    protected $table = 'convenio_categorias';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['salario_base' => 'decimal:4', 'grupo_cotizacion' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ConvenioVersion::class, 'version_id');
    }
}
