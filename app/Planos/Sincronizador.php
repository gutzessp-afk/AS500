<?php

namespace App\Planos;

use App\Models\Carpeta;
use App\Models\Configuracion;
use App\Models\Plano;
use App\Planos\Fuentes\ArchivoPlano;
use App\Planos\Fuentes\CarpetaOrigen;
use App\Planos\Fuentes\FuentePlanos;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día las tablas "carpetas" y "planos" con lo que hay en la fuente (Drive o local).
 *   - Nuevos → se agregan.  - Cambiados (nombre, huella, carpeta) → se actualizan.
 *   - Los que ya no están → activo = false (no se borran: pueden tener despiece).
 * Los que no cambiaron NO se tocan, para no llenar el historial de cambios.
 */
class Sincronizador
{
    /** @return array{nuevos: int, actualizados: int, retirados: int, total: int, carpetas: int} */
    public static function sincronizar(FuentePlanos $fuente): array
    {
        $todo = $fuente->explorar();
        $origen = $fuente->origen();
        $nuevos = $actualizados = 0;

        DB::transaction(function () use ($todo, $origen, &$nuevos, &$actualizados) {
            // 1. Carpetas (vienen con el padre antes que las hijas)
            $mapa = [];   // id en el origen → id en la base de datos
            $vistas = [];
            foreach ($todo['carpetas'] as $c) {
                $mapa[$c->id] = self::guardarCarpeta($origen, $c, $mapa)->id;
                $vistas[] = $c->id;
            }
            Carpeta::where('origen', $origen)->where('activo', true)
                ->whereNotIn('archivo_id', $vistas ?: [''])->update(['activo' => false]);

            // 2. Planos
            $existentes = Plano::where('origen', $origen)->get()->keyBy('archivo_id');
            foreach ($todo['archivos'] as $a) {
                $plano = $existentes[$a->id] ?? null;
                $datos = self::datosPlano($a, $mapa);
                if (! $plano) {
                    Plano::create($datos + ['origen' => $origen, 'archivo_id' => $a->id]);
                    $nuevos++;
                } elseif (self::cambio($plano, $datos)) {
                    $plano->update($datos);
                    $actualizados++;
                }
            }
        });

        $ids = array_map(fn ($a) => $a->id, $todo['archivos']);
        $retirados = Plano::where('origen', $origen)->where('activo', true)
            ->whereNotIn('archivo_id', $ids ?: [''])
            ->update(['activo' => false]);

        Configuracion::updateOrCreate(['clave' => 'planos_ultima_sync'],
            ['valor' => now()->toIso8601String(), 'descripcion' => 'Última sincronización de planos']);

        return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'retirados' => $retirados,
            'total' => count($todo['archivos']), 'carpetas' => count($todo['carpetas'])];
    }

    /** Registra una carpeta recién creada desde el sistema (sin volver a leer todo Drive). */
    public static function registrarCarpeta(string $origen, CarpetaOrigen $c, ?Carpeta $padre): Carpeta
    {
        return Carpeta::create([
            'origen' => $origen,
            'archivo_id' => $c->id,
            'nombre' => $c->nombre,
            'padre_id' => $padre?->id,
            'ruta' => $c->ruta,
            'creada_en_sistema' => true,
        ]);
    }

    /** Registra un PDF recién subido desde el sistema. */
    public static function registrarPlano(string $origen, ArchivoPlano $a, ?Carpeta $carpeta): Plano
    {
        return Plano::create(self::datosPlano($a, []) + [
            'origen' => $origen,
            'archivo_id' => $a->id,
            'carpeta_id' => $carpeta?->id,
            'subido_en_sistema' => true,
        ]);
    }

    private static function guardarCarpeta(string $origen, CarpetaOrigen $c, array $mapa): Carpeta
    {
        $datos = [
            'nombre' => $c->nombre,
            'padre_id' => $c->padreId !== null ? ($mapa[$c->padreId] ?? null) : null,
            'ruta' => $c->ruta,
            'activo' => true,
        ];
        $carpeta = Carpeta::firstOrNew(['origen' => $origen, 'archivo_id' => $c->id]);
        if (! $carpeta->exists || self::cambio($carpeta, $datos)) {
            $carpeta->fill($datos + ['sincronizado_en' => now()])->save();
        }

        return $carpeta;
    }

    private static function datosPlano(ArchivoPlano $a, array $mapa): array
    {
        $patron = config('sula.planos.patron_clave') ?: NombreArchivo::PATRON_CLAVE;
        $datos = [
            'nombre' => $a->nombre,
            'clave' => NombreArchivo::clave($a->nombre, $patron),
            'revision' => NombreArchivo::revision($a->nombre),
            'carpeta' => $a->carpeta,
            'tamano_bytes' => $a->tamano,
            'huella' => $a->huella,
            'modificado_en_origen' => $a->modificado,
            'activo' => true,
            'sincronizado_en' => now(),
        ];
        if ($mapa !== []) {
            $datos['carpeta_id'] = $a->carpetaId !== null ? ($mapa[$a->carpetaId] ?? null) : null;
        }

        return $datos;
    }

    /** ¿Cambió algo importante? (se ignoran las fechas de sincronización) */
    private static function cambio($modelo, array $datos): bool
    {
        foreach ($datos as $k => $v) {
            if (in_array($k, ['sincronizado_en', 'modificado_en_origen', 'tamano_bytes'], true)) {
                continue;
            }
            $actual = $modelo->getAttribute($k);
            if ((string) $actual !== (string) ($v === true ? '1' : ($v === false ? '' : $v))) {
                return true;
            }
        }

        return false;
    }
}
