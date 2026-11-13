<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpedienteEvento extends Model
{
    use PerteneceACliente;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nota' => 'boolean', 'texto' => 'encrypted', 'created_at' => 'datetime'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
