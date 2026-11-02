<?php

namespace App\Planos\Fuentes;

/** Lo que una fuente (Drive o carpeta local) dice de un PDF. */
final readonly class ArchivoPlano
{
    public function __construct(
        public string $id,          // id de Drive o ruta relativa
        public string $nombre,      // "2-1-0072-0003 VITRINA REV-01.pdf"
        public ?string $carpetaId,  // id de la carpeta donde vive (null = raíz)
        public ?string $carpeta,    // ruta legible: "VENTO/VITRINAS"
        public ?int $tamano,
        public ?string $huella,     // md5 (Drive) o fecha+tamaño (local)
        public ?string $modificado, // fecha ISO 8601
    ) {}
}
