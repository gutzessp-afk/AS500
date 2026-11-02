<?php

namespace App\Support;

/** Formatos de pantalla (los valores llegan como texto para no perder centavos). */
class Formato
{
    /** $1,234.56 */
    public static function dinero(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $n = (float) $v;

        return ($n < 0 ? '-$' : '$').number_format(abs($n), 2, '.', ',');
    }

    /** $34.9267 (para precios unitarios, donde importan los decimales) */
    public static function dineroFino(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $n = (float) $v;
        $decimales = abs($n - round($n, 2)) > 0.00001 ? 4 : 2;

        return '$'.number_format($n, $decimales, '.', ',');
    }

    /** 0.25 → 25% */
    public static function pct(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }

        return rtrim(rtrim(number_format((float) $v * 100, 2, '.', ','), '0'), '.').'%';
    }

    /** 1800.0000 → 1,800 · 0.2500 → 0.25 */
    public static function num(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $s = number_format((float) $v, 4, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    /** m2 → m² */
    public static function unidad(?string $u): string
    {
        return $u === 'm2' ? 'm²' : (string) $u;
    }

    public static function fecha(mixed $v): string
    {
        return $v ? \Illuminate\Support\Carbon::parse($v)->locale('es')->isoFormat('D MMM YYYY') : '—';
    }
}
