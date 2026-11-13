<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lee extractos bancarios en CSV o Excel (.xlsx) y devuelve los movimientos.
 * Detecta la fila de cabecera y las columnas por su nombre (Fecha, Concepto, Importe, o Cargo/Abono, Saldo).
 * El formato exacto de cada banco se afinará cuando se defina la plantilla.
 */
class ExtractoBancario
{
    /** @return array<int, array{fecha: string, concepto: string, importe: float, saldo: ?float}> */
    public static function leer(string $ruta, string $nombre): array
    {
        $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $filas = match ($ext) {
            'xlsx' => self::xlsx($ruta),
            'csv', 'txt' => self::csv($ruta),
            default => throw new InvalidArgumentException('Formato no admitido: sube el extracto en Excel (.xlsx) o CSV.'),
        };

        return self::interpretar($filas);
    }

    private static function csv(string $ruta): array
    {
        $txt = (string) file_get_contents($ruta);
        if (! mb_check_encoding($txt, 'UTF-8')) {
            $txt = mb_convert_encoding($txt, 'UTF-8', 'Windows-1252');
        }
        $txt = preg_replace('/^\xEF\xBB\xBF/', '', $txt);
        $primera = strtok($txt, "\n") ?: '';
        $sep = collect([';', ',', "\t"])->sortByDesc(fn ($s) => substr_count($primera, $s))->first();
        $out = [];
        foreach (preg_split('/\r\n|\n|\r/', $txt) as $l) {
            if (trim($l) !== '') {
                $out[] = str_getcsv($l, $sep);
            }
        }

        return $out;
    }

    private static function xlsx(string $ruta): array
    {
        $z = new ZipArchive;
        if ($z->open($ruta) !== true) {
            throw new InvalidArgumentException('No se puede abrir el Excel.');
        }
        $ss = [];
        if (($x = $z->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach ((new SimpleXMLElement($x, LIBXML_NONET))->si as $si) {
                $ss[] = isset($si->t) ? (string) $si->t : implode('', array_map(fn ($r) => (string) $r->t, iterator_to_array($si->r, false)));
            }
        }
        $hoja = $z->getFromName('xl/worksheets/sheet1.xml');
        $z->close();
        if ($hoja === false) {
            throw new InvalidArgumentException('El Excel no tiene hojas con datos.');
        }
        $out = [];
        foreach ((new SimpleXMLElement($hoja, LIBXML_NONET))->sheetData->row as $row) {
            $fila = [];
            foreach ($row->c as $cel) {
                $col = self::col(preg_replace('/\d/', '', (string) $cel['r']));
                $t = (string) $cel['t'];
                $v = $t === 's' ? ($ss[(int) $cel->v] ?? '') : ($t === 'inlineStr' ? (string) $cel->is->t : (string) $cel->v);
                $fila[$col] = $v;
            }
            if ($fila) {
                $max = max(array_keys($fila));
                $out[] = array_map(fn ($i) => $fila[$i] ?? '', range(0, $max));
            }
        }

        return $out;
    }

    private static function col(string $letras): int
    {
        $n = 0;
        foreach (str_split($letras) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    private static function interpretar(array $filas): array
    {
        $norm = fn ($s) => trim(strtolower(strtr((string) $s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', '.' => ''])));
        $cab = null;
        $idx = [];
        foreach (array_slice($filas, 0, 25) as $i => $f) {
            $h = array_map($norm, $f);
            $fecha = self::buscar($h, ['fecha operacion', 'f operacion', 'fecha', 'f valor', 'fecha valor']);
            $conc = self::buscar($h, ['concepto', 'descripcion', 'movimiento', 'detalle', 'observaciones']);
            $imp = self::buscar($h, ['importe', 'importe eur', 'importe (eur)', 'cantidad']);
            $cargo = self::buscar($h, ['cargo', 'cargos', 'debe', 'salidas', 'reintegros']);
            $abono = self::buscar($h, ['abono', 'abonos', 'haber', 'entradas', 'ingresos']);
            if ($fecha !== null && $conc !== null && ($imp !== null || ($cargo !== null && $abono !== null))) {
                $cab = $i;
                $idx = ['fecha' => $fecha, 'conc' => $conc, 'imp' => $imp, 'cargo' => $cargo, 'abono' => $abono, 'saldo' => self::buscar($h, ['saldo', 'saldo disponible', 'saldo contable'])];
                break;
            }
        }
        if ($cab === null) {
            throw new InvalidArgumentException('No encuentro las columnas del extracto: tiene que haber una fila con «Fecha», «Concepto» e «Importe» (o «Cargo» y «Abono»).');
        }
        $out = [];
        foreach (array_slice($filas, $cab + 1) as $f) {
            $fecha = self::fecha($f[$idx['fecha']] ?? '');
            if (! $fecha) {
                continue;
            }
            $importe = $idx['imp'] !== null ? self::num($f[$idx['imp']] ?? '') : self::num($f[$idx['abono']] ?? '') - abs(self::num($f[$idx['cargo']] ?? ''));
            if (abs($importe) < 0.005) {
                continue;
            }
            $out[] = ['fecha' => $fecha, 'concepto' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($f[$idx['conc']] ?? ''))), 0, 255) ?: 'Movimiento',
                'importe' => round($importe, 2), 'saldo' => $idx['saldo'] !== null && ($f[$idx['saldo']] ?? '') !== '' ? round(self::num($f[$idx['saldo']]), 2) : null];
        }
        if (! $out) {
            throw new InvalidArgumentException('El extracto no tiene movimientos con fecha e importe.');
        }

        return $out;
    }

    private static function buscar(array $h, array $nombres): ?int
    {
        foreach ($nombres as $n) {
            $i = array_search($n, $h, true);
            if ($i !== false) {
                return $i;
            }
        }
        foreach ($nombres as $n) {
            foreach ($h as $i => $v) {
                if ($v !== '' && str_starts_with($v, $n)) {
                    return $i;
                }
            }
        }

        return null;
    }

    private static function num(string $v): float
    {
        $v = trim($v);
        if (is_numeric($v)) {
            return (float) $v;
        }

        return Formato::parseNum($v);
    }

    private static function fecha(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
            // Fecha de Excel (días desde el 30/12/1899)
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) $v)->toDateString();
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $v, $m)) {
            $y = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

            return checkdate((int) $m[2], (int) $m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
            return $m[0];
        }

        return null;
    }
}
