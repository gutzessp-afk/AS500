<?php

namespace App\Cotizaciones;

use App\Costeo\Motor;
use App\Models\Cotizacion;
use App\Models\CotizacionAjuste;
use App\Models\CotizacionPieza;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Estados de una cotización:  BORRADOR → ENVIADA → APROBADA / RECHAZADA
 * Al ENVIAR se congela la foto. Para cambiar una enviada se abre una nueva revisión.
 */
class Flujo
{
    /** Calcula con los precios de HOY y guarda la foto. Desde aquí ya no cambia. */
    public static function enviar(Cotizacion $c): void
    {
        DB::transaction(function () use ($c) {
            $c->refresh();
            if (! $c->esBorrador()) {
                throw new ErrorCotizacion('Solo se puede enviar una cotización en BORRADOR.');
            }
            if ($c->piezas()->count() === 0) {
                throw new ErrorCotizacion('No se puede enviar una cotización sin despiece.');
            }

            [$entrada] = ArmadorEntrada::enVivo($c);
            try {
                $resultado = Motor::calcular($entrada);
            } catch (InvalidArgumentException $e) {
                throw new ErrorCotizacion($e->getMessage());
            }

            foreach ($resultado['compras'] as $compra) {
                if ((float) $compra['precio_unitario'] === 0.0) {
                    throw new ErrorCotizacion("{$compra['clave']} no tiene precio: regístralo antes de enviar.");
                }
            }
            foreach ($resultado['piezas'] as $p) {
                if ($p['avisos']) {
                    throw new ErrorCotizacion("La pieza \"{$p['descripcion']}\" tiene datos incompletos: ".implode(' ', $p['avisos']));
                }
            }

            $c->versiones()->create([
                'revision' => $c->revision,
                'entrada' => $entrada->aArreglo(),
                'resultado' => $resultado,
                'precio_venta' => $resultado['resumen']['precio_venta'],
                'total_pedido' => $resultado['resumen']['total_pedido'],
                'creado_por' => config('sula.usuario'),
            ]);
            $c->update(['estado' => 'ENVIADA', 'enviada_en' => now(), 'decidida_en' => null]);
        });
    }

    /** APROBADA o RECHAZADA (solo si está ENVIADA). */
    public static function decidir(Cotizacion $c, string $estado): void
    {
        if (! in_array($estado, ['APROBADA', 'RECHAZADA'], true)) {
            throw new ErrorCotizacion('Estado inválido: APROBADA o RECHAZADA.');
        }
        if ($c->estado !== 'ENVIADA') {
            throw new ErrorCotizacion('Solo una cotización ENVIADA se puede aprobar o rechazar.');
        }
        $c->update(['estado' => $estado, 'decidida_en' => now()]);
    }

    /** Regresa a BORRADOR como R+1. La foto anterior se queda para comparar. */
    public static function abrirRevision(Cotizacion $c): void
    {
        if ($c->esBorrador()) {
            throw new ErrorCotizacion('La cotización ya está en BORRADOR.');
        }
        $c->update([
            'estado' => 'BORRADOR',
            'revision' => $c->revision + 1,
            'enviada_en' => null,
            'decidida_en' => null,
        ]);
    }

    /** Copia encabezado, despiece y ajustes en un borrador nuevo. */
    public static function duplicar(Cotizacion $origen): Cotizacion
    {
        return DB::transaction(function () use ($origen) {
            $nueva = Cotizacion::create($origen->only([
                'cliente_id', 'plano_codigo', 'descripcion', 'plano_rev', 'dibujo', 'cantidad',
                'frente_m', 'pintura_material_id', 'vigencia_dias', 'notas',
            ]));
            foreach ($origen->piezas as $p) {
                $nueva->piezas()->create($p->only([
                    'orden', 'subensamble', 'descripcion', 'material_id', 'cantidad',
                    'largo_mm', 'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'notas',
                ]));
            }
            $ajustes = $origen->ajustes->map(fn ($a) => [
                'cotizacion_id' => $nueva->id, 'material_id' => $a->material_id, 'plan_corte' => $a->plan_corte,
                'merma_pct' => $a->merma_pct, 'modo_carga' => $a->modo_carga, 'nota' => $a->nota,
            ])->all();
            if ($ajustes) {
                CotizacionAjuste::insert($ajustes);
            }

            return $nueva->refresh();
        });
    }

    /**
     * Reemplaza el despiece completo (solo BORRADOR).
     *
     * @param  array<int, array>  $filas
     */
    public static function guardarPiezas(Cotizacion $c, array $filas): void
    {
        if (! $c->esBorrador()) {
            throw new ErrorCotizacion('La cotización no está en BORRADOR: abre una nueva revisión para modificarla.');
        }
        DB::transaction(function () use ($c, $filas) {
            CotizacionPieza::where('cotizacion_id', $c->id)->delete();
            foreach (array_values($filas) as $i => $f) {
                $c->piezas()->create([
                    'orden' => $i + 1,
                    'subensamble' => trim((string) ($f['subensamble'] ?? '')) ?: null,
                    'descripcion' => trim($f['descripcion']),
                    'material_id' => (int) $f['material_id'],
                    'cantidad' => $f['cantidad'],
                    'largo_mm' => $f['largo_mm'] ?: 0,
                    'ancho_mm' => $f['ancho_mm'] ?: 0,
                    'perimetro_pintable_mm' => $f['perimetro_pintable_mm'] ?: 0,
                    'caras_pintables' => $f['caras_pintables'] ?: 0,
                    'notas' => trim((string) ($f['notas'] ?? '')) ?: null,
                ]);
            }
            $c->touch();
        });
    }

    /** Plan de corte, merma y modo de carga de un material (solo BORRADOR). */
    public static function guardarAjuste(Cotizacion $c, int $materialId, ?string $planCorte, string $mermaPct, ?string $modo, ?string $nota): void
    {
        if (! $c->esBorrador()) {
            throw new ErrorCotizacion('La cotización no está en BORRADOR: abre una nueva revisión para modificarla.');
        }
        $merma = (float) $mermaPct;
        if ($merma < 0 || $merma >= 5) {
            throw new ErrorCotizacion('La merma va de 0 a 5 (ej. 0.10 = 10%).');
        }
        if ($modo !== null && ! in_array($modo, ['CONSUMIDO', 'COMPLETA'], true)) {
            throw new ErrorCotizacion('Modo de carga inválido: CONSUMIDO o COMPLETA.');
        }
        CotizacionAjuste::upsert([[
            'cotizacion_id' => $c->id,
            'material_id' => $materialId,
            'plan_corte' => ($planCorte !== null && (float) $planCorte > 0) ? $planCorte : null,
            'merma_pct' => $mermaPct,
            'modo_carga' => $modo,
            'nota' => $nota ?: null,
        ]], ['cotizacion_id', 'material_id'], ['plan_corte', 'merma_pct', 'modo_carga', 'nota']);
        $c->touch();
    }

    public static function quitarAjuste(Cotizacion $c, int $materialId): void
    {
        if (! $c->esBorrador()) {
            throw new ErrorCotizacion('La cotización no está en BORRADOR.');
        }
        CotizacionAjuste::where('cotizacion_id', $c->id)->where('material_id', $materialId)->delete();
        $c->touch();
    }
}
