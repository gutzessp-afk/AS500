<?php

namespace Tests\Unit;

use App\Planos\Normalizador;
use App\Planos\Prompts;
use PHPUnit\Framework\TestCase;

/**
 * El Normalizador limpia lo que responde la IA. Se prueba con una respuesta
 * "de mentiras" parecida a la que daría Groq con el plano del Módulo Exhibidor.
 */
class NormalizadorTest extends TestCase
{
    /** Catálogo mínimo: id → tipo/categoría (como la semilla) */
    private const MATERIALES = [
        1 => ['tipo' => 'LINEAL', 'categoria' => 'METAL', 'descripcion' => 'Tubo cuadrado (PTR) 1" × 1" cal 16, tramo 6 m'],
        2 => ['tipo' => 'AREA_KG', 'categoria' => 'METAL', 'descripcion' => 'Lámina CRS cal 11'],
        5 => ['tipo' => 'AREA', 'categoria' => 'MADERA', 'descripcion' => 'Melamina roble 15 mm'],
        6 => ['tipo' => 'AREA', 'categoria' => 'MADERA', 'descripcion' => 'Melamina roble 28 mm'],
        9 => ['tipo' => 'PIEZA', 'categoria' => 'HERRAJES', 'descripcion' => 'Bisagra Ducasse 35 mm'],
    ];

    /** Claves y alias → id (como LectorPlano::catalogo()) */
    private const DICCIONARIO = [
        'M01' => 1, 'M02' => 2, 'M05' => 5, 'M06' => 6, 'M09' => 9,
        'T-CUADRADO 1" C.16' => 1,
        'LAMINA CRS C. 11' => 2,
        'MELAMINA ROBLE (SMA) 15MM' => 5,
        'MELAMINA ROBLE (SMA) 25MM' => 6,
    ];

    private static function respuestaIa(): array
    {
        return [
            'plano' => ['modelo' => 'S/M', 'descripcion' => 'MODULO EXHIBIDOR', 'cliente' => 'SULA', 'revision' => 'REV-00', 'acabado' => ''],
            'leyenda' => [
                ['codigo' => 'TC1', 'material' => 'T-CUADRADO 1" C.16'],
                ['codigo' => 'LM1', 'material' => 'LAMINA CRS C. 11'],
                ['codigo' => 'ML1', 'material' => 'MELAMINA ROBLE (SMA) 15mm'],
                ['codigo' => 'ML2', 'material' => 'MELAMINA ROBLE (SMA) 25mm'],
            ],
            'subensambles' => [
                ['nombre' => 'Poste final', 'cantidad' => 2, 'pagina' => 1, 'piezas' => [
                    ['descripcion' => 'Vertical', 'material' => 'TC1', 'cantidad' => 2, 'largo_mm' => '1,800', 'ancho_mm' => null, 'pintura' => null],
                    ['descripcion' => 'Ángulo de amarre', 'material' => 'LM1', 'cantidad' => '3', 'largo_mm' => 344, 'ancho_mm' => '37 mm', 'pintura' => null],
                ]],
                ['nombre' => 'GABINETE', 'cantidad' => 3, 'piezas' => [
                    ['descripcion' => 'Costado', 'material' => 'ML1', 'cantidad' => 2, 'largo_mm' => 669, 'ancho_mm' => null],
                ]],
                ['nombre' => 'ENSAMBLE GENERAL', 'cantidad' => 1, 'piezas' => [
                    ['descripcion' => 'Cubierta superior', 'material' => 'MELAMINA ROBLE 25mm', 'cantidad' => 1, 'largo_mm' => 2000, 'ancho_mm' => 350],
                ]],
            ],
            'herrajes' => [['descripcion' => 'BISAGRA RECTA CAZOLETA 35mm', 'cantidad' => 12]],
        ];
    }

