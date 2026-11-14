<?php

namespace App\Services;

use App\Models\ParametroNormativo;
use App\Support\Formato;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Lectura de los parámetros normativos vigentes en una fecha. Si no hay ninguno para esa fecha (p. ej. un año nuevo todavía sin cargar),
 * devuelve el más reciente y lo marca como no exacto para que la nómina lo avise: nunca un cero ni un valor inventado.
 */
class Normativa
{
    private static array $cache = [];

    public static function olvidar(): void
    {
        self::$cache = [];
    }

    /** @return array{valor: array, desde: string, hasta: ?string, fuente: string, estado: string, exacto: bool, id: int} */
    public static function get(string $clave, Carbon|string|null $fecha = null): array
    {
        $f = $fecha instanceof Carbon ? $fecha->toDateString() : (is_string($fecha) && preg_match('/^\d{4}-\d{2}-\d{2}/', $fecha) ? substr($fecha, 0, 10) : now()->toDateString());
        $k = $clave.'|'.$f;
        if (isset(self::$cache[$k])) {
            return self::$cache[$k];
        }
        $q = ParametroNormativo::where('clave', $clave);
        $p = (clone $q)->whereDate('desde', '<=', $f)->where(fn ($x) => $x->whereNull('hasta')->orWhereDate('hasta', '>=', $f))->orderByDesc('desde')->first();
        $exacto = (bool) $p;
        $p ??= (clone $q)->whereDate('desde', '<=', $f)->orderByDesc('desde')->first() ?? (clone $q)->orderBy('desde')->first();
        if (! $p) {
            throw new RuntimeException('Faltan los parámetros normativos «'.(ParametroNormativo::CLAVES[$clave] ?? $clave).'». Cárgalos en Laboral › Parámetros normativos.');
        }

        return self::$cache[$k] = ['valor' => $p->valor, 'desde' => $p->desde->toDateString(), 'hasta' => $p->hasta?->toDateString(), 'fuente' => $p->fuente,
            'estado' => $p->estado, 'exacto' => $exacto, 'id' => $p->id];
    }

    public static function v(string $clave, Carbon|string|null $fecha = null): array
    {
        return self::get($clave, $fecha)['valor'];
    }

    /** Texto para la explicación de un cálculo: fuente, vigencia y estado */
    public static function cita(string $clave, Carbon|string|null $fecha = null): string
    {
        $p = self::get($clave, $fecha);

        return (ParametroNormativo::CLAVES[$clave] ?? $clave).': '.$p['fuente'].' · '.(ParametroNormativo::ESTADOS[$p['estado']] ?? $p['estado'])
            .($p['exacto'] ? '' : ' · ATENCIÓN: no hay parámetros cargados para esta fecha; se usan los vigentes desde el '.Formato::fecha($p['desde']));
    }
}
