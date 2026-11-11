<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Apunte extends Model
{
    use PerteneceACliente, SoloDespacho;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['debe' => 'decimal:2', 'haber' => 'decimal:2'];
    }

    public function asiento(): BelongsTo
    {
        return $this->belongsTo(Asiento::class);
    }
}
