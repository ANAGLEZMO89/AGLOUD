<?php

namespace App\Support;

/** Validadores portados del prototipo (nifCheck, ibanOk, bankOf, provCod). */
class Validacion
{
    private const LETRAS = 'TRWAGMYFPDXBNJZSQVHLCKE';

    /** @return array{ok: bool, empty?: bool, msg: string} */
    public static function nif(?string $v): array
    {
        $s = strtoupper(preg_replace('/[\s.\-]/', '', (string) $v));
        if ($s === '') {
            return ['ok' => false, 'empty' => true, 'msg' => ''];
        }
        $L = self::LETRAS;
        if (preg_match('/^\d{8}[A-Z]$/', $s)) {
            $l = $L[(int) substr($s, 0, 8) % 23];

            return $l === $s[8] ? ['ok' => true, 'msg' => 'DNI correcto · persona física'] : ['ok' => false, 'msg' => 'DNI incorrecto: la letra debería ser '.$l];
        }
        if (preg_match('/^[XYZ]\d{7}[A-Z]$/', $s)) {
            $l = $L[(int) (strpos('XYZ', $s[0]).substr($s, 1, 7)) % 23];

            return $l === $s[8] ? ['ok' => true, 'msg' => 'NIE correcto · persona extranjera'] : ['ok' => false, 'msg' => 'NIE incorrecto: la letra debería ser '.$l];
        }
        if (preg_match('/^[KLM]\d{7}[A-Z]$/', $s)) {
            $l = $L[(int) substr($s, 1, 7) % 23];

            return $l === $s[8] ? ['ok' => true, 'msg' => 'NIF especial ('.$s[0].') correcto'] : ['ok' => false, 'msg' => 'NIF incorrecto: la letra debería ser '.$l];
        }
        if (preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $s)) {
            $d = substr($s, 1, 7);
            $sum = 0;
            for ($i = 0; $i < 7; $i++) {
                $n = (int) $d[$i];
                if ($i % 2 === 0) {
                    $n *= 2;
                    $n = intdiv($n, 10) + ($n % 10);
                }
                $sum += $n;
            }
            $c = (10 - ($sum % 10)) % 10;
            $cl = 'JABCDEFGHI'[$c];
            $last = $s[8];
            $onlyL = str_contains('PQRSNW', $s[0]);
            $onlyD = str_contains('ABEH', $s[0]);
            $ok = $onlyL ? $last === $cl : ($onlyD ? $last === (string) $c : ($last === $cl || $last === (string) $c));
            $T = ['A' => 'Sociedad anónima', 'B' => 'Sociedad limitada', 'C' => 'Sociedad colectiva', 'D' => 'Sociedad comanditaria', 'E' => 'Comunidad de bienes', 'F' => 'Cooperativa', 'G' => 'Asociación / fundación', 'H' => 'Comunidad de propietarios', 'J' => 'Sociedad civil', 'N' => 'Entidad extranjera', 'P' => 'Corporación local', 'Q' => 'Organismo público', 'R' => 'Congregación religiosa', 'S' => 'Órgano de la Administración', 'U' => 'Unión temporal de empresas', 'V' => 'Otro tipo', 'W' => 'Establecimiento permanente de no residente'];

            return $ok ? ['ok' => true, 'msg' => 'CIF correcto · '.$T[$s[0]]]
                : ['ok' => false, 'msg' => 'CIF incorrecto: el dígito de control debería ser '.($onlyL ? $cl : ($onlyD ? $c : $c.' o '.$cl))];
        }

        return ['ok' => false, 'msg' => 'Formato no válido. Usa DNI (12345678Z), NIE (X1234567L) o CIF (B12345674).'];
    }

    public static function nifOk(?string $v): bool
    {
        return self::nif($v)['ok'];
    }

    public static function normalizaNif(?string $v): string
    {
        return strtoupper(preg_replace('/[\s.\-]/', '', (string) $v));
    }

    public static function iban(?string $v): bool
    {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $v));
        if (! preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $s) || (str_starts_with($s, 'ES') && strlen($s) !== 24)) {
            return false;
        }
        $r = substr($s, 4).substr($s, 0, 4);
        $m = 0;
        foreach (str_split($r) as $ch) {
            $digits = ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
            foreach (str_split($digits) as $dg) {
                $m = ($m * 10 + (int) $dg) % 97;
            }
        }

        return $m === 1;
    }

    /** [entidad, BIC] a partir de un IBAN español, o null */
    public static function banco(?string $v): ?array
    {
        $s = strtoupper(preg_replace('/\s+/', '', (string) $v));
        if (! str_starts_with($s, 'ES') || strlen($s) < 8) {
            return null;
        }
        $c = substr($s, 4, 4);
        $b = config('agcloud.bancos.'.$c);
        if ($b) {
            return $b;
        }

        return str_starts_with($c, '30') ? ['Caja Rural / cooperativa de crédito ('.$c.')', 'BCOEESMM'.substr($c, 1)] : null;
    }

    /** Provincia a partir de los 2 primeros dígitos (CP o CCC) */
    public static function provinciaDe(?string $v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        if (strlen($d) < 2) {
            return '';
        }
        $n = (int) substr($d, 0, 2);

        return $n >= 1 && $n <= 52 ? config('agcloud.provincias_ine')[$n - 1] : '';
    }

    /** Contraseña: mínimo 8, con letras y números (regla del prototipo) */
    public static function contrasenaOk(string $p): bool
    {
        return strlen($p) >= 8 && preg_match('/[A-Za-z]/', $p) && preg_match('/\d/', $p);
    }

    public static function generaContrasena(): string
    {
        $abc = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $num = '23456789';
        $p = '';
        for ($i = 0; $i < 9; $i++) {
            $p .= $abc[random_int(0, strlen($abc) - 1)];
        }
        for ($i = 0; $i < 3; $i++) {
            $p .= $num[random_int(0, strlen($num) - 1)];
        }

        return str_shuffle($p);
    }
}