    public function test_numeros_como_vienen_en_planos(): void
    {
        $this->assertSame('1800', Normalizador::numero('1,800'));
        $this->assertSame('1800', Normalizador::numero('1800 mm'));
        $this->assertSame('0.25', Normalizador::numero('0,25'));
        $this->assertSame('12.5', Normalizador::numero(12.5));
        $this->assertSame(null, Normalizador::numero('VARIOS'));
        $this->assertSame(null, Normalizador::numero(null));
        $this->assertSame('1800.5', Normalizador::numero('1,800.5'));
    }

    public function test_normaliza_leyenda_subensambles_y_herrajes(): void
    {
        $doc = Normalizador::normalizar(self::respuestaIa());

        $this->assertSame('MELAMINA ROBLE (SMA) 25MM', $doc['leyenda']['ML2']);
        $this->assertSame('POSTE FINAL', $doc['subensambles'][0]['nombre']);
        $this->assertSame('1800', $doc['subensambles'][0]['piezas'][0]['largo_mm']);
        $this->assertSame('37', $doc['subensambles'][0]['piezas'][1]['ancho_mm']);
        $this->assertSame('HERRAJE DE ARMADO', $doc['subensambles'][3]['nombre']);
        $this->assertSame('BISAGRA RECTA CAZOLETA 35MM', $doc['subensambles'][3]['piezas'][0]['material_texto']);
    }

    public function test_fusionar_completa_lo_que_falta_sin_pisar(): void
    {
        $a = Normalizador::normalizar(self::respuestaIa());
        // Pasada con dibujos: trae el ancho del costado (faltaba) y un largo distinto del vertical (no debe pisar)
        $b = Normalizador::normalizar(['subensambles' => [
            ['nombre' => 'gabinete', 'piezas' => [
                ['descripcion' => 'COSTADO', 'ancho_mm' => 333, 'notas' => 'veta vertical'],
                ['descripcion' => 'Puerta', 'material' => 'ML1', 'cantidad' => 2, 'largo_mm' => 665, 'ancho_mm' => 312],
            ]],
            ['nombre' => 'POSTE FINAL', 'piezas' => [['descripcion' => 'VERTICAL', 'largo_mm' => 1799]]],
        ]]);
        $doc = Normalizador::fusionar($a, $b);

        $gabinete = $doc['subensambles'][1];
        $this->assertCount(4, $doc['subensambles']);                       // no se duplicó GABINETE
        $this->assertSame('333', $gabinete['piezas'][0]['ancho_mm']);      // se completó
        $this->assertSame('veta vertical', $gabinete['piezas'][0]['notas']);
        $this->assertCount(2, $gabinete['piezas']);                        // se agregó la puerta
        $this->assertSame('1800', $doc['subensambles'][0]['piezas'][0]['largo_mm']); // no se pisó
    }

    public function test_resolver_materiales_pintura_y_avisos(): void
    {
        $doc = Normalizador::resolver(Normalizador::normalizar(self::respuestaIa()), self::DICCIONARIO, self::MATERIALES);
        [$poste, $gabinete, $general, $herraje] = $doc['subensambles'];

        // Código de leyenda → material del catálogo
        $vertical = $poste['piezas'][0];
        $this->assertSame(1, $vertical['material_id']);
        $this->assertSame('T-CUADRADO 1" C.16', $vertical['material_texto']);
        $this->assertSame('101.6', $vertical['perimetro_pintable_mm']);     // 4 × 25.4: igual que el Excel
        $this->assertSame('info', $vertical['avisos'][0]['nivel']);

        // Lámina: 2 caras estimadas
        $this->assertSame(2, $poste['piezas'][1]['material_id']);
        $this->assertSame('2', $poste['piezas'][1]['caras_pintables']);

        // Melamina sin ancho → error
        $costado = $gabinete['piezas'][0];
        $this->assertSame(5, $costado['material_id']);
        $this->assertSame('error', $costado['avisos'][0]['nivel']);

        // "MELAMINA ROBLE 25MM" no está como alias en este diccionario → error para que el usuario elija
        $this->assertSame(null, $general['piezas'][0]['material_id']);
        $this->assertSame('error', $general['piezas'][0]['avisos'][0]['nivel']);

        // Herraje sin alias → error; melamina no lleva pintura
        $this->assertSame(null, $herraje['piezas'][0]['material_id']);
        $this->assertSame('0', $costado['caras_pintables']);
    }

