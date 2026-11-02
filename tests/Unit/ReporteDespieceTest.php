<?php

namespace Tests\Unit;

use App\Costeo\Dec;
use App\Planos\ReporteDespiece;
use PHPUnit\Framework\TestCase;

/** Los datos del PDF: explosión, a producir, REVISAR, hojas por área y material a comprar. */
class ReporteDespieceTest extends TestCase
{
    private const CATALOGO = [
        1 => ['clave' => 'M01', 'descripcion' => 'Tubo cuadrado 1"', 'tipo' => 'LINEAL', 'categoria' => 'METAL', 'presentacion' => 'Tramo 6 m', 'contenido' => '6', 'kg_m2' => '0'],
        2 => ['clave' => 'M02', 'descripcion' => 'Lámina cal 11', 'tipo' => 'AREA_KG', 'categoria' => 'METAL', 'presentacion' => 'Hoja 4×8', 'contenido' => '72.57', 'kg_m2' => '24.41'],
        5 => ['clave' => 'M05', 'descripcion' => 'Melamina 15 mm', 'tipo' => 'AREA', 'categoria' => 'MADERA', 'presentacion' => 'Hoja 1.22×2.44', 'contenido' => '2.9768', 'kg_m2' => '0'],
        9 => ['clave' => 'M09', 'descripcion' => 'Bisagra 35 mm', 'tipo' => 'PIEZA', 'categoria' => 'HERRAJES', 'presentacion' => 'Pieza', 'contenido' => '1', 'kg_m2' => '0'],
    ];

    private static function pieza(string $d, ?int $m, string $c, string $l = '0', string $a = '0', array $avisos = [], string $notas = ''): array
    {
        return ['descripcion' => $d, 'material_id' => $m, 'material_texto' => '', 'cantidad' => $c, 'largo_mm' => $l,
            'ancho_mm' => $a, 'perimetro_pintable_mm' => $m === 1 ? '101.6' : '0', 'caras_pintables' => '0', 'notas' => $notas, 'avisos' => $avisos];
    }

    private static function reporte(int $muebles = 10): array
    {
        return ReporteDespiece::armar([
            ['nombre' => 'POSTE', 'cantidad' => '2', 'pagina' => 2, 'piezas' => [
                self::pieza('VERTICAL', 1, '2', '1800'),
                self::pieza('ÁNGULO', 2, '3', '344', '37', [], 'Doblez a 90°'),
            ]],
            ['nombre' => 'GABINETE', 'cantidad' => '3', 'pagina' => null, 'piezas' => [
                self::pieza('COSTADO', 5, '2', '669', '333'),
                self::pieza('MANGÜETE', 5, '2', '602', '0', [['nivel' => 'error', 'texto' => 'Faltan medidas.'], ['nivel' => 'info', 'texto' => 'x']]),
            ]],
            ['nombre' => 'HERRAJE', 'cantidad' => '1', 'pagina' => null, 'piezas' => [
                self::pieza('BISAGRA', 9, '12'),
                self::pieza('PIJA RARA', null, '24'),
            ]],
        ], self::CATALOGO, ['cantidad' => $muebles, 'merma' => '0.10', 'prefijo' => '0048-0017']);
    }

    public function test_explosion_por_mueble_y_a_producir(): void
    {
        $r = self::reporte();
        $vertical = $r['subs'][0]['piezas'][0];
        $this->assertSame('4', $vertical['por_mueble']);          // 2 × 2 postes
        $this->assertSame('40', $vertical['a_producir']);         // × 10 muebles
        $this->assertSame('0048-0017-01-01', $vertical['codigo']);
        $this->assertSame('1,800', $vertical['largo']);
        $this->assertSame('20', $r['subs'][0]['a_producir']);     // 2 postes × 10
        $this->assertSame('580', $r['totales']['a_producir']);    // (4 + 6 + 6 + 6 + 12 + 24) × 10
    }

    public function test_marca_revisar_solo_lo_rojo_o_sin_material(): void
    {
        $r = self::reporte();
        $this->assertSame(false, $r['subs'][1]['piezas'][0]['revisar']);
        $this->assertSame(true, $r['subs'][1]['piezas'][1]['revisar']);
        $this->assertSame(['Faltan medidas.'], $r['subs'][1]['piezas'][1]['avisos']);
        $this->assertSame(true, $r['subs'][2]['piezas'][1]['revisar']);
        $this->assertSame(2, $r['totales']['por_revisar']);
    }

    public function test_hojas_por_area_en_orden_de_planta(): void
    {
        $r = self::reporte();
        $this->assertSame(['TUB', 'LAS', 'DOB', 'SOL', 'SEC', 'CAN', 'PIN', 'ALM'], array_column($r['areas'], 'codigo'));
        $doblez = $r['areas'][2];
        $this->assertSame(1, $doblez['renglones']);
        $this->assertSame('60', $doblez['total']);                // 3 ángulos × 2 postes × 10
    }

    public function test_material_a_comprar(): void
    {
        $porClave = array_column(self::reporte()['materiales'], null, 'clave');
        $this->assertSame('72 m', $porClave['M01']['neto']);                  // 1.8 m × 40
        $this->assertSame('≈ 14 × Tramo 6 m', $porClave['M01']['pedir']);    // 79.2 m ÷ 6 = 13.2 → 14
        $this->assertSame('≈ 5 × Hoja 1.22×2.44', $porClave['M05']['pedir']);  // 0.669×0.333×60 = 13.37 m² + 10% ÷ 2.9768 = 4.9 → 5
        $this->assertSame('≈ 120 × Pieza', $porClave['M09']['pedir']);
        $this->assertSame('18.6 kg', $porClave['M02']['peso']);               // 0.344×0.037×60 m² × 24.41
    }

    public function test_m2_a_pintar_y_hojas_agrupadas_por_material(): void
    {
        $r = self::reporte();
        $this->assertSame('7.32', $r['totales']['m2_pintura']);               // 0.1016 m × 1.8 m × 40

        $seccionadora = array_column($r['areas'], null, 'codigo')['SEC'];
        $this->assertSame('M05', $seccionadora['grupos'][0]['clave']);
        $this->assertSame('COSTADO', $seccionadora['grupos'][0]['filas'][0]['descripcion']);   // el más largo primero
        $this->assertSame('120', $seccionadora['grupos'][0]['total']);
    }

    public function test_no_incluye_subensambles_vacios(): void
    {
        $r = ReporteDespiece::armar([
            ['nombre' => 'VACIO', 'cantidad' => '1', 'piezas' => []],
            ['nombre' => 'BASE', 'cantidad' => '1', 'piezas' => [self::pieza('PISO', 5, '1', '500', '500')]],
        ], self::CATALOGO, ['prefijo' => 'P1']);

        $this->assertSame(1, $r['totales']['subensambles']);
        $this->assertSame('P1-01-01', $r['subs'][0]['piezas'][0]['codigo']);
    }

    public function test_formato_y_prefijo(): void
    {
        $this->assertSame('12,345.5', ReporteDespiece::bonito(Dec::de('12345.5000')));
        $this->assertSame('0048-0017', ReporteDespiece::prefijo('2-1-0048-0017', 7));
        $this->assertSame('P7', ReporteDespiece::prefijo('SULA - MODULO EXHIBIDOR', 7));
    }
}
