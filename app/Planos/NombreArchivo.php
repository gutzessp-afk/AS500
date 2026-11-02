<?php

namespace App\Planos;

/**
 * Saca la clave y la revisión del NOMBRE del PDF.
 *   "2-1-0072-0003 VITRINA PARA REFACCIONES REV-01.pdf" → clave 2-1-0072-0003, REV-01
 *   "SULA - MODULO EXHIBIDOR PL - FT.pdf"               → clave "SULA - MODULO EXHIBIDOR PL - FT", REV-00
 * El patrón de la clave se puede cambiar en el .env (PLANOS_PATRON_CLAVE).
 */
final class NombreArchivo
{
    public const PATRON_CLAVE = '/\d{1,2}-\d{1,2}-\d{3,5}-\d{3,5}/';

    public static function clave(string $nombre, string $patron = self::PATRON_CLAVE): string
    {
        if (@preg_match($patron, $nombre, $m) && isset($m[0])) {
            return $m[0];
        }

        // Sin clave en el nombre: usamos el nombre limpio (sin .pdf ni "REV-xx")
        $limpio = preg_replace('/\.pdf$/i', '', $nombre);
        $limpio = preg_replace('/[\s_-]*\bREV[\s._-]*\d{1,3}\b/i', '', $limpio);
        $limpio = preg_replace('/[\s_]+/', ' ', $limpio);

        return mb_strtoupper(trim($limpio, " -_."));
    }

    public static function revision(string $nombre): string
    {
        if (preg_match('/\bREV[\s._-]*(\d{1,3})\b/i', $nombre, $m)) {
            return 'REV-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return 'REV-00';
    }

    /** Nombre de carpeta válido en Drive y en Linux: sin / \ : * ? " < > | y sin espacios de sobra. */
    public static function limpiarCarpeta(string $nombre): string
    {
        $n = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#u', ' ', $nombre);

        return trim(preg_replace('/\s+/u', ' ', $n), ' .');
    }

    /** Nombre de PDF subido: sin ruta, sin caracteres raros y siempre terminado en .pdf */
    public static function limpiarPdf(string $nombre): string
    {
        $n = self::limpiarCarpeta(basename(str_replace('\\', '/', $nombre)));
        $n = preg_replace('/\.pdf$/i', '', $n);

        return ($n === '' ? 'plano' : $n).'.pdf';
    }
}
