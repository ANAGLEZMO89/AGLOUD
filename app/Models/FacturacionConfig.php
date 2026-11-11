<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;

class FacturacionConfig extends Model
{
    use PerteneceACliente, SoloDespacho;

    protected $table = 'facturacion_config';

    protected $guarded = ['id'];

    public const ESTILO = ['modelo' => 'clasica', 'color' => '#0B0B0B', 'forma' => 'suave', 'cuadros' => true, 'cabecera' => 'relleno', 'margen' => 'normal', 'fuente' => 'sans', 'logo' => true, 'regShow' => true, 'reg' => ''];

    public const SERIES = [['cod' => 'A', 'nombre' => 'Facturas ordinarias', 'tf' => 'F1'], ['cod' => 'S', 'nombre' => 'Facturas simplificadas', 'tf' => 'F2'], ['cod' => 'R', 'nombre' => 'Facturas rectificativas', 'tf' => 'R1']];

    protected function casts(): array
    {
        return ['series' => 'array', 'estilo' => 'array', 'catalogo' => 'array'];
    }

    public static function de(Cliente $c): self
    {
        return static::firstOrNew(['cliente_id' => $c->id], ['series' => self::SERIES, 'estilo' => self::ESTILO, 'catalogo' => [], 'pie' => '']);
    }

    public function estiloCompleto(): array
    {
        return array_merge(self::ESTILO, $this->estilo ?? []);
    }

    public function serie(string $cod): ?array
    {
        return collect($this->series)->firstWhere('cod', $cod);
    }
}
