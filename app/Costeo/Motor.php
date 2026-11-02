<?php

namespace App\Costeo;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Motor de costeo de SULA. Replica la plantilla de costeo de Excel:
 *
 *   DESPIECE → consumo por pieza (según el tipo de cálculo del material)
 *   COMPRAS  → neto → bruto (merma o plan de corte) → presentaciones → costo
 *   RESUMEN  → materiales + M.O. + overhead → costo → precio = costo ÷ (1 − margen)
 *
 * Es "puro": no toca la base de datos. Regresa un arreglo con textos
 * ("12317.68") para no perder centavos al pasar a la vista o a JSON.
 */
final class Motor
{
    /** Orden en que se muestran las categorías (igual que el Excel). */
    private const CATEGORIAS = ['METAL', 'MADERA', 'ACABADO', 'HERRAJES', 'CONSUMIBLES', 'EMPAQUE'];

    public static function calcular(Entrada $e): array
    {
        if ($e->cantidad < 1) {
            throw new InvalidArgumentException('La cantidad de muebles debe ser al menos 1.');
        }
        if ($e->parametros->margenPct->isGreaterThanOrEqualTo(1)) {
            throw new InvalidArgumentException('El margen debe ser menor a 100%.');
        }

        $cero = BigDecimal::zero();
        $uno = BigDecimal::one();
        $cantidad = BigDecimal::of($e->cantidad);
        $avisos = [];

        // ---- 1. DESPIECE: consumo y área pintable de cada pieza ----
        $piezas = [];
        $consumo = [];          // material_id => consumo por mueble
        $areaPintable = $cero;

        foreach ($e->piezas as $p) {
            $pc = self::calcularPieza($p, $e->materiales);
            $piezas[] = $pc['fila'];
            if (isset($e->materiales[$p->materialId])) {
                $consumo[$p->materialId] = ($consumo[$p->materialId] ?? $cero)->plus($pc['consumo']);
            }
            $areaPintable = $areaPintable->plus($pc['area']);
        }

        // ---- 2. PINTURA: área pintable ÷ rendimiento, se agrega sola ----
        if ($areaPintable->isPositive()) {
            $pid = $e->pinturaMaterialId;
            if ($pid === null) {
                $avisos[] = 'Hay '.Dec::d4($areaPintable).' m² pintables pero no se eligió acabado: la pintura no se está cobrando.';
            } elseif (! isset($e->materiales[$pid])) {
                $avisos[] = 'El acabado elegido no existe en el catálogo.';
            } elseif (! $e->parametros->rendimientoPintura->isPositive()) {
                $avisos[] = 'El rendimiento de pintura debe ser mayor a 0.';
            } else {
                $m = $e->materiales[$pid];
                $kg = Dec::div($areaPintable, $e->parametros->rendimientoPintura);
                $piezas[] = [
                    'orden' => count($e->piezas) + 1,
                    'subensamble' => 'ACABADO',
                    'descripcion' => $m->descripcion.' (área pintable ÷ '.Dec::d4($e->parametros->rendimientoPintura).' m²/kg)',
                    'material_id' => $m->id,
                    'clave' => $m->clave,
                    'unidad' => $m->unidad,
                    'cantidad' => Dec::d4($kg),
                    'consumo_unitario' => '1',
                    'consumo_total' => Dec::d4($kg),
                    'area_pintable_m2' => '0',
                    'costo' => Dec::dinero($kg->multipliedBy($m->precioUnitario())),
                    'avisos' => $m->tipoCalculo === 'PESO' ? [] : ['El acabado debería cobrarse por peso (kg).'],
                    'automatica' => true,
                ];
                $consumo[$m->id] = ($consumo[$m->id] ?? $cero)->plus($kg);
            }
        }

        // ---- 3. COMPRAS: de consumo a presentaciones y costo ----
        $ids = array_keys($consumo);
        usort($ids, fn ($a, $b) => strcmp($e->materiales[$a]->clave, $e->materiales[$b]->clave));

        $compras = [];
        $materiales = $cero;
        $porCategoria = [];
        $montoBaja = $cero;
        $montoVencido = $cero;
        $montoRiesgo = $cero;
        $motivos = [];
        $sinPrecio = 0;

        foreach ($ids as $id) {
            $m = $e->materiales[$id];
            [$fila, $costo] = self::calcularCompra($m, $consumo[$id], $e->ajustes[$id] ?? null, $cantidad, $e->parametros->vigenciaDias);
            $compras[] = $fila;

            $materiales = $materiales->plus($costo);
            $porCategoria[$m->categoria] = ($porCategoria[$m->categoria] ?? $cero)->plus($costo);

            $enRiesgo = false;
            if (! $m->tienePrecio) {
                $sinPrecio++;
                $motivos[] = "{$m->clave} no tiene precio: su costo cuenta como \$0.";
            }
            if ($m->confianza === 'BAJA') {
                $montoBaja = $montoBaja->plus($costo);
                $enRiesgo = true;
            }
            if ($fila['vencido']) {
                $montoVencido = $montoVencido->plus($costo);
                $enRiesgo = true;
                $motivos[] = "{$m->clave}: precio de hace {$m->diasAntiguedad} días (vigencia {$e->parametros->vigenciaDias}).";
            }
            if ($enRiesgo) {
                $montoRiesgo = $montoRiesgo->plus($costo);
            }
        }

        // ---- 4. RESUMEN: costo y precio por mueble ----
        $p = $e->parametros;
        $manoObra = $materiales->multipliedBy($p->manoObraPct);
        $base = $p->overheadIncluyeMo ? $materiales->plus($manoObra) : $materiales;
        $overhead = $base->multipliedBy($p->overheadPct);
        $costoTotal = $materiales->plus($manoObra)->plus($overhead);
        $precio = Dec::div($costoTotal, $uno->minus($p->margenPct));
        $iva = $precio->multipliedBy($p->tasaIva);
        $total = $precio->multipliedBy($cantidad);
        $porMetro = $e->frenteM->isPositive() ? Dec::div($precio, $e->frenteM) : $cero;

        $resumen = [
            'materiales' => Dec::dinero($materiales),
            'mano_obra' => Dec::dinero($manoObra),
            'overhead' => Dec::dinero($overhead),
            'costo_total' => Dec::dinero($costoTotal),
            'utilidad' => Dec::dinero($precio->minus($costoTotal)),
            'precio_venta' => Dec::dinero($precio),
            'iva' => Dec::dinero($iva),
            'precio_con_iva' => Dec::dinero($precio->plus($iva)),
            'cantidad' => $e->cantidad,
            'total_pedido' => Dec::dinero($total),
            'total_pedido_con_iva' => Dec::dinero($total->multipliedBy($uno->plus($p->tasaIva))),
            'precio_metro_lineal' => Dec::dinero($porMetro),
            'area_pintable_m2' => Dec::d4($areaPintable),
        ];

        // ---- 5. CATEGORÍAS ----
        $categorias = [];
        foreach (self::CATEGORIAS as $cat) {
            if (! isset($porCategoria[$cat])) {
                continue;
            }
            $categorias[] = [
                'categoria' => $cat,
                'costo' => Dec::dinero($porCategoria[$cat]),
                'pct_materiales' => Dec::pct($porCategoria[$cat], $materiales),
                'pct_precio' => Dec::pct($porCategoria[$cat], $precio),
            ];
        }

        // ---- 6. RIESGO: ¿qué tan confiable es este precio? ----
        $pctRiesgo = $materiales->isZero() ? $cero : Dec::div($montoRiesgo, $materiales);
        $hayAvisosPiezas = (bool) array_filter($piezas, fn ($x) => count($x['avisos']) > 0);
        $nivel = 'VERDE';
        if ($sinPrecio > 0 || $pctRiesgo->isGreaterThanOrEqualTo('0.15')) {
            $nivel = 'ROJO';
        } elseif ($pctRiesgo->isPositive() || $avisos || $hayAvisosPiezas) {
            $nivel = 'AMARILLO';
        }
        if ($montoBaja->isPositive()) {
            $motivos[] = '$'.Dec::dinero($montoBaja).' por mueble viene de precios estimados (confianza baja).';
        }
        if ($hayAvisosPiezas) {
            $motivos[] = 'Hay piezas con datos incompletos en el despiece.';
        }

        return [
            'piezas' => $piezas,
            'compras' => $compras,
            'categorias' => $categorias,
            'resumen' => $resumen,
            'riesgo' => [
                'nivel' => $nivel,
                'monto_confianza_baja' => Dec::dinero($montoBaja),
                'monto_vencido' => Dec::dinero($montoVencido),
                'monto_en_riesgo' => Dec::dinero($montoRiesgo),
                'pct_materiales' => Dec::d4($pctRiesgo),
                'motivos' => $motivos,
            ],
            'parametros' => $p->aArreglo(),
            'avisos' => $avisos,
        ];
    }

