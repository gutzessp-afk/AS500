<?php

namespace App\Planos\Fuentes;

/** Una carpeta en la fuente (Drive o local). */
final readonly class CarpetaOrigen
{
    public function __construct(
        public string $id,        // id de Drive o ruta relativa
        public string $nombre,    // "VITRINAS"
        public ?string $padreId,  // id de la carpeta padre (null = raíz)
        public string $ruta,      // "VENTO/VITRINAS"
    ) {}
}
