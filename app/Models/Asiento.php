<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asiento extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date'];
    }

    public function apuntes(): HasMany
    {
        return $this->hasMany(Apunte::class)->orderBy('orden');
    }

    public function ejercicio(): BelongsTo
    {
        return $this->belongsTo(Ejercicio::class);
    }
}
