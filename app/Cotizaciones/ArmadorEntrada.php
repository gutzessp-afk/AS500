<?php

namespace App\Cotizaciones;

use App\Costeo\Ajuste;
use App\Costeo\Dec;
use App\Costeo\Entrada;
use App\Costeo\Material;
use App\Costeo\Parametros;
use App\Costeo\Pieza;
use App\Models\Configuracion;
use App\Models\CosteoParametro;
use App\Models\Cotizacion;
use Illuminate\Support\Facades\DB;

/**
 * Junta todo lo que el motor necesita para una cotización:
 * - en vivo (borrador): precios de HOY, parámetros del cliente o de SULA
 * - congelada (enviada): la foto guardada al enviar
 */
class ArmadorEntrada
{
    /** @return array{0: Entrada, 1: string} [entrada, origen de parámetros: SULA o CLIENTE] */
    public static function enVivo(Cotizacion $c): array
    {
        // 1. Parámetros
        $p = CosteoParametro::efectivosPara($c->cliente_id);
        $parametros = new Parametros(
            Dec::de($p->mano_obra_pct),
            Dec::de($p->overhead_pct),
            (bool) $p->overhead_incluye_mo,
            Dec::de($p->margen_pct),
            Dec::de($p->rendimiento_pintura_m2kg),
            Dec::de(Configuracion::valor('iva', '0.16')),
            (int) $p->vigencia_precios_dias,
        );

        // 2. Piezas y los materiales que usan
        $piezas = [];
        $ids = [];
        foreach ($c->piezas as $pz) {
            $piezas[] = new Pieza(
                $pz->id, $pz->orden, (string) $pz->subensamble, $pz->descripcion, $pz->material_id,
                Dec::de($pz->cantidad), Dec::de($pz->largo_mm), Dec::de($pz->ancho_mm),
                Dec::de($pz->perimetro_pintable_mm), Dec::de($pz->caras_pintables), (string) $pz->notas,
            );
            $ids[$pz->material_id] = true;
        }
        if ($c->pintura_material_id) {
            $ids[$c->pintura_material_id] = true;
        }

        // 3. Ajustes de compra
        $ajustes = [];
        foreach ($c->ajustes as $a) {
            $ajustes[$a->material_id] = new Ajuste(
                $a->material_id,
                $a->plan_corte === null ? null : Dec::de($a->plan_corte),
                Dec::de($a->merma_pct),
                $a->modo_carga,
                (string) $a->nota,
            );
        }

        $entrada = new Entrada(
            cantidad: (int) $c->cantidad,
            frenteM: Dec::de($c->frente_m),
            pinturaMaterialId: $c->pintura_material_id,
            piezas: $piezas,
            materiales: self::materiales(array_keys($ids)),
            ajustes: $ajustes,
            parametros: $parametros,
        );

        return [$entrada, $p->cliente_id ? 'CLIENTE' : 'SULA'];
    }

    /**
     * La foto de una revisión enviada.
     *
     * @return array{0: Entrada, 1: array} [entrada, resultado guardado]
     */
    public static function congelada(Cotizacion $c, ?int $revision = null): array
    {
        $v = $c->versiones()->where('revision', $revision ?? $c->revision)->firstOrFail();

        return [Entrada::deArreglo($v->entrada), $v->resultado];
    }

    /**
     * Materiales con su precio vigente "crudo" (sin redondear) para que el
     * motor calcule con precisión completa.
     *
     * @param  int[]  $ids
     * @return array<int, Material>
     */
    public static function materiales(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $filas = DB::select("
            SELECT m.id, m.clave, m.descripcion, m.categoria, m.tipo_calculo, m.unidad, m.presentacion, m.modo_carga,
                   COALESCE(c.peso_hoja_4x8, m.contenido) AS contenido, c.kg_m2,
                   p.precio_publicado, p.precio_por, p.incluye_iva, p.tasa_iva, p.confianza, p.dias_antiguedad
            FROM materiales m
            LEFT JOIN calibres c         ON c.calibre = m.calibre
            LEFT JOIN v_precio_vigente p ON p.material_id = m.id
            WHERE m.id IN ($marcas)", array_values($ids));

        $lista = [];
        foreach ($filas as $f) {
            $tiene = $f->precio_publicado !== null;
            $lista[$f->id] = new Material(
                $f->id, $f->clave, $f->descripcion, $f->categoria, $f->tipo_calculo, $f->unidad,
                $f->presentacion, Dec::de($f->contenido), Dec::de($f->kg_m2), $f->modo_carga,
                $tiene, Dec::de($f->precio_publicado), $f->precio_por ?? 'PRESENTACION',
                (bool) $f->incluye_iva, Dec::de($f->tasa_iva ?? '0.16'), (string) $f->confianza,
                (int) $f->dias_antiguedad,
            );
        }

        return $lista;
    }
}
