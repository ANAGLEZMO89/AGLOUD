<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Ajustes clave/valor, cifrados en la base de datos. */
class Ajuste extends Model
{
    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['clave', 'valor'];

    protected function casts(): array
    {
        return ['valor' => 'encrypted'];
    }

    public static function get(string $k, ?string $def = null): ?string
    {
        return static::find($k)?->valor ?? $def;
    }

    public static function put(string $k, ?string $v): void
    {
        static::updateOrCreate(['clave' => $k], ['valor' => $v]);
    }
}
