<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Atajos para trabajar con dinero sin errores de redondeo.
 * Nunca usamos float para dinero: 0.1 + 0.2 no da 0.3 en float.
 */
final class Dec
{
    /** Decimales internos al dividir (precisión "completa"). */
    public const ESCALA = 20;

    /** Convierte texto, número o null a BigDecimal (null o '' = 0). */
    public static function de(mixed $v): BigDecimal
    {
        if ($v instanceof BigDecimal) {
            return $v;
        }
        if ($v === null || $v === '') {
            return BigDecimal::zero();
        }
        return BigDecimal::of(is_float($v) ? (string) $v : $v);
    }

    /** División con precisión completa. */
    public static function div(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->dividedBy($b, self::ESCALA, RoundingMode::HalfUp);
    }

    /** Redondea a centavos y regresa texto: "12317.68". */
    public static function dinero(BigDecimal $v): string
    {
        return (string) $v->toScale(2, RoundingMode::HalfUp);
    }

    /** Redondea a 4 decimales y quita ceros sobrantes: "34.9267", "6". */
    public static function d4(BigDecimal $v): string
    {
        return (string) $v->toScale(4, RoundingMode::HalfUp)->strippedOfTrailingZeros();
    }

    /** Sube al siguiente entero: 1.2 → 2 (REDONDEAR.MAS). */
    public static function techo(BigDecimal $v): BigDecimal
    {
        return $v->toScale(0, RoundingMode::Ceiling);
    }

    /** parte / total con 4 decimales (0 si el total es 0). */
    public static function pct(BigDecimal $parte, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0';
        }
        return self::d4(self::div($parte, $total));
    }
}
