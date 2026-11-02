<?php

namespace App\Planos;

use App\Costeo\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Prepara los datos del PDF del despiece: ya formateados y con todas las cuentas hechas.
 * NO toca la base de datos (recibe arreglos), por eso se puede probar sin Laravel.
 *
 *   - Código único por pieza: 0048-0017-02-04 (plano-subensamble-pieza).
 *   - "Por mueble" = cantidad de la pieza × cantidad de su subensamble.
 *   - "A producir" = por mueble × cantidad de muebles del pedido.
 *   - Ruta de producción por pieza (RutaProduccion) y una hoja por área.
 *   - Material a comprar: neto + merma → presentaciones (hojas, tramos, paquetes) y kg de lámina.
 *   - REVISAR: piezas con avisos en rojo o sin material del catálogo.
 */
final class ReporteDespiece
{
    /**
     * @param  array  $subs  [['nombre','cantidad','pagina','piezas' => [['descripcion','material_texto','material_id','cantidad',
     *                       'largo_mm','ancho_mm','perimetro_pintable_mm','caras_pintables','notas','avisos' => [['nivel','texto']]]]]]
     * @param  array  $catalogo  material_id => ['clave','descripcion','tipo','categoria','presentacion','contenido','kg_m2']
     * @param  array  $opciones  ['cantidad' => 10, 'merma' => '0.10', 'prefijo' => '0048-0017']
     */
    public static function armar(array $subs, array $catalogo, array $opciones = []): array
    {
        $muebles = max(1, (int) ($opciones['cantidad'] ?? 1));
        $merma = Dec::de((string) ($opciones['merma'] ?? '0.10'));
        $prefijo = (string) ($opciones['prefijo'] ?? 'P');

        $salida = [];
        $porMaterial = [];
        $porArea = [];
        $piezasMueble = BigDecimal::zero();
        $m2Pintura = BigDecimal::zero();
        $porRevisar = 0;

        // Un subensamble sin piezas no le sirve a nadie en piso
        $subs = array_values(array_filter($subs, fn ($s) => ! empty($s['piezas'])));

        foreach ($subs as $si => $s) {
            $veces = Dec::de(self::num($s['cantidad'] ?? '1') ?: '1');
            $metales = count(array_filter($s['piezas'] ?? [], fn ($p) => ($catalogo[$p['material_id'] ?? 0]['categoria'] ?? '') === 'METAL'));
            $piezas = [];

            foreach (array_values($s['piezas'] ?? []) as $pi => $p) {
                $mat = $catalogo[$p['material_id'] ?? 0] ?? null;
                $cant = Dec::de(self::num($p['cantidad'] ?? '0'));
                $porMueble = $cant->multipliedBy($veces);
                $aProducir = $porMueble->multipliedBy($muebles);
                $largo = Dec::de(self::num($p['largo_mm'] ?? '0'));
                $ancho = Dec::de(self::num($p['ancho_mm'] ?? '0'));
                $perimetro = Dec::de(self::num($p['perimetro_pintable_mm'] ?? '0'));
                $caras = Dec::de(self::num($p['caras_pintables'] ?? '0'));
                $errores = array_values(array_map(fn ($a) => $a['texto'],
                    array_filter($p['avisos'] ?? [], fn ($a) => ($a['nivel'] ?? '') === 'error')));
                $revisar = $errores !== [] || ! $mat;
                $porRevisar += $revisar ? 1 : 0;
                $piezasMueble = $piezasMueble->plus($porMueble);
                $ruta = RutaProduccion::ruta($p, $mat, $metales);
                // m² a pintar: perímetro × largo (tubo) o caras × largo × ancho (lámina)
                $m2Pintura = $m2Pintura->plus(Dec::div(($perimetro->isZero() ? $caras->multipliedBy($ancho) : $perimetro)
                    ->multipliedBy($largo)->multipliedBy($aProducir), Dec::de(1000000)));
                $codigo = $prefijo.'-'.str_pad($si + 1, 2, '0', STR_PAD_LEFT).'-'.str_pad($pi + 1, 2, '0', STR_PAD_LEFT);

                $fila = [
                    'codigo' => $codigo,
                    'descripcion' => (string) $p['descripcion'],
                    'subensamble' => (string) $s['nombre'],
                    'material_clave' => $mat['clave'] ?? null,
                    'material_desc' => $mat['descripcion'] ?? null,
                    'material_texto' => (string) ($p['material_texto'] ?? ''),
                    'cantidad' => self::bonito($cant),
                    'por_mueble' => self::bonito($porMueble),
                    'a_producir' => self::bonito($aProducir),
                    'largo' => $largo->isZero() ? '—' : self::bonito($largo),
                    'ancho' => $ancho->isZero() ? '—' : self::bonito($ancho),
                    'pintura' => ! $perimetro->isZero() ? self::bonito($perimetro).' mm'
                        : (! $caras->isZero() ? self::bonito($caras).' '.($caras->isEqualTo(1) ? 'cara' : 'caras') : '—'),
                    'notas' => trim((string) ($p['notas'] ?? '')),
                    'revisar' => $revisar,
                    'avisos' => $errores,
                    'ruta' => $ruta,
                    'ruta_texto' => RutaProduccion::texto($ruta),
                ];
                $piezas[] = $fila;

                // Hojas por área (Ensamble va aparte, por subensamble)
                foreach ($ruta as $area) {
                    if ($area !== 'ENS') {
                        $porArea[$area][] = $fila;
                    }
                }

                // Material: lo que consume UN mueble
                if ($mat) {
                    $id = $p['material_id'];
                    $porMaterial[$id] ??= ['mat' => $mat, 'piezas' => BigDecimal::zero(), 'medida' => BigDecimal::zero()];
                    $porMaterial[$id]['piezas'] = $porMaterial[$id]['piezas']->plus($porMueble);
                    $porMaterial[$id]['medida'] = $porMaterial[$id]['medida']->plus(match ($mat['tipo'] ?? '') {
                        'LINEAL' => Dec::div($largo, Dec::de(1000))->multipliedBy($porMueble),
                        'AREA', 'AREA_KG' => Dec::div($largo->multipliedBy($ancho), Dec::de(1000000))->multipliedBy($porMueble),
                        'PESO' => $porMueble,       // en material por peso la "cantidad" ya son kg
                        default => BigDecimal::zero(),
                    });
                }
            }

            $salida[] = [
                'numero' => str_pad($si + 1, 2, '0', STR_PAD_LEFT),
                'nombre' => (string) $s['nombre'],
                'cantidad' => self::bonito($veces),
                'a_producir' => self::bonito($veces->multipliedBy($muebles)),
                'pagina' => $s['pagina'] ?? null,
                'piezas' => $piezas,
            ];
        }

        [$materiales, $kgLamina] = self::compras($porMaterial, $muebles, $merma);

        // Áreas en el orden de la planta
        $areas = [];
        foreach (RutaProduccion::AREAS as $codigo => [$nombre]) {
            if (! empty($porArea[$codigo])) {
                $total = array_reduce($porArea[$codigo], fn ($t, $f) => $t->plus(Dec::de(str_replace(',', '', $f['a_producir']))), BigDecimal::zero());
                $areas[] = ['codigo' => $codigo, 'nombre' => $nombre, 'filas' => $porArea[$codigo], 'grupos' => self::grupos($porArea[$codigo]),
                    'renglones' => count($porArea[$codigo]), 'total' => self::bonito($total)];
            }
        }

        return [
            'subs' => $salida,
            'materiales' => $materiales,
            'areas' => $areas,
            'totales' => [
                'subensambles' => count($salida),
                'piezas_mueble' => self::bonito($piezasMueble),
                'a_producir' => self::bonito($piezasMueble->multipliedBy($muebles)),
                'muebles' => $muebles,
                'materiales' => count($materiales),
                'por_revisar' => $porRevisar,
                'kg_lamina' => self::bonito($kgLamina, 1),
                'm2_pintura' => self::bonito($m2Pintura, 2),
                'merma_pct' => self::bonito($merma->multipliedBy(100), 1),
            ],
        ];
    }

