<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Convenio aplicable a una empresa (o a uno de sus centros) desde una fecha */
class EmpresaConvenio extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'empresa_convenios';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date'];
    }

    public function convenio(): BelongsTo
    {
        return $this->belongsTo(Convenio::class);
    }
}
