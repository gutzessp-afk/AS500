<?php

namespace Tests\Unit;

use App\Costeo\Ajuste;
use App\Costeo\Dec;
use App\Costeo\Entrada;
use App\Costeo\Material;
use App\Costeo\Motor;
use App\Costeo\Parametros;
use App\Costeo\Pieza;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * LA PRUEBA DE ORO: el Módulo Exhibidor (plano "SULA – MODULO EXHIBIDOR PL-FT REV-00")
 * tiene que dar exactamente lo mismo que la plantilla Costeo_Modulo_Exhibidor_SULA.xlsx.
 */
class MotorCosteoTest extends TestCase
{
    private static function mat(int $id, string $clave, string $cat, string $tipo, string $unidad, string $pres,
        string $contenido, string $modo, string $precio, string $por, bool $iva, string $conf, string $kgM2 = '0'): Material
    {
        return new Material($id, $clave, $clave, $cat, $tipo, $unidad, $pres, Dec::de($contenido), Dec::de($kgM2),
            $modo, true, Dec::de($precio), $por, $iva, Dec::de('0.16'), $conf, 1);
    }

    private static function pz(string $desc, int $mid, string $cant, string $largo = '0', string $ancho = '0',
        string $perim = '0', string $caras = '0'): Pieza
    {
        return new Pieza(0, 0, '', $desc, $mid, Dec::de($cant), Dec::de($largo), Dec::de($ancho), Dec::de($perim), Dec::de($caras));
    }