    /** Consumo por mueble de una pieza según el tipo de cálculo de su material. */
    private static function calcularPieza(Pieza $p, array $materiales): array
    {
        $cero = BigDecimal::zero();
        $fila = [
            'id' => $p->id, 'orden' => $p->orden, 'subensamble' => $p->subensamble,
            'descripcion' => $p->descripcion, 'material_id' => $p->materialId,
            'cantidad' => Dec::d4($p->cantidad), 'clave' => '', 'unidad' => '',
            'consumo_unitario' => '0', 'consumo_total' => '0', 'area_pintable_m2' => '0',
            'costo' => '0.00', 'avisos' => [], 'automatica' => false,
        ];

        $m = $materiales[$p->materialId] ?? null;
        if ($m === null) {
            $fila['avisos'][] = 'Material no encontrado en el catálogo.';
            return ['fila' => $fila, 'consumo' => $cero, 'area' => $cero];
        }
        $fila['clave'] = $m->clave;
        $fila['unidad'] = $m->unidad;

        $largo = Dec::div($p->largoMm, BigDecimal::of(1000));   // mm → m
        $ancho = Dec::div($p->anchoMm, BigDecimal::of(1000));
        $area = $cero;

        switch ($m->tipoCalculo) {
            case 'LINEAL':
                $unitario = $largo;
                $area = $p->cantidad->multipliedBy($largo)->multipliedBy(Dec::div($p->perimetroPintableMm, BigDecimal::of(1000)));
                if (! $largo->isPositive()) {
                    $fila['avisos'][] = 'Falta el largo (mm).';
                }
                break;
            case 'AREA':
                $unitario = $largo->multipliedBy($ancho);
                $area = $p->cantidad->multipliedBy($largo)->multipliedBy($ancho)->multipliedBy($p->carasPintables);
                if (! $largo->isPositive() || ! $ancho->isPositive()) {
                    $fila['avisos'][] = 'Faltan largo y ancho (mm).';
                }
                break;
            case 'AREA_KG':
                $unitario = $largo->multipliedBy($ancho)->multipliedBy($m->kgM2);
                $area = $p->cantidad->multipliedBy($largo)->multipliedBy($ancho)->multipliedBy($p->carasPintables);
                if (! $largo->isPositive() || ! $ancho->isPositive()) {
                    $fila['avisos'][] = 'Faltan largo y ancho (mm) de la lámina.';
                }
                break;
            default: // PESO y PIEZA: la cantidad ya es el consumo (kg o piezas)
                $unitario = BigDecimal::one();
        }

        if (! $p->cantidad->isPositive()) {
            $fila['avisos'][] = 'La cantidad debe ser mayor a 0.';
        }
        if (! $m->tienePrecio) {
            $fila['avisos'][] = 'El material no tiene precio.';
        }

        $total = $p->cantidad->multipliedBy($unitario);
        $fila['consumo_unitario'] = Dec::d4($unitario);
        $fila['consumo_total'] = Dec::d4($total);
        $fila['area_pintable_m2'] = Dec::d4($area);
        $fila['costo'] = Dec::dinero($total->multipliedBy($m->precioUnitario()));

        return ['fila' => $fila, 'consumo' => $total, 'area' => $area];
    }

