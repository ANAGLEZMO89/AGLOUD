<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Actividad extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const TIPOS = ['Llamada', 'Reunión', 'Correo', 'Tarea', 'Visita'];

    protected $table = 'crm_actividades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'hecha' => 'boolean'];
    }

    public function oportunidad(): BelongsTo
    {
        return $this->belongsTo(Oportunidad::class);
    }
}
