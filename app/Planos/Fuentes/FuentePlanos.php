<?php

namespace App\Planos\Fuentes;

/**
 * "Contrato" que cumple cualquier lugar de donde salen los planos.
 * Hoy hay dos: Google Drive (carpeta PLANOS) y una carpeta local.
 * El resto del sistema no sabe ni le importa de cuál viene el PDF.
 *
 * A propósito NO hay borrar, renombrar ni mover: desde el sistema
 * solo se sube y se crean carpetas (decisión de SULA).
 */
interface FuentePlanos
{
    /** 'DRIVE' o 'LOCAL' (se guarda en planos.origen) */
    public function origen(): string;

    /**
     * Todo lo que hay, recorriendo subcarpetas. Las carpetas vienen
     * ordenadas de arriba hacia abajo (el padre siempre antes que sus hijas).
     * @return array{carpetas: CarpetaOrigen[], archivos: ArchivoPlano[]}
     */
    public function explorar(): array;

    /** Los bytes del PDF. */
    public function descargar(string $id): string;

    /** Crea una carpeta dentro de otra (null = en la raíz). */
    public function crearCarpeta(string $nombre, ?string $padreId, string $rutaPadre = ''): CarpetaOrigen;

    /** Sube un PDF a una carpeta (null = en la raíz). */
    public function subir(string $nombre, string $bytes, ?string $carpetaId, string $rutaCarpeta = ''): ArchivoPlano;
}
