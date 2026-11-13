<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** PDF del convenio (texto, anexo, tablas o revisión salarial), guardado cifrado en el gestor documental del despacho */
class ConvenioDocumento extends Model
{
    use SoloDespacho;

    public const TIPOS = ['texto' => 'Texto del convenio', 'anexo' => 'Anexo', 'tablas' => 'Tablas salariales', 'revision' => 'Revisión salarial'];

    protected $table = 'convenio_documentos';

    protected $guarded = ['id'];

    public function documento(): BelongsTo
    {
        return $this->belongsTo(Documento::class)->withoutGlobalScopes();
    }
}