    public static function entradaExhibidor(): Entrada
    {
        $lista = [
            self::mat(1, 'M01', 'METAL', 'LINEAL', 'm', 'Tramo 6 m', '6', 'COMPLETA', '243.09', 'PRESENTACION', true, 'MEDIA'),
            self::mat(2, 'M02', 'METAL', 'AREA_KG', 'kg', 'Hoja 4×8', '72.57', 'CONSUMIDO', '28', 'UNIDAD', false, 'ALTA', '24.41'),
            self::mat(3, 'M03', 'METAL', 'AREA_KG', 'kg', 'Hoja 4×8', '36.29', 'CONSUMIDO', '28', 'UNIDAD', false, 'ALTA', '12.20'),
            self::mat(4, 'M04', 'ACABADO', 'PESO', 'kg', 'Caja 25 kg', '25', 'CONSUMIDO', '200', 'UNIDAD', false, 'ALTA'),
            self::mat(5, 'M05', 'MADERA', 'AREA', 'm2', 'Hoja 1.22×2.44', '2.9768', 'CONSUMIDO', '900', 'PRESENTACION', false, 'ALTA'),
            self::mat(6, 'M06', 'MADERA', 'AREA', 'm2', 'Hoja 1.22×2.44', '2.9768', 'CONSUMIDO', '1500', 'PRESENTACION', false, 'ALTA'),
            self::mat(7, 'M07', 'MADERA', 'LINEAL', 'm', 'Rollo 300 m', '300', 'CONSUMIDO', '1377', 'PRESENTACION', false, 'MEDIA'),
            self::mat(8, 'M08', 'MADERA', 'LINEAL', 'm', 'Rollo 300 m', '300', 'CONSUMIDO', '2388', 'PRESENTACION', false, 'MEDIA'),
            self::mat(9, 'M09', 'HERRAJES', 'PIEZA', 'pza', 'Pieza', '1', 'CONSUMIDO', '15', 'PRESENTACION', true, 'MEDIA'),
            self::mat(10, 'M10', 'HERRAJES', 'PIEZA', 'pza', 'Paquete 5 pzas', '5', 'CONSUMIDO', '267.51', 'PRESENTACION', true, 'MEDIA'),
            self::mat(11, 'M11', 'HERRAJES', 'PIEZA', 'pza', 'Pieza', '1', 'CONSUMIDO', '8.59', 'PRESENTACION', true, 'MEDIA'),
            self::mat(12, 'M12', 'HERRAJES', 'PIEZA', 'pza', 'Bolsa 12 pzas', '12', 'CONSUMIDO', '29', 'PRESENTACION', true, 'MEDIA'),
            self::mat(13, 'M13', 'HERRAJES', 'PIEZA', 'pza', 'Pieza', '1', 'CONSUMIDO', '1.16', 'PRESENTACION', true, 'MEDIA'),
            self::mat(14, 'M14', 'HERRAJES', 'PIEZA', 'pza', 'Bolsa 50 pzas', '50', 'CONSUMIDO', '481.40', 'PRESENTACION', true, 'MEDIA'),
            self::mat(15, 'M15', 'HERRAJES', 'PIEZA', 'pza', 'Juego 6 pzas', '6', 'CONSUMIDO', '23', 'PRESENTACION', false, 'MEDIA'),
            self::mat(16, 'M16', 'CONSUMIBLES', 'PESO', 'kg', 'Rollo 15 kg', '15', 'CONSUMIDO', '879', 'PRESENTACION', true, 'MEDIA'),
            self::mat(17, 'M17', 'CONSUMIBLES', 'PIEZA', 'pza', 'Por módulo', '1', 'COMPLETA', '35', 'PRESENTACION', false, 'BAJA'),
            self::mat(18, 'M18', 'CONSUMIBLES', 'PIEZA', 'pza', 'Por módulo', '1', 'COMPLETA', '40', 'PRESENTACION', false, 'BAJA'),
            self::mat(19, 'M19', 'CONSUMIBLES', 'PIEZA', 'pza', 'Por módulo', '1', 'COMPLETA', '25', 'PRESENTACION', false, 'BAJA'),
            self::mat(20, 'M20', 'CONSUMIBLES', 'PIEZA', 'pza', 'Por módulo', '1', 'COMPLETA', '30', 'PRESENTACION', false, 'BAJA'),
            self::mat(21, 'M21', 'EMPAQUE', 'PIEZA', 'pza', 'Por módulo', '1', 'COMPLETA', '250', 'PRESENTACION', false, 'ALTA'),
        ];
        $materiales = [];
        foreach ($lista as $m) {
            $materiales[$m->id] = $m;
        }

        $piezas = [
            self::pz('Vertical de poste', 1, '8', '1800', '0', '101.6'),
            self::pz('Travesaño de poste adicional', 1, '2', '299', '0', '101.6'),
            self::pz('Ángulo de amarre', 2, '12', '344', '37', '0', '2'),
            self::pz('Tapa de tubo 1"', 3, '8', '25.4', '25.4', '0', '1'),
            self::pz('Cubierta superior', 6, '1', '2000', '350'),
            self::pz('Cubierta de gabinetes', 6, '1', '2000', '350'),
            self::pz('Tablero base de gabinete', 6, '3', '632', '350'),
            self::pz('Entrepaño exterior móvil', 6, '9', '630', '350'),
            self::pz('Costado de gabinete', 5, '6', '669', '333'),
            self::pz('Respaldo de gabinete', 5, '3', '669', '600'),
            self::pz('Puerta de gabinete', 5, '6', '665', '312'),
            self::pz('Mangüete superior', 5, '6', '602', '50'),
            self::pz('Entrepaño interior', 5, '6', '598', '310'),
            self::pz('Cubrecanto 15 mm', 7, '45', '1000'),
            self::pz('Cubrecanto 28 mm', 8, '35', '1000'),
            self::pz('Bisagra recta', 9, '12'),
            self::pz('Jaladera', 10, '6'),
            self::pz('Soporte Kintai', 11, '36'),
            self::pz('Regatón 1"', 12, '8'),
            self::pz('Soporte plástico', 13, '24'),
            self::pz('Tuerca remache', 14, '8'),
            self::pz('Minifix', 15, '24'),
            self::pz('Microalambre', 16, '0.25'),
            self::pz('Gas', 17, '1'),
            self::pz('Abrasivos', 18, '1'),
            self::pz('Pretratamiento', 19, '1'),
            self::pz('Pijas', 20, '1'),
            self::pz('Empaque', 21, '1'),
        ];

        return new Entrada(
            cantidad: 1,
            frenteM: Dec::de('2'),
            pinturaMaterialId: 4,
            piezas: $piezas,
            materiales: $materiales,
            ajustes: [1 => new Ajuste(1, Dec::de('3'), Dec::de('0'), null)], // 3 tramos por módulo
            parametros: new Parametros(Dec::de('0.25'), Dec::de('0.15'), false, Dec::de('0.30'), Dec::de('5'), Dec::de('0.16'), 15),
        );
    }

    private static function porClave(array $r): array
    {
        return array_column($r['compras'], null, 'clave');
    }

