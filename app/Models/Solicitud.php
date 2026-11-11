<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Solicitud extends Model
{
    use SoloDespacho;

    public const TIPOS = ['Vacaciones', 'Asuntos propios', 'Corrección de fichaje'];

    protected $table = 'solicitudes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['desde' => 'immutable_date', 'hasta' => 'immutable_date', 'resuelto_at' => 'datetime'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
