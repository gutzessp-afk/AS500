<?php

namespace App\Http\Controllers;

use App\Models\Plano;
use App\Planos\AlmacenPdf;
use Throwable;

/**
 * GET /planos/{plano}/pdf            → muestra el PDF en el navegador
 * GET /planos/{plano}/pdf?descargar=1 → lo descarga
 * Solo sirve planos que están en la tabla (nadie puede pedir cualquier archivo de Drive).
 */
class PdfPlanoController
{
    public function __invoke(Plano $plano)
    {
        try {
            $ruta = AlmacenPdf::ruta($plano);
        } catch (Throwable $e) {
            abort(502, 'No se pudo traer el plano: '.$e->getMessage());
        }

        $nombre = str_replace('"', '', $plano->nombre);
        $modo = request()->boolean('descargar') ? 'attachment' : 'inline';

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$modo; filename=\"$nombre\"",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
