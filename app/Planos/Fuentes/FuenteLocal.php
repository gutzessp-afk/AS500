<?php

namespace App\Planos\Fuentes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Planos desde una carpeta de tu máquina (PLANOS_CARPETA_LOCAL).
 * Sirve para probar sin Drive. Los "ids" son rutas relativas: "VENTO/VITRINAS/plano.pdf".
 */
class FuenteLocal implements FuentePlanos
{
    public function __construct(private string $raiz)
    {
        $this->raiz = rtrim($raiz, '/');
    }

    public function origen(): string
    {
        return 'LOCAL';
    }

    public function explorar(): array
    {
        if (! is_dir($this->raiz)) {
            throw new RuntimeException("La carpeta de planos no existe: {$this->raiz}");
        }

        $carpetas = [];
        $archivos = [];
        // SELF_FIRST: primero la carpeta y luego lo que tiene adentro (el padre antes que los hijos)
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->raiz, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $f) {
            $relativa = ltrim(substr($f->getPathname(), strlen($this->raiz)), '/');
            $padre = dirname($relativa);
            $padre = $padre === '.' ? null : $padre;

            if ($f->isDir()) {
                $carpetas[] = new CarpetaOrigen($relativa, $f->getFilename(), $padre, $relativa);
            } elseif (strtolower($f->getExtension()) === 'pdf') {
                $archivos[] = new ArchivoPlano(
                    id: $relativa,
                    nombre: $f->getFilename(),
                    carpetaId: $padre,
                    carpeta: $padre,
                    tamano: $f->getSize(),
                    huella: $f->getMTime().'-'.$f->getSize(),
                    modificado: date(DATE_ATOM, $f->getMTime()),
                );
            }
        }
        // Ordena carpetas por profundidad: el padre siempre antes que sus hijas
        usort($carpetas, fn ($a, $b) => substr_count($a->id, '/') <=> substr_count($b->id, '/') ?: strcmp($a->id, $b->id));

        return ['carpetas' => $carpetas, 'archivos' => $archivos];
    }

    public function descargar(string $id): string
    {
        return file_get_contents($this->dentro($id, debeExistir: true));
    }

    public function crearCarpeta(string $nombre, ?string $padreId, string $rutaPadre = ''): CarpetaOrigen
    {
        $relativa = ltrim(($padreId ? $padreId.'/' : '').$nombre, '/');
        $ruta = $this->dentro($relativa, debeExistir: false);
        if (file_exists($ruta)) {
            throw new RuntimeException("Ya existe una carpeta llamada «{$nombre}» aquí.");
        }
        mkdir($ruta, 0775, true);

        return new CarpetaOrigen($relativa, $nombre, $padreId, $relativa);
    }

    public function subir(string $nombre, string $bytes, ?string $carpetaId, string $rutaCarpeta = ''): ArchivoPlano
    {
        $relativa = ltrim(($carpetaId ? $carpetaId.'/' : '').$nombre, '/');
        $ruta = $this->dentro($relativa, debeExistir: false);
        if (file_exists($ruta)) {
            throw new RuntimeException("Ya existe «{$nombre}» en esta carpeta.");
        }
        file_put_contents($ruta, $bytes);
        clearstatcache(true, $ruta);

        return new ArchivoPlano($relativa, $nombre, $carpetaId, $carpetaId, filesize($ruta),
            filemtime($ruta).'-'.filesize($ruta), date(DATE_ATOM, filemtime($ruta)));
    }

    /** Ruta absoluta segura: nunca deja salir de la carpeta raíz con "../". */
    private function dentro(string $relativa, bool $debeExistir): string
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $relativa) || str_contains($relativa, "\0")) {
            throw new RuntimeException('Ruta no permitida.');
        }
        $raiz = realpath($this->raiz);
        if ($raiz === false) {
            throw new RuntimeException("La carpeta de planos no existe: {$this->raiz}");
        }
        $ruta = $raiz.'/'.$relativa;
        if ($debeExistir) {
            $real = realpath($ruta);
            if ($real === false || ! str_starts_with($real, $raiz.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("El plano no existe en la carpeta local: $relativa");
            }

            return $real;
        }

        return $ruta;
    }
}
