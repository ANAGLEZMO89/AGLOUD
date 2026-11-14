<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parámetro normativo de nómina con su periodo de vigencia, su fuente y su estado de verificación (común a todo el despacho) */
class ParametroNormativo extends Model
{
    protected $table = 'parametros_normativos';

    protected $guarded = ['id'];

    public const CLAVES = ['cotizacion' => 'Bases y tipos de cotización', 'smi' => 'Salario mínimo interprofesional', 'irpf' => 'Retenciones de IRPF', 'it' => 'Prestación de incapacidad temporal'];

    public const ESTADOS = ['pendiente' => 'Pendiente de verificar', 'resumen' => 'Contrastado con resúmenes', 'verificado' => 'Verificado en el BOE'];

    protected function casts(): array
    {
        return ['desde' => 'date', 'hasta' => 'date', 'valor' => 'array', 'verificado_at' => 'datetime'];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