    public function test_exhibidor_cuadra_con_el_excel(): void
    {
        $r = Motor::calcular(self::entradaExhibidor());
        $s = $r['resumen'];

        $this->assertSame('6158.84', $s['materiales']);
        $this->assertSame('1539.71', $s['mano_obra']);
        $this->assertSame('923.83', $s['overhead']);
        $this->assertSame('8622.37', $s['costo_total']);
        $this->assertSame('3695.30', $s['utilidad']);
        $this->assertSame('12317.68', $s['precio_venta']);
        $this->assertSame('1970.83', $s['iva']);
        $this->assertSame('6158.84', $s['precio_metro_lineal']);
        $this->assertSame('1.8344', $s['area_pintable_m2']);

        // Columna P de la hoja COMPRAS, material por material
        $esperado = [
            'M01' => '628.68', 'M02' => '104.39', 'M03' => '1.76', 'M04' => '73.38', 'M05' => '1535.46',
            'M06' => '2039.82', 'M07' => '206.55', 'M08' => '278.60', 'M09' => '155.17', 'M10' => '276.73',
            'M11' => '266.59', 'M12' => '16.67', 'M13' => '24.00', 'M14' => '66.40', 'M15' => '92.00',
            'M16' => '12.63', 'M17' => '35.00', 'M18' => '40.00', 'M19' => '25.00', 'M20' => '30.00', 'M21' => '250.00',
        ];
        $compras = self::porClave($r);
        $this->assertCount(21, $compras);
        foreach ($esperado as $clave => $costo) {
            $this->assertSame($costo, $compras[$clave]['costo_por_mueble'], "Costo de $clave");
        }

        // Hoja RESUMEN por categoría
        $cats = array_column($r['categorias'], 'costo', 'categoria');
        $this->assertSame(['METAL' => '734.84', 'MADERA' => '4060.44', 'ACABADO' => '73.38',
            'HERRAJES' => '897.56', 'CONSUMIBLES' => '142.63', 'EMPAQUE' => '250.00'], $cats);
    }

    public function test_presentaciones_a_comprar(): void
    {
        $c = self::porClave(Motor::calcular(self::entradaExhibidor()));
        $this->assertSame('3', $c['M01']['presentaciones_comprar']);   // 3 tramos (plan de corte)
        $this->assertSame('COMPLETA', $c['M01']['modo_carga']);
        $this->assertSame('2', $c['M05']['presentaciones_comprar']);   // 5.0786 m² ÷ 2.9768 → 2 hojas
        $this->assertSame('2', $c['M10']['presentaciones_comprar']);   // 6 jaladeras en paquetes de 5
        $this->assertSame('0', $c['M01']['sobrante']);
    }

    public function test_pedido_de_diez_muebles(): void
    {
        $e = self::entradaExhibidor();
        $e->cantidad = 10;
        $r = Motor::calcular($e);
        // 10 × 5.0786 m² = 50.79 m² ÷ 2.9768 = 17.06 → 18 hojas (no 20)
        $this->assertSame('18', self::porClave($r)['M05']['presentaciones_comprar']);
        $diferencia = abs((float) $r['resumen']['total_pedido'] - (float) $r['resumen']['precio_venta'] * 10);
        $this->assertLessThan(0.05, $diferencia);
    }

    public function test_riesgo(): void
    {
        $r = Motor::calcular(self::entradaExhibidor());
        // Gas, abrasivos, pretratamiento y pijas son confianza BAJA: $130 de $6,158.84
        $this->assertSame('130.00', $r['riesgo']['monto_confianza_baja']);
        $this->assertSame('AMARILLO', $r['riesgo']['nivel']);

        // Un precio importante vencido sube a ROJO
        $e = self::entradaExhibidor();
        $e->materiales[6]->diasAntiguedad = 40;
        $this->assertSame('ROJO', Motor::calcular($e)['riesgo']['nivel']);
    }

    public function test_sin_acabado_avisa(): void
    {
        $e = self::entradaExhibidor();
        $e->pinturaMaterialId = null;
        $this->assertNotEmpty(Motor::calcular($e)['avisos']);
    }

    public function test_pieza_incompleta_avisa(): void
    {
        $e = self::entradaExhibidor();
        $e->piezas[] = self::pz('Sin cotas', 5, '1');
        $r = Motor::calcular($e);
        $this->assertNotEmpty($r['piezas'][count($e->piezas) - 1]['avisos']);
    }

    public function test_la_foto_en_json_da_el_mismo_resultado(): void
    {
        // Así se congela una cotización: Entrada → JSON → Entrada
        $original = self::entradaExhibidor();
        $copia = Entrada::deArreglo(json_decode(json_encode($original->aArreglo()), true));
        $this->assertSame(Motor::calcular($original), Motor::calcular($copia));
    }

    public function test_cantidad_cero_falla(): void
    {
        $e = self::entradaExhibidor();
        $e->cantidad = 0;
        $this->expectException(InvalidArgumentException::class);
        Motor::calcular($e);
    }

    public function test_margen_de_cien_por_ciento_falla(): void
    {
        $e = self::entradaExhibidor();
        $e->parametros->margenPct = Dec::de('1');
        $this->expectException(InvalidArgumentException::class);
        Motor::calcular($e);
    }
}
