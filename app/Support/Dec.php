<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal exacto para nóminas: entero escalado a 4 decimales (sin errores de coma flotante y sin depender de bcmath).
 * Política de redondeo: «la mitad hacia arriba» (0,005 → 0,01; −0,005 → −0,01), al céntimo por línea salvo que el concepto diga «al euro».
 * Rango admitido: ±900.000.000 € (sobra para cualquier nómina); fuera de rango lanza una excepción en vez de desbordar.
 */
final class Dec
{
    public const ESCALA = 10000;

    private const MAX = 9_000_000_000_000;

    private function __construct(public readonly int $u) {}

    public static function de(int|float|string|self|null $v): self
    {
        if ($v instanceof self) {
            return $v;
        }
        if ($v === null || $v === '') {
            return new self(0);
        }
        if (is_int($v)) {
            return self::chk($v * self::ESCALA);
        }
        if (is_float($v)) {
            return self::chk((int) round($v * self::ESCALA));
        }
        $s = str_replace([' ', '€', "\u{A0}"], '', trim($v));
        // «1.234,56» (español) o «1234.56»
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        if (! preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $s, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new InvalidArgumentException('Número no válido: «'.$v.'».');
        }
        $frac = str_pad(substr($m[3] ?? '', 0, 5), 5, '0');
        $u = (int) ($m[2] ?: '0') * self::ESCALA + (int) substr($frac, 0, 4) + ((int) $frac[4] >= 5 ? 1 : 0);

        return self::chk($m[1] === '-' ? -$u : $u);
    }

    private static function chk(int $u): self
    {
        if (abs($u) > self::MAX) {
            throw new InvalidArgumentException('Importe fuera de rango.');
        }

        return new self($u);
    }

    public function mas(self|int|float|string $b): self
    {
        return self::chk($this->u + self::de($b)->u);
    }

    public function menos(self|int|float|string $b): self
    {
        return self::chk($this->u - self::de($b)->u);
    }

    /** Producto exacto: se parte el segundo factor para no desbordar 64 bits */
    public function por(self|int|float|string $b): self
    {
        $b = self::de($b)->u;
        $hi = intdiv($b, self::ESCALA);
        $lo = $b % self::ESCALA;
        $a = $this->u * $hi;
        $p = $this->u * $lo;
        if (! is_int($a) || ! is_int($p)) {
            throw new InvalidArgumentException('Importe fuera de rango.');
        }

        return self::chk($a + self::divRedondeo($p, self::ESCALA));
    }

    public function entre(self|int|float|string $b): self
    {
        $b = self::de($b)->u;
        if ($b === 0) {
            throw new DivisionPorCero('División por cero.');
        }

        $a = $this->u * self::ESCALA;
        if (! is_int($a)) {
            throw new InvalidArgumentException('Importe fuera de rango.');
        }

        return self::chk(self::divRedondeo($a, $b));
    }

    /** a / b redondeando la mitad hacia arriba (alejándose de cero) */
    private static function divRedondeo(int $a, int $b): int
    {
        $q = intdiv($a, $b);
        $r = $a % $b;
        if (2 * abs($r) >= abs($b)) {
            $q += ($a < 0) !== ($b < 0) ? -1 : 1;
        }

        return $q;
    }

    /** Redondeo a n decimales (0 a 4), la mitad hacia arriba */
    public function redondear(int $dec = 2): self
    {
        $f = 10 ** (4 - max(0, min(4, $dec)));

        return new self(self::divRedondeo($this->u, $f) * $f);
    }

    public function min(self $b): self
    {
        return $this->u <= $b->u ? $this : $b;
    }

    public function max(self $b): self
    {
        return $this->u >= $b->u ? $this : $b;
    }

    public function esCero(): bool
    {
        return $this->u === 0;
    }

    public function menorQue(self $b): bool
    {
        return $this->u < $b->u;
    }

    public function float(): float
    {
        return $this->u / self::ESCALA;
    }

    /** Texto con formato español: 1.234,56 */
    public function txt(int $dec = 2): string
    {
        return Formato::num($this->redondear($dec)->float(), $dec);
    }
}
