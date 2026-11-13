<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Convenio colectivo del despacho: se introduce una vez y se asigna a las empresas */
class Convenio extends Model
{
    use SoloDespacho;

    public const ESTADOS = ['borrador' => 'Borrador', 'revision' => 'Pendiente de revisión', 'aprobado' => 'Aprobado', 'sustituido' => 'Sustituido'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha_publicacion' => 'date'];
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(ConvenioVersion::class)->orderByRaw('efectos_desde is null')->orderBy('efectos_desde')->orderBy('id');
    }

    public function documentos(): HasMany
    {
        return $this->hasMany(ConvenioDocumento::class);
    }

    public function empresas(): HasMany
    {
        return $this->hasMany(EmpresaConvenio::class);
    }
}
