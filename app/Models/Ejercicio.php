<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ejercicio extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bloqueo' => 'immutable_date'];
    }

    public function asientos(): HasMany
    {
        return $this->hasMany(Asiento::class);
    }

    public function cerrado(): bool
    {
        return $this->estado === 'Cerrado';
    }
}
