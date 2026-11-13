<?php

namespace App\Services;

use App\Support\Dec;
use App\Support\DivisionPorCero;
use InvalidArgumentException;

/**
 * Fórmulas de los conceptos del convenio: un lenguaje pequeño y cerrado, sin eval ni código arbitrario.
 *
 *   números (1,75 o 1.75) · variables permitidas · referencias a otros conceptos (c_CODIGO)
 *   + − * / · paréntesis · min(a; b…) · max(a; b…) · redondear(x; decimales)
 *
 * Se analiza a un árbol, se validan las variables y las referencias (y los ciclos) al guardar, y se evalúa con decimales exactos.
 */
class Formula
{
    /** Variables que puede usar una fórmula: [nombre => [descripción, unidad]] */
    public const VARIABLES = [
        'salario_base' => ['Salario base mensual (si cobra por día: diario × 30)', '€/mes'],
        'salario_dia' => ['Salario base diario (si cobra por mes: mensual / 30)', '€/día'],
        'plus_convenio' => ['Plus de convenio mensual del trabajador', '€/mes'],
        'antiguedad' => ['Antigüedad del mes', '€/mes'],
        'jornada' => ['Coeficiente de jornada (1 = completa; 0,5 = media)', 'coeficiente'],
        'horas_semana' => ['Horas semanales del contrato', 'h/semana'],
        'horas_anuales' => ['Jornada anual del convenio a tiempo completo', 'h/año'],
        'horas_mes' => ['Horas mensuales a tiempo completo (jornada anual / 12)', 'h/mes'],
        'dias_mes' => ['Días naturales del mes', 'días'],
        'dias_alta' => ['Días naturales en alta en el mes', 'días'],
        'dias_laborables' => ['Días de lunes a viernes en alta en el mes', 'días'],
        'cantidad' => ['Cantidad introducida en la nómina (horas, días…) para conceptos variables', 'unidades'],
    ];

    private const FUNCIONES = ['min', 'max', 'redondear'];

    /** Árbol de la fórmula; lanza InvalidArgumentException con un mensaje en castellano si no es válida */
    public static function analizar(string $f): array
    {
        $tok = self::tokens($f);
        $i = 0;
        $ast = self::expr($tok, $i);
        if ($i < count($tok)) {
            throw new InvalidArgumentException('Sobra «'.$tok[$i][1].'» en la fórmula.');
        }

        return $ast;
    }

    /** Variables y referencias a conceptos que usa la fórmula */
    public static function usa(array $ast): array
    {
        $out = ['vars' => [], 'refs' => []];
        $walk = function ($n) use (&$walk, &$out) {
            match ($n[0]) {
                'var' => str_starts_with($n[1], 'c_') ? $out['refs'][] = substr($n[1], 2) : $out['vars'][] = $n[1],
                'op' => [$walk($n[2]), $walk($n[3])],
                'neg' => $walk($n[1]),
                'fn' => array_map($walk, $n[2]),
                default => null,
            };
        };
        $walk($ast);

        return ['vars' => array_values(array_unique($out['vars'])), 'refs' => array_values(array_unique($out['refs']))];
    }

