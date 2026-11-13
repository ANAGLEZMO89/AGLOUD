<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

/** Importe de un concepto para una categoría concreta */
class ConvenioConceptoImporte extends Model
{
    use SoloDespacho;

    protected $table = 'convenio_concepto_importes';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['importe' => 'decimal:4'];
    }
}
