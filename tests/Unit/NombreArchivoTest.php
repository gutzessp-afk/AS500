<?php

namespace Tests\Unit;

use App\Planos\NombreArchivo;
use PHPUnit\Framework\TestCase;

/** La clave y la revisión salen del nombre del PDF. */
class NombreArchivoTest extends TestCase
{
    public function test_clave_sula_en_el_nombre(): void
    {
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('2-1-0072-0003 VITRINA PARA REFACCIONES REV-01.pdf'));
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('VENTO_2-1-0072-0003.pdf'));
    }

    public function test_sin_clave_usa_el_nombre_limpio(): void
    {
        $this->assertSame('SULA - MODULO EXHIBIDOR PL - FT', NombreArchivo::clave('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
        $this->assertSame('GONDOLA CENTRAL', NombreArchivo::clave('gondola_central REV-02.pdf'));
    }

    public function test_revision(): void
    {
        $this->assertSame('REV-01', NombreArchivo::revision('2-1-0072-0003 VITRINA REV-01.pdf'));
        $this->assertSame('REV-03', NombreArchivo::revision('mesa rev 3.pdf'));
        $this->assertSame('REV-00', NombreArchivo::revision('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
    }

    public function test_patron_propio(): void
    {
        $this->assertSame('KFC-0012', NombreArchivo::clave('Mostrador KFC-0012.pdf', '/KFC-\d{4}/'));
    }

    public function test_limpia_nombres_de_carpeta(): void
    {
        $this->assertSame('VENTO Vitrinas 2026', NombreArchivo::limpiarCarpeta('  VENTO / Vitrinas: 2026?  '));
        $this->assertSame('a b c', NombreArchivo::limpiarCarpeta('a\\b/c'));
        $this->assertSame('', NombreArchivo::limpiarCarpeta(' ../ '));
    }

    public function test_limpia_nombres_de_pdf(): void
    {
        $this->assertSame('2-1-0072 VITRINA.pdf', NombreArchivo::limpiarPdf('C:\\Users\\x\\2-1-0072 VITRINA.PDF'));
        $this->assertSame('mesa.pdf', NombreArchivo::limpiarPdf('mesa'));
    }
}