    /**
     * Valida una fórmula: sintaxis, variables permitidas y referencias a conceptos existentes. Devuelve el error o null.
     *
     * @param  array<int, string>  $codigos  códigos de los otros conceptos de la versión
     */
    public static function validar(string $f, array $codigos = []): ?string
    {
        try {
            $u = self::usa(self::analizar($f));
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }
        if ($mal = array_diff($u['vars'], array_keys(self::VARIABLES))) {
            return 'Variable no permitida: '.implode(', ', $mal).'.';
        }
        if ($mal = array_diff($u['refs'], array_map('strtoupper', $codigos))) {
            return 'No hay ningún concepto con el código '.implode(', ', $mal).' (para usar otro concepto escribe c_ y su código).';
        }
        // Prueba con datos de ejemplo (todo a 1) para detectar divisiones por cero escritas en la propia fórmula
        try {
            self::evaluar($f, array_fill_keys(array_merge($u['vars'], array_map(fn ($r) => 'c_'.$r, $u['refs'])), 1));
        } catch (DivisionPorCero) {
            return 'La fórmula divide por cero.';
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Orden de cálculo de los conceptos con fórmula según sus referencias; detecta ciclos.
     *
     * @param  array<string, string>  $formulas  [código => fórmula]
     * @return array{0: array<int, string>, 1: ?string} [orden, error]
     */
    public static function orden(array $formulas): array
    {
        $dep = [];
        $formulas = array_combine(array_map('strtoupper', array_keys($formulas)), array_values($formulas)) ?: [];
        foreach ($formulas as $c => $f) {
            try {
                $dep[$c] = array_values(array_intersect(self::usa(self::analizar($f))['refs'], array_map('strtoupper', array_keys($formulas))));
            } catch (InvalidArgumentException) {
                $dep[$c] = [];
            }
        }
        $orden = [];
        $estado = [];
        $ciclo = null;
        $visita = function ($c, $camino) use (&$visita, &$orden, &$estado, &$ciclo, $dep) {
            if (($estado[$c] ?? 0) === 2 || $ciclo) {
                return;
            }
            if (($estado[$c] ?? 0) === 1) {
                $ciclo = 'Las fórmulas se llaman en círculo: '.implode(' → ', array_merge($camino, [$c])).'.';

                return;
            }
            $estado[$c] = 1;
            foreach ($dep[$c] as $d) {
                $visita($d, array_merge($camino, [$c]));
            }
            $estado[$c] = 2;
            $orden[] = $c;
        };
        foreach (array_keys($dep) as $c) {
            $visita($c, []);
        }

        return [$ciclo ? [] : $orden, $ciclo];
    }

    /**
     * Evalúa con decimales exactos. Devuelve [resultado, explicación con los valores sustituidos].
     *
     * @param  array<string, Dec|int|float|string|null>  $ctx  variables y c_CODIGO
     */
    public static function evaluar(array|string $ast, array $ctx): array
    {
        $ast = is_string($ast) ? self::analizar($ast) : $ast;
        $txt = [];
        $ev = function ($n) use (&$ev, $ctx, &$txt): Dec {
            switch ($n[0]) {
                case 'num':
                    return Dec::de($n[1]);
                case 'var':
                    if (! array_key_exists($n[1], $ctx) || $ctx[$n[1]] === null) {
                        throw new InvalidArgumentException('Falta el dato «'.$n[1].'» para calcular la fórmula.');
                    }
                    $v = Dec::de($ctx[$n[1]]);
                    $txt[$n[1]] = $n[1].' = '.$v->txt(4);

                    return $v;
                case 'neg':
                    return Dec::de(0)->menos($ev($n[1]));
                case 'op':
                    $a = $ev($n[2]);
                    $b = $ev($n[3]);

                    return match ($n[1]) {
                        '+' => $a->mas($b), '-' => $a->menos($b), '*' => $a->por($b),
                        '/' => $b->esCero() ? throw new DivisionPorCero('División por cero en la fórmula.') : $a->entre($b),
                    };
                case 'fn':
                    $args = array_map($ev, $n[2]);
                    if ($n[1] === 'redondear') {
                        return $args[0]->redondear(isset($args[1]) ? (int) $args[1]->float() : 2);
                    }

                    return array_reduce(array_slice($args, 1), fn ($x, $y) => $n[1] === 'min' ? $x->min($y) : $x->max($y), $args[0]);
            }
            throw new InvalidArgumentException('Fórmula no válida.');
        };
        $r = $ev($ast);

        return [$r, implode(' · ', array_values($txt))];
    }

    // ------------------------------------------------------------------ análisis
    private static function tokens(string $f): array
    {
        $f = trim($f);
        if ($f === '' || mb_strlen($f) > 500) {
            throw new InvalidArgumentException('La fórmula está vacía o es demasiado larga.');
        }
        $t = [];
        $i = 0;
        $n = strlen($f);
        while ($i < $n) {
            $c = $f[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif (ctype_digit($c) || (($c === ',' || $c === '.') && $i + 1 < $n && ctype_digit($f[$i + 1]))) {
                preg_match('/\G\d*(?:[.,]\d+)?/', $f, $m, 0, $i);
                $t[] = ['num', str_replace(',', '.', $m[0])];
                $i += strlen($m[0]);
            } elseif (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $f, $m, 0, $i);
                // Las referencias a conceptos (c_CODIGO) no distinguen mayúsculas
                $t[] = ['id', str_starts_with(strtolower($m[0]), 'c_') ? 'c_'.strtoupper(substr($m[0], 2)) : strtolower($m[0])];
                $i += strlen($m[0]);
            } elseif (str_contains('+-*/()', $c)) {
                $t[] = ['op', $c];
                $i++;
            } elseif ($c === ';') {
                $t[] = ['sep', ';'];
                $i++;
            } else {
                throw new InvalidArgumentException('Carácter no permitido en la fórmula: «'.$c.'».');
            }
        }

        return $t;
    }

    private static function expr(array $t, int &$i): array
    {
        $a = self::term($t, $i);
        while (isset($t[$i]) && $t[$i][0] === 'op' && in_array($t[$i][1], ['+', '-'], true)) {
            $op = $t[$i++][1];
            $a = ['op', $op, $a, self::term($t, $i)];
        }

        return $a;
    }

    private static function term(array $t, int &$i): array
    {
        $a = self::factor($t, $i);
        while (isset($t[$i]) && $t[$i][0] === 'op' && in_array($t[$i][1], ['*', '/'], true)) {
            $op = $t[$i++][1];
            $a = ['op', $op, $a, self::factor($t, $i)];
        }

        return $a;
    }

    private static function factor(array $t, int &$i): array
    {
        $x = $t[$i] ?? throw new InvalidArgumentException('La fórmula está incompleta.');
        if ($x[0] === 'op' && $x[1] === '-') {
            $i++;

            return ['neg', self::factor($t, $i)];
        }
        if ($x[0] === 'num') {
            $i++;

            return ['num', $x[1]];
        }
        if ($x[0] === 'op' && $x[1] === '(') {
            $i++;
            $e = self::expr($t, $i);
            if (($t[$i][1] ?? '') !== ')') {
                throw new InvalidArgumentException('Falta cerrar un paréntesis.');
            }
            $i++;

            return $e;
        }
        if ($x[0] === 'id') {
            $i++;
            if (($t[$i][1] ?? '') === '(') {
                if (! in_array($x[1], self::FUNCIONES, true)) {
                    throw new InvalidArgumentException('Función no permitida: «'.$x[1].'» (se admiten min, max y redondear).');
                }
                $i++;
                $args = [self::expr($t, $i)];
                while (($t[$i][0] ?? '') === 'sep') {
                    $i++;
                    $args[] = self::expr($t, $i);
                }
                if (($t[$i][1] ?? '') !== ')') {
                    throw new InvalidArgumentException('Falta cerrar el paréntesis de «'.$x[1].'».');
                }
                $i++;
                if ($x[1] !== 'redondear' && count($args) < 2) {
                    throw new InvalidArgumentException('«'.$x[1].'» necesita al menos dos valores separados por punto y coma.');
                }

                return ['fn', $x[1], $args];
            }

            return ['var', $x[1]];
        }
        throw new InvalidArgumentException('No se esperaba «'.$x[1].'» en la fórmula.');
    }
}
