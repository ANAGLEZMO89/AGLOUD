<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Cómputo de plazos administrativos (Ley 39/2015).
 *
 * - Caducidad de la notificación electrónica: 10 días naturales desde la
 *   puesta a disposición sin acceder → se entiende rechazada (art. 43.2).
 * - Días hábiles: excluye sábados, domingos y festivos; se cuentan desde el
 *   día siguiente a la notificación (art. 30.2 y 30.3).
 * - Meses: «de fecha a fecha»; si en el mes de vencimiento no hay día
 *   equivalente, el último día del mes (art. 30.4).
 * - Si el último día es inhábil, se pasa al primer día hábil siguiente (art. 30.5).
 *
 * Festivos: los nacionales se calculan solos (incluido Viernes Santo). Los de
 * la comunidad autónoma (Andalucía: 28 de febrero y Jueves Santo) se añaden si
 * está configurada, y los locales o traslados por domingo van en FESTIVOS_EXTRA.
 */
class Plazos
{
    public const CADUCIDAD_DIAS = 10;

    private static array $cache = [];

    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone'))->startOfDay();
    }

    public static function caducidad(CarbonImmutable|string $puesta): CarbonImmutable
    {
        return self::d($puesta)->addDays(self::CADUCIDAD_DIAS);
    }

    /** Fecha fin del plazo para actuar, o null si no abre plazo. */
    public static function vencimiento(CarbonImmutable|string|null $desde, int $n, string $tipo): ?CarbonImmutable
    {
        if (! $desde || $n <= 0) {
            return null;
        }
        $d = self::d($desde);
        if ($tipo === 'h') {
            $k = 0;
            while ($k < $n) {
                $d = $d->addDay();
                if (self::esHabil($d)) {
                    $k++;
                }
            }

            return $d;
        }
        if ($tipo === 'm') {
            $fin = $d->addMonthsNoOverflow($n);
        } else {
            $fin = $d->addDays($n);
        }
        while (! self::esHabil($fin)) {
            $fin = $fin->addDay();
        }

        return $fin;
    }

    public static function texto(int $n, string $tipo): string
    {
        if ($n <= 0) {
            return 'Sin plazo de respuesta';
        }

        return match ($tipo) {
            'h' => $n.' '.($n === 1 ? 'día hábil' : 'días hábiles'),
            'm' => $n.' '.($n === 1 ? 'mes' : 'meses'),
            default => $n.' '.($n === 1 ? 'día natural' : 'días naturales'),
        };
    }

    public static function esHabil(CarbonImmutable $d): bool
    {
        return ! $d->isWeekend() && ! isset(self::festivos((int) $d->format('Y'))[$d->format('Y-m-d')]);
    }

    /** Días naturales entre hoy y la fecha (negativo si ya pasó). */
    public static function diasHasta(CarbonImmutable|string|null $f): ?int
    {
        return $f ? (int) self::hoy()->diffInDays(self::d($f), false) : null;
    }

    /** @return array<string,string> fecha => nombre */
    public static function festivos(int $y): array
    {
        $ca = (string) config('agcloud.comunidad', '');
        $extra = (array) config('agcloud.festivos_extra', []);
        $key = $y.'|'.$ca.'|'.implode(',', $extra);
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $pascua = self::pascua($y);
        $f = [
            "$y-01-01" => 'Año Nuevo',
            "$y-01-06" => 'Epifanía del Señor',
            $pascua->subDays(2)->format('Y-m-d') => 'Viernes Santo',
            "$y-05-01" => 'Fiesta del Trabajo',
            "$y-08-15" => 'Asunción de la Virgen',
            "$y-10-12" => 'Fiesta Nacional de España',
            "$y-11-01" => 'Todos los Santos',
            "$y-12-06" => 'Día de la Constitución',
            "$y-12-08" => 'Inmaculada Concepción',
            "$y-12-25" => 'Navidad',
        ];
        if ($ca === 'AN') {
            $f["$y-02-28"] = 'Día de Andalucía';
            $f[$pascua->subDays(3)->format('Y-m-d')] = 'Jueves Santo';
        }
        foreach ($extra as $e) {
            if (preg_match('/^'.$y.'-\d{2}-\d{2}$/', $e)) {
                $f[$e] = 'Festivo';
            }
        }

        return self::$cache[$key] = $f;
    }

    /** Domingo de Pascua (algoritmo de Meeus; no depende de la extensión calendar). */
    public static function pascua(int $y): CarbonImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($y, $mes, $dia, 0, 0, 0, config('app.timezone'));
    }

    public static function limpiarCache(): void
    {
        self::$cache = [];
    }

    private static function d(CarbonImmutable|string $f): CarbonImmutable
    {
        return $f instanceof CarbonImmutable ? $f->startOfDay() : CarbonImmutable::parse(substr((string) $f, 0, 10), config('app.timezone'))->startOfDay();
    }
}
