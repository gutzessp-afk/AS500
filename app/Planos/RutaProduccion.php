<?php

namespace App\Planos;

/**
 * Ruta de producción de una pieza: por qué estaciones pasa y en qué orden.
 * Se DEDUCE del material (categoría y tipo de cálculo) y de lo que dicen la
 * descripción y las notas del plano (dobleces, barrenos, láser…).
 * NO toca la base de datos: se prueba sin Laravel.
 *
 * Es una propuesta: si en SULA alguna regla no aplica, se ajusta aquí.
 */
final class RutaProduccion
{
    /** Estaciones en el orden en que se recorren en planta: código => [nombre, nombre corto] */
    public const AREAS = [
        'TUB' => ['Corte de tubo', 'Tubo'],
        'LAS' => ['Corte láser', 'Láser'],
        'LAM' => ['Corte de lámina', 'Cizalla'],
        'DOB' => ['Doblez', 'Doblez'],
        'SOL' => ['Soldadura y pulido', 'Soldadura'],
        'SEC' => ['Seccionadora (melamina)', 'Seccionadora'],
        'CAN' => ['Enchapado de canto', 'Canto'],
        'MAQ' => ['Maquinado y barrenado', 'Maquinado'],
        'PIN' => ['Pintura', 'Pintura'],
        'ALM' => ['Almacén (surtir)', 'Almacén'],
        'ENS' => ['Ensamble', 'Ensamble'],
    ];

    private const LASER = '/LASER|LÁSER|BARREN|RANURA|CONTORNO|PERFORA|SUAJ|TROQUEL|CALADO/u';

    private const DOBLEZ = '/DOBL|CEJA|PESTAÑ|ÁNGULO|ANGULO|CHAROLA|FORRO|ZOCLO|CANAL|BAGUETA|ESCUADRA/u';

    private const SOLDADURA = '/SOLD|PUNTEAD/u';

    private const MAQUINADO = '/BARREN|MINIFIX|BISAGRA|CAZOLETA|RANURA|PERFORA|RUTEO|CNC|CAJEADO/u';

    /**
     * @param  array  $pieza  descripcion, notas, material_texto, largo_mm, ancho_mm, perimetro_pintable_mm, caras_pintables
     * @param  array|null  $material  ['categoria' => 'METAL', 'tipo' => 'AREA_KG'] o null si no tiene material
     * @param  int  $metalesEnSubensamble  cuántas piezas de metal hay en su subensamble (≥ 2 → se sueldan entre sí)
     * @return string[] códigos de AREAS en orden, ej. ['LAS', 'DOB', 'SOL', 'PIN', 'ENS']
     */
    public static function ruta(array $pieza, ?array $material, int $metalesEnSubensamble = 1): array
    {
        if (! $material) {
            return [];
        }
        $texto = mb_strtoupper(($pieza['descripcion'] ?? '').' '.($pieza['notas'] ?? '').' '.($pieza['material_texto'] ?? ''));
        $cat = $material['categoria'] ?? '';
        $tipo = $material['tipo'] ?? '';
        $pinta = (float) ($pieza['perimetro_pintable_mm'] ?? 0) > 0 || (float) ($pieza['caras_pintables'] ?? 0) > 0;
        $suelda = $metalesEnSubensamble >= 2 || preg_match(self::SOLDADURA, $texto);
        $ruta = [];

        if ($cat === 'METAL' && $tipo === 'LINEAL') {
            $ruta[] = 'TUB';
            if ($suelda) { $ruta[] = 'SOL'; }
            if ($pinta) { $ruta[] = 'PIN'; }
            $ruta[] = 'ENS';
        } elseif ($cat === 'METAL') {
            $chica = (float) ($pieza['largo_mm'] ?? 0) <= 400 && (float) ($pieza['ancho_mm'] ?? 0) <= 400;
            $ruta[] = preg_match(self::LASER, $texto) || $chica ? 'LAS' : 'LAM';
            if (preg_match(self::DOBLEZ, $texto)) { $ruta[] = 'DOB'; }
            if ($suelda) { $ruta[] = 'SOL'; }
            if ($pinta) { $ruta[] = 'PIN'; }
            $ruta[] = 'ENS';
        } elseif ($cat === 'MADERA' && $tipo === 'LINEAL') {
            $ruta = ['CAN'];                                    // el cubrecanto se usa en enchapado
        } elseif ($cat === 'MADERA') {
            $ruta[] = 'SEC';
            if (! str_contains($texto, 'SIN CANTO')) { $ruta[] = 'CAN'; }
            if (preg_match(self::MAQUINADO, $texto)) { $ruta[] = 'MAQ'; }
            $ruta[] = 'ENS';
        } elseif ($cat === 'HERRAJES') {
            $ruta = ['ALM', 'ENS'];
        } elseif (in_array($cat, ['CONSUMIBLES', 'EMPAQUE', 'ACABADO'], true)) {
            $ruta = ['ALM'];
        } else {
            $ruta = ['ENS'];
        }

        return $ruta;
    }

    /** "LAS › DOB › PIN" con nombres cortos: "Láser › Doblez › Pintura" */
    public static function texto(array $ruta): string
    {
        return $ruta ? implode(' › ', array_map(fn ($c) => self::AREAS[$c][1] ?? $c, $ruta)) : 'Por definir';
    }
}
