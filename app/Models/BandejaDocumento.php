<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Factura o tique subido a la bandeja de Contabilidad, pendiente de revisar y contabilizar. */
class BandejaDocumento extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'bandeja_documentos';

    protected $guarded = ['id'];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class);
    }
}