    /**
     * Una fila de la hoja COMPRAS. Regresa [fila para mostrar, costo por mueble con precisión completa].
     */
    private static function calcularCompra(Material $m, BigDecimal $neto, ?Ajuste $a, BigDecimal $cantidad, int $vigencia): array
    {
        $cero = BigDecimal::zero();
        $modo = ($a?->modoCarga) ?: $m->modoCarga;
        $merma = $a?->mermaPct ?? $cero;
        $plan = ($a?->planCorte && $a->planCorte->isPositive()) ? $a->planCorte : null;

        // Bruto por mueble: el plan de corte manda sobre la merma
        $bruto = $plan !== null
            ? $plan->multipliedBy($m->contenido)
            : $neto->multipliedBy(BigDecimal::one()->plus($merma));

        $aprovechamiento = $bruto->isPositive() ? Dec::div($neto, $bruto) : $cero;

        // Presentaciones para TODO el pedido: REDONDEAR.MAS(REDONDEAR(x, 4), 0)
        $presentaciones = $m->contenido->isPositive()
            ? Dec::techo(Dec::div($bruto->multipliedBy($cantidad), $m->contenido)->toScale(4, RoundingMode::HalfUp))
            : $cero;

        $precioUnit = $m->precioUnitario();
        $precioPres = $m->precioPresentacion();

        // COMPLETA cobra presentaciones enteras repartidas; CONSUMIDO solo lo usado
        $costo = $modo === 'COMPLETA'
            ? Dec::div($presentaciones->multipliedBy($precioPres), $cantidad)
            : $bruto->multipliedBy($precioUnit);

        $sobrante = $presentaciones->multipliedBy($m->contenido)->minus($bruto->multipliedBy($cantidad));
        if ($sobrante->isNegative()) {
            $sobrante = $cero;
        }

        return [[
            'material_id' => $m->id,
            'clave' => $m->clave,
            'descripcion' => $m->descripcion,
            'categoria' => $m->categoria,
            'unidad' => $m->unidad,
            'consumo_neto' => Dec::d4($neto),
            'plan_corte' => $plan === null ? null : Dec::d4($plan),
            'merma_pct' => Dec::d4($merma),
            'consumo_bruto' => Dec::d4($bruto),
            'aprovechamiento' => Dec::d4($aprovechamiento),
            'presentacion' => $m->presentacion,
            'contenido' => Dec::d4($m->contenido),
            'presentaciones_comprar' => (string) $presentaciones,
            'modo_carga' => $modo,
            'precio_unitario' => Dec::d4($precioUnit),
            'precio_presentacion' => Dec::dinero($precioPres),
            'costo_por_mueble' => Dec::dinero($costo),
            'costo_pedido' => Dec::dinero($costo->multipliedBy($cantidad)),
            'sobrante' => Dec::d4($sobrante),
            'confianza' => $m->confianza,
            'dias_antiguedad' => $m->diasAntiguedad,
            'vencido' => $m->tienePrecio && $vigencia > 0 && $m->diasAntiguedad > $vigencia,
            'nota' => $a?->nota ?? '',
        ], $costo];
    }
}
