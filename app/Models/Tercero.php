<?php

namespace App\Models;

use App\Models\Concerns\PerteneceACliente;
use App\Models\Concerns\SoloDespacho;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Proveedor (cuenta 400) o acreedor (cuenta 410) de una empresa. */
class Tercero extends Model
{
    use PerteneceACliente, SoloDespacho;

    public const CLASES = ['proveedor' => ['Proveedores', 'Proveedor', '400', 'compra'], 'acreedor' => ['Acreedores', 'Acreedor', '410', 'gasto']];

    public const TIPOS_ACREEDOR = ['Servicios', 'Profesional', 'Arrendador'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['retencion' => 'decimal:2', 'iban' => 'encrypted'];
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(FacturaRecibida::class);
    }

    /** Siguiente subcuenta libre: 400000001, 400000002… */
    public static function siguienteCuenta(int $clienteId, string $clase): string
    {
        $pre = self::CLASES[$clase][2];
        $max = DB::table('terceros')->where('cliente_id', $clienteId)->where('cuenta', 'like', $pre.'%')->max('cuenta');

        return $max ? str_pad((string) ((int) $max + 1), 9, '0', STR_PAD_LEFT) : $pre.'000001';
    }
}