    public function test_respeta_material_elegido_a_mano(): void
    {
        $doc = Normalizador::normalizar(['subensambles' => [['nombre' => 'X', 'cantidad' => 1, 'piezas' => [
            ['descripcion' => 'Bisagra', 'material' => 'BISAGRA RARA', 'cantidad' => 4],
        ]]]]);
        $doc['subensambles'][0]['piezas'][0]['material_id'] = 9;
        $r = Normalizador::resolver($doc, self::DICCIONARIO, self::MATERIALES);

        $this->assertSame(9, $r['subensambles'][0]['piezas'][0]['material_id']);
        $this->assertSame([], $r['subensambles'][0]['piezas'][0]['avisos']);
    }

    public function test_perimetros_de_tubo(): void
    {
        $this->assertSame('101.6', Normalizador::perimetroTubo('T-CUADRADO 1" C.16'));
        $this->assertSame('203.2', Normalizador::perimetroTubo('PTR 2" CAL 14'));
        $this->assertSame('152.4', Normalizador::perimetroTubo('TUBO RECTANGULAR 1" X 2" C.18'));
        $this->assertSame('76.2', Normalizador::perimetroTubo('PTR 3/4" C.18'));
        $this->assertSame(null, Normalizador::perimetroTubo('SOLERA 1/8'));
    }

    public function test_explosion_multiplica_por_subensamble(): void
    {
        $doc = Normalizador::resolver(Normalizador::normalizar(self::respuestaIa()), self::DICCIONARIO, self::MATERIALES);
        $filas = Normalizador::explotar($doc['subensambles']);

        $this->assertSame('POSTE FINAL', $filas[0]['subensamble']);
        $this->assertSame('4', $filas[0]['cantidad']);   // 2 verticales × 2 postes
        $this->assertSame('6', $filas[1]['cantidad']);   // 3 ángulos × 2 postes
        $this->assertSame('6', $filas[2]['cantidad']);   // 2 costados × 3 gabinetes
        $this->assertSame('12', $filas[4]['cantidad']);  // herraje por mueble
    }

    public function test_herraje_con_material_vacio_usa_su_descripcion(): void
    {
        $doc = Normalizador::normalizar(['herrajes' => [['descripcion' => 'Bisagra recta 35mm', 'cantidad' => 12, 'material' => '']]]);

        $this->assertSame('BISAGRA RECTA 35MM', $doc['subensambles'][0]['piezas'][0]['material_texto']);
    }

    public function test_subensamble_sin_cantidad_sale_en_rojo(): void
    {
        $doc = Normalizador::normalizar(['subensambles' => [['nombre' => 'GABINETE', 'cantidad' => null, 'piezas' => [
            ['descripcion' => 'Bisagra', 'material' => 'M09', 'cantidad' => 4],
        ]]]]);
        $r = Normalizador::resolver($doc, self::DICCIONARIO, self::MATERIALES);

        $this->assertSame('1', $r['subensambles'][0]['cantidad']);
        $this->assertSame('error', $r['subensambles'][0]['piezas'][0]['avisos'][0]['nivel']);
        $this->assertStringStartsWith(Normalizador::AVISO_CANTIDAD, $r['subensambles'][0]['piezas'][0]['avisos'][0]['texto']);
    }

    public function test_codigos_de_leyenda_no_son_alias(): void
    {
        $this->assertTrue(Normalizador::esCodigoLeyenda('ML1'));
        $this->assertFalse(Normalizador::esCodigoLeyenda('BISAGRA RECTA CAZOLETA 35MM'));
        $this->assertFalse(Normalizador::esCodigoLeyenda('T-CUADRADO 1" C.16'));
    }

    public function test_resumen_para_la_ia_es_corto(): void
    {
        $doc = Normalizador::normalizar(self::respuestaIa());
        $r = Prompts::resumenBorrador($doc, 200);
        $this->assertLessThan(230, mb_strlen($r));
        $this->assertNotEmpty($r);
    }
}
