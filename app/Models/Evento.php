<?php

namespace App\Models;

use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evento extends Model
{
    use SoloDespacho;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }
}
