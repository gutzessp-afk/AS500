<?php

namespace Tests\Unit;

use App\Planos\Fuentes\FuenteLocal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** La fuente local se prueba con una carpeta temporal de verdad (sin Drive). */
class FuenteLocalTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir().'/sula-planos-'.uniqid();
        mkdir($this->raiz.'/VENTO/VITRINAS', 0775, true);
        file_put_contents($this->raiz.'/VENTO/VITRINAS/2-1-0072-0003 VITRINA.pdf', '%PDF-1.4 prueba');
        file_put_contents($this->raiz.'/raiz.pdf', '%PDF-1.4 prueba');
        file_put_contents($this->raiz.'/VENTO/notas.txt', 'no es pdf');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->raiz));
    }

    public function test_explora_carpetas_en_orden_y_solo_pdfs(): void
    {
        $r = (new FuenteLocal($this->raiz))->explorar();

        $this->assertSame(['VENTO', 'VENTO/VITRINAS'], array_map(fn ($c) => $c->id, $r['carpetas'])); // padre antes que hija
        $this->assertSame('VENTO', $r['carpetas'][1]->padreId);
        $this->assertCount(2, $r['archivos']);
        $porId = [];
        foreach ($r['archivos'] as $a) {
            $porId[$a->id] = $a;
        }
        $this->assertSame('VENTO/VITRINAS', $porId['VENTO/VITRINAS/2-1-0072-0003 VITRINA.pdf']->carpetaId);
        $this->assertSame(null, $porId['raiz.pdf']->carpetaId);
    }

    public function test_crea_carpeta_y_sube_pdf(): void
    {
        $f = new FuenteLocal($this->raiz);
        $c = $f->crearCarpeta('KFC', 'VENTO');
        $this->assertSame('VENTO/KFC', $c->id);
        $this->assertSame('VENTO', $c->padreId);

        $a = $f->subir('mostrador.pdf', '%PDF-1.4 hola', $c->id);
        $this->assertSame('VENTO/KFC/mostrador.pdf', $a->id);
        $this->assertSame('%PDF-1.4 hola', $f->descargar($a->id));
    }

    public function test_no_sobrescribe(): void
    {
        $this->expectException(RuntimeException::class);
        (new FuenteLocal($this->raiz))->subir('raiz.pdf', '%PDF otra', null);
    }

    public function test_no_deja_salir_de_la_carpeta(): void
    {
        $this->expectException(RuntimeException::class);
        (new FuenteLocal($this->raiz))->descargar('../../etc/passwd');
    }
}
