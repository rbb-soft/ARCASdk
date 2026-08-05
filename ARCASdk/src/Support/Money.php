<?php

declare(strict_types=1);

namespace Rbbsoft\ArcaSdk\Support;

use InvalidArgumentException;

/**
 * Helper unico de redondeo decimal usando ext-bcmath.
 *
 * Reglas:
 *  - Nunca convierte a float. Toda operacion aritmetica es bcadd/bcsub/bcmul/bcdiv/bccomp.
 *  - Redondeo "half up" (estandar ARCA): cuando el digito decisivo es 5 exacto,
 *    se redondea hacia arriba en magnitud. 0.005 -> 0.01; -0.005 -> -0.01.
 *  - No produce "-0.00": cualquier resultado cero se normaliza a "0.00".
 *  - Usado por toda la cadena de calculo monetario del SDK (IvaCalculator, etc).
 *
 * Casos cubiertos por tests:
 *  - positivos, negativos, escala 0, escala 2
 *  - 0.1 + 0.2 (no drift)
 *  - 33.33 * 3
 *  - valores terminados en .005 / .015 / .025 (half-up siempre)
 *  - suma con acarreo (0.99 + 0.01 = 1.00)
 *  - input vacio, "-", null -> 0
 *  - input con coma decimal ("1,50") -> 1.50
 *  - input con separadores de miles ("1.234,56") -> 1234.56
 */
final class Money
{
    /**
     * Redondea $value a la escala indicada con redondeo "half up".
     *
     * @param string $value Importe en formato decimal como string (ej "121.005").
     * @param int    $scale Escala destino (default 2). Debe ser >= 0.
     */
    public static function round(string $value, int $scale = 2): string
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('scale debe ser >= 0');
        }

        $value = self::normalize($value);
        if ($value === '' || $value === '-' || $value === null) {
            return self::zero($scale);
        }

        $negative = str_starts_with($value, '-');
        $abs = $negative ? substr($value, 1) : $value;
        if ($abs === '' || $abs === '0' || bccomp($abs, '0', 64) === 0) {
            return self::zero($scale);
        }

        if (!str_contains($abs, '.')) {
            $result = $scale === 0 ? $abs : $abs . '.' . str_repeat('0', $scale);
            return ($negative && bccomp($result, '0', $scale) !== 0) ? '-' . $result : $result;
        }

        [$int, $frac] = explode('.', $abs, 2);
        if ($int === '') {
            $int = '0';
        }

        // Pad frac to scale+1 to read the deciding digit at position $scale.
        $padded = str_pad($frac, $scale + 1, '0');
        $decider = ord($padded[$scale]) - 48; // 0-9

        if ($scale === 0) {
            $truncated = $int;
            $bump = '1';
        } else {
            $truncated = $int . '.' . substr($padded, 0, $scale);
            $bump = '0.' . str_repeat('0', $scale - 1) . '1';
        }

        if ($decider >= 5) {
            $result = bcadd($truncated, $bump, $scale);
        } else {
            $result = $truncated;
        }

        // Normalizar -0.00 / -0 a 0.00
        if (bccomp($result, '0', $scale) === 0) {
            return self::zero($scale);
        }

        return $negative ? '-' . $result : $result;
    }

    /**
     * Suma N importes decimales (strings) con BCMath y devuelve el total redondeado a $scale.
     *
     * @param array<int, string> $values
     */
    public static function sum(array $values, int $scale = 2): string
    {
        $total = '0';
        foreach ($values as $v) {
            $total = bcadd($total, self::normalize((string) $v), $scale);
        }
        return self::round($total, $scale);
    }

    /**
     * Normaliza string decimal:
     *  - trim, strip separadores de miles y espacios.
     *  - coma decimal -> punto.
     *  - vacio o "-" -> "0".
     *  - entrada sin parte fraccionaria -> queda igual (no agrega .00).
     */
    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '' || $value === '-') {
            return '0';
        }
        // Si tiene ambos '.' y ',': el '.' es separador de miles y la ',' es decimal.
        // Formato latino: "1.234,56" -> quitar puntos, reemplazar coma por punto -> "1234.56".
        if (str_contains($value, '.') && str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
            return $value;
        }
        // Si solo tiene ',': formato latino puro, "1,50" -> "1.50".
        if (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
            return $value;
        }
        // Solo '.' (o ninguno): formato anglosajon, dejar como esta.
        return str_replace(' ', '', $value);
    }

    private static function zero(int $scale): string
    {
        return $scale === 0 ? '0' : '0.' . str_repeat('0', $scale);
    }
}
