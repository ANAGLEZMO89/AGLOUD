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

    public const MODOS = [
        'normal' => ['Facturación normal', 'Factura en PDF. Los registros se encadenan y se conservan en AG Cloud (modalidad No VERI*FACTU).'],
        'electronica' => ['Factura electrónica', 'Además del PDF, cada factura se descarga en formato Facturae 3.2.2 (XML), el que piden las Administraciones Públicas (FACe) y grandes empresas.'],
        'verifactu' => ['Acogerse a VERI*FACTU', 'Cada registro se envía a la AEAT y la factura lleva la leyenda «VERI*FACTU». Una vez elegido, se mantiene todo el año natural.'],
    ];

    /** ¿Está realmente activo el envío a la AEAT? (no: se activará tras validar los esquemas oficiales con la administradora) */
    public static function envioActivo(): bool
    {
        return false;
    }

    public function modoEfectivo(): string
    {
        return $this->modo === 'verifactu' && ! self::envioActivo() ? 'normal' : ($this->modo ?: 'normal');
    }

    protected function casts(): array
    {
        return ['series' => 'array', 'estilo' => 'array', 'catalogo' => 'array'];
    }

    public static function de(Cliente $c): self
    {
        return static::firstOrNew(['cliente_id' => $c->id], ['modo' => 'normal', 'series' => self::SERIES, 'estilo' => self::ESTILO, 'catalogo' => [], 'pie' => '']);
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