    /** Hoja de un área: junta las piezas por material y corta primero las más largas. */
    private static function grupos(array $filas): array
    {
        $numero = fn ($v) => (float) str_replace(',', '', $v);
        usort($filas, fn ($a, $b) => strnatcmp($a['material_clave'] ?? 'ZZ', $b['material_clave'] ?? 'ZZ')
            ?: $numero($b['largo']) <=> $numero($a['largo']));

        $grupos = [];
        foreach ($filas as $f) {
            $clave = $f['material_clave'] ?? 'Sin material';
            $grupos[$clave] ??= ['clave' => $clave, 'descripcion' => $f['material_desc'] ?? '', 'total' => BigDecimal::zero(), 'filas' => []];
            $grupos[$clave]['filas'][] = $f;
            $grupos[$clave]['total'] = $grupos[$clave]['total']->plus(Dec::de(str_replace(',', '', $f['a_producir'])));
        }

        return array_values(array_map(fn ($g) => ['total' => self::bonito($g['total'])] + $g, $grupos));
    }

    /** Material a comprar para TODO el pedido: neto, con merma, presentaciones y kg. */
    private static function compras(array $porMaterial, int $muebles, BigDecimal $merma): array
    {
        uasort($porMaterial, fn ($a, $b) => strnatcmp($a['mat']['clave'], $b['mat']['clave']));
        $filas = [];
        $kgLamina = BigDecimal::zero();

        foreach ($porMaterial as $m) {
            $mat = $m['mat'];
            $tipo = $mat['tipo'] ?? '';
            $contenido = Dec::de((string) ($mat['contenido'] ?? '0'));
            $piezas = $m['piezas']->multipliedBy($muebles);
            $neto = $m['medida']->multipliedBy($muebles);
            $conMerma = $neto->multipliedBy(BigDecimal::one()->plus($merma));
            $kg = null;

            // Cuántas presentaciones pedir (hojas, tramos, rollos, paquetes)
            $base = match ($tipo) {
                'AREA_KG' => $conMerma->multipliedBy(Dec::de((string) ($mat['kg_m2'] ?? '0'))),   // kg
                'PIEZA' => $piezas,
                default => $conMerma,
            };
            if ($tipo === 'AREA_KG') {
                $kg = $neto->multipliedBy(Dec::de((string) ($mat['kg_m2'] ?? '0')));
                $kgLamina = $kgLamina->plus($kg);
            }
            $presentaciones = $contenido->isPositive() ? Dec::techo(Dec::div($base, $contenido)) : null;

            $filas[] = [
                'clave' => $mat['clave'],
                'descripcion' => $mat['descripcion'],
                'piezas' => self::bonito($piezas),
                'neto' => match ($tipo) {
                    'LINEAL' => self::bonito($neto, 2).' m',
                    'AREA', 'AREA_KG' => self::bonito($neto, 2).' m²',
                    'PESO' => self::bonito($neto, 2).' kg',
                    default => '—',
                },
                'con_merma' => in_array($tipo, ['LINEAL', 'AREA', 'AREA_KG'], true)
                    ? self::bonito($conMerma, 2).($tipo === 'LINEAL' ? ' m' : ' m²') : '—',
                'pedir' => $presentaciones !== null && ! $presentaciones->isZero()
                    ? '≈ '.self::bonito($presentaciones).' × '.($mat['presentacion'] ?? 'pieza') : '—',
                'peso' => $kg ? self::bonito($kg, 1).' kg' : '—',
            ];
        }

        return [$filas, $kgLamina];
    }

    /** "1800.0000" → "1,800" · "0.2500" → "0.25" */
    public static function bonito(BigDecimal $v, int $decimales = 4): string
    {
        $t = Dec::d4($v->toScale($decimales, RoundingMode::HalfUp));
        [$entero, $frac] = array_pad(explode('.', $t), 2, null);
        $signo = str_starts_with($entero, '-') ? '-' : '';
        $entero = number_format((int) ltrim($entero, '-'), 0, '.', ',');

        return $signo.$entero.($frac !== null ? '.'.$frac : '');
    }

    /** Prefijo del código de pieza: "2-1-0048-0017" → "0048-0017"; si la clave no es de SULA, "P" + id. */
    public static function prefijo(string $clave, int $planoId): string
    {
        return preg_match('/\d+-\d+-(\d{3,5})-(\d{3,5})/', $clave, $m) ? $m[1].'-'.$m[2] : 'P'.$planoId;
    }

    private static function num(mixed $v): string
    {
        return Normalizador::numero(is_scalar($v) ? (string) $v : '') ?? '0';
    }
}
