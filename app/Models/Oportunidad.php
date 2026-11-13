<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use App\Support\Parametros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Oportunidad extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const ETAPAS = ['Nuevo' => 10, 'Contactado' => 25, 'Presupuesto enviado' => 50, 'Negociación' => 75, 'Ganado' => 100, 'Perdido' => 0];

    public static function probabilidad(string $etapa): int
    {
        return (int) (Parametros::get('crm')['probabilidades'][$etapa] ?? self::ETAPAS[$etapa] ?? 0);
    }

    public static function origenes(): array
    {
        return Parametros::get('crm')['origenes'];
    }

    protected $table = 'crm_oportunidades';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'immutable_date', 'importe' => 'decimal:2'];
    }

    public function presupuestos(): HasMany
    {
        return $this->hasMany(Presupuesto::class, 'oportunidad_id');
    }

    public function cerrada(): bool
    {
        return in_array($this->etapa, ['Ganado', 'Perdido'], true);
    }
}
