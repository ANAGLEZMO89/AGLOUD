<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tarea extends Model
{
    use SoloDespacho;

    public const PRIORIDADES = ['Alta' => '#E5484D', 'Media' => '#F5A524', 'Baja' => '#8A8A85'];

    public const REPETIR = ['' => 'No se repite', 'semanal' => 'Cada semana', 'mensual' => 'Cada mes', 'trimestral' => 'Cada trimestre'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'hecha' => 'boolean', 'hecha_at' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }
}
