<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\Fuente;

/**
 * Copia de los PDFs DENTRO del AS500 (storage/app/private/planos, fuera de git y fuera de "public").
 *   - Al ver o leer un plano de Drive, se baja la primera vez y se reutiliza.
 *   - Al subir un PDF desde el sistema, se guarda aquí en ese momento (además de en Drive).
 * Si el archivo cambia en Drive (cambia su "huella"), se vuelve a bajar.
 */
class AlmacenPdf
{
    public static function ruta(Plano $plano): string
    {
        $ruta = self::rutaLocal($plano);
        if (is_file($ruta)) {
            return $ruta;
        }

        $bytes = Fuente::para($plano->origen)->descargar($plano->archivo_id);
        self::guardarCopia($plano, $bytes);

        return $ruta;
    }

    /** Guarda los bytes del PDF como la copia local del plano (borra copias viejas del mismo plano). */
    public static function guardarCopia(Plano $plano, string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('Lo que llegó no es un PDF.');
        }
        $ruta = self::rutaLocal($plano);
        $dir = dirname($ruta);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $prefijo = sha1($plano->archivo_id);
        foreach (glob("$dir/$prefijo-*.pdf") ?: [] as $vieja) {
            if ($vieja !== $ruta) {
                @unlink($vieja);
            }
        }
        file_put_contents($ruta, $bytes);

        return $ruta;
    }

    /** storage/app/private/planos/drive/<sha1 del id>-<huella>.pdf */
    private static function rutaLocal(Plano $plano): string
    {
        $dir = storage_path('app/private/planos/'.strtolower($plano->origen));

        return "$dir/".sha1($plano->archivo_id).'-'.substr(sha1((string) $plano->huella), 0, 12).'.pdf';
    }
}
