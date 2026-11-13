<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Formato español: 1.234,56 € · dd/mm/aaaa */
class Formato
{
    public static function num(float|int|string|null $n, int $dec = 2): string
    {
        $n = (float) ($n ?? 0);
        $s = number_format(abs($n), $dec, ',', '.');

        return ($n < 0 ? '−' : '').$s;
    }

    public static function eur(float|int|string|null $n, int $dec = 2): string
    {
        return self::num($n, $dec).' €';
    }

    public static function entero(int|float $n): string
    {
        return number_format((float) $n, 0, ',', '.');
    }

    /** Acepta «1.234,56», «1234,56» o «1234.56» */
    public static function parseNum(?string $v): float
    {
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace([' ', '€', "\u{A0}", '−'], ['', '', '', '-'], $s);
        // «1.200» o «12.500.000» sin coma: puntos de miles (formato español)
        if (! str_contains($s, ',') && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    public static function fecha(CarbonInterface|string|null $d): string
    {
        if (! $d) {
            return '';
        }
        if (is_string($d)) {
            $p = explode('-', substr($d, 0, 10));

            return count($p) === 3 ? $p[2].'/'.$p[1].'/'.$p[0] : $d;
        }

        return $d->format('d/m/Y');
    }

    public static function fechaHora(?CarbonInterface $d): string
    {
        return $d ? $d->timezone(config('app.timezone'))->format('d/m/Y H:i') : '';
    }

    public static function iniciales(?string $s): string
    {
        $s = preg_replace('/\b(SLU|SL|SA|CB|SC|Hnos\.)\b/u', '', (string) $s);
        $w = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $out = '';
        foreach (array_slice($w, 0, 2) as $x) {
            $out .= mb_substr($x, 0, 1);
        }

        return mb_strtoupper($out ?: '·');
    }

    public static function carpeta(string $f): string
    {
        [$a, $b] = array_pad(explode('/', $f, 2), 2, null);
        $g = config('agcloud.carpetas.'.$a) ?? config('agcloud.carpetas.otros');

        return $g[0].' · '.($g[1][$b] ?? $b);
    }

    public static function iban(?string $v): string
    {
        return trim(chunk_split(strtoupper(preg_replace('/\s+/', '', (string) $v)), 4, ' '));
    }

    public static function fechaLarga(CarbonInterface $d): string
    {
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return mb_convert_case($dias[$d->dayOfWeek], MB_CASE_TITLE).', '.$d->day.' de '.$meses[$d->month - 1].' de '.$d->year;
    }

    public static function saludo(CarbonInterface $d): string
    {
        $h = (int) $d->format('G');

        return $h < 14 ? 'Buenos días' : ($h < 21 ? 'Buenas tardes' : 'Buenas noches');
    }

    /** Nombre del mes en minúscula: 6 → junio */
    public static function mesNombre(int $m): string
    {
        return ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'][max(1, min(12, $m)) - 1];
    }
}
