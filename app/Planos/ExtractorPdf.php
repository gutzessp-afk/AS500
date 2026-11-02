<?php

namespace App\Planos;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Saca texto e imágenes de las páginas de un PDF con Poppler
 * (pdfinfo, pdftotext, pdftoppm). En Arch: sudo pacman -S poppler
 *
 * Los planos de SolidWorks traen texto (tablas, materiales, cotas),
 * así que leer el texto es rápido y barato. Lo que solo está dibujado
 * se manda como imagen a la IA de visión.
 */
class ExtractorPdf
{
    public function __construct(private string $ruta) {}

    public function paginas(): int
    {
        $salida = $this->correr(['pdfinfo', $this->ruta]);
        if (! preg_match('/^Pages:\s+(\d+)/m', $salida, $m)) {
            throw new RuntimeException('No se pudo leer el número de páginas del PDF.');
        }

        return (int) $m[1];
    }

    /** Texto de una página respetando columnas (-layout), sin renglones vacíos repetidos. */
    public function texto(int $pagina): string
    {
        $t = $this->correr(['pdftotext', '-layout', '-f', $pagina, '-l', $pagina, $this->ruta, '-']);
        $t = preg_replace('/[ \t]+$/m', '', $t);

        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    /** Imagen JPEG de una página (lado mayor 1600 px por defecto), en base64 (para la IA o la miniatura del PDF). */
    public function imagenBase64(int $pagina, int $ladoMayor = 1600): string
    {
        $base = tempnam(sys_get_temp_dir(), 'plano');
        try {
            $this->correr(['pdftoppm', '-jpeg', '-jpegopt', 'quality=80', '-scale-to', $ladoMayor,
                '-f', $pagina, '-l', $pagina, '-singlefile', $this->ruta, $base]);

            return base64_encode(file_get_contents("$base.jpg"));
        } finally {
            @unlink($base);
            @unlink("$base.jpg");
        }
    }

    private function correr(array $comando): string
    {
        $r = Process::timeout(120)->run(array_map('strval', $comando));
        if ($r->failed()) {
            $err = trim($r->errorOutput());
            if (str_contains($err, 'not found') || $r->exitCode() === 127) {
                throw new RuntimeException("Falta Poppler ({$comando[0]}). Instálalo: sudo pacman -S poppler");
            }
            throw new RuntimeException("{$comando[0]} falló: $err");
        }

        return $r->output();
    }
}
