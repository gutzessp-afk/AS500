<?php

namespace Tests\Unit;

use App\Planos\RutaProduccion;
use PHPUnit\Framework\TestCase;

/** La ruta de producción se deduce del material y de las notas del plano. */
class RutaProduccionTest extends TestCase
{
    private const TUBO = ['categoria' => 'METAL', 'tipo' => 'LINEAL'];

    private const LAMINA = ['categoria' => 'METAL', 'tipo' => 'AREA_KG'];

    private const MELAMINA = ['categoria' => 'MADERA', 'tipo' => 'AREA'];

    public function test_tubo_soldado_y_pintado(): void
    {
        $r = RutaProduccion::ruta(['descripcion' => 'VERTICAL', 'perimetro_pintable_mm' => '101.6'], self::TUBO, 3);
        $this->assertSame(['TUB', 'SOL', 'PIN', 'ENS'], $r);
        $this->assertSame('Tubo › Soldadura › Pintura › Ensamble', RutaProduccion::texto($r));
    }

    public function test_lamina_con_doblez_y_barrenos_va_a_laser(): void
    {
        $r = RutaProduccion::ruta(['descripcion' => 'FORRO LATERAL', 'notas' => 'Doblez a 90°, 2 barrenos', 'largo_mm' => '800',
            'ancho_mm' => '500', 'caras_pintables' => '2'], self::LAMINA, 1);
        $this->assertSame(['LAS', 'DOB', 'PIN', 'ENS'], $r);
    }

    public function test_lamina_grande_lisa_va_a_cizalla(): void
    {
        $r = RutaProduccion::ruta(['descripcion' => 'PISO', 'largo_mm' => '1200', 'ancho_mm' => '600'], self::LAMINA, 1);
        $this->assertSame(['LAM', 'ENS'], $r);
    }

    public function test_melamina_canto_y_maquinado(): void
    {
        $this->assertSame(['SEC', 'CAN', 'MAQ', 'ENS'], RutaProduccion::ruta(['descripcion' => 'PUERTA', 'notas' => 'Cazoleta 35 mm'], self::MELAMINA));
        $this->assertSame(['SEC', 'ENS'], RutaProduccion::ruta(['descripcion' => 'RESPALDO', 'notas' => 'sin canto'], self::MELAMINA));
    }

    public function test_herrajes_y_sin_material(): void
    {
        $this->assertSame(['ALM', 'ENS'], RutaProduccion::ruta(['descripcion' => 'BISAGRA'], ['categoria' => 'HERRAJES', 'tipo' => 'PIEZA']));
        $this->assertSame([], RutaProduccion::ruta(['descripcion' => 'X'], null));
        $this->assertSame('Por definir', RutaProduccion::texto([]));
    }
}
