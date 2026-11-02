<!-- SPEC SULA Core · MÓDULO 3 · PARTE 5 DE 5 -->
> **PARTE 5 DE 5 (ÚLTIMA) del spec del Módulo 3.** Guárdala en `docs/SPEC-modulo3-parte5.md`. Ya con las 5 partes: júntalas en orden en `docs/SPEC-modulo3.md`, léelo completo y **muéstrame la lista de archivos que vas a tocar antes de empezar** (sección 0).

### `tests/Unit/NormalizadorTest.php`

**NUEVO.**

8 pruebas con una respuesta **de mentiras** parecida a la que daría Groq con el exhibidor: no gastan IA ni necesitan internet.
- Lo más importante: que **no se pisen** datos al juntar lecturas, que el tubo de 1" dé **101.6 mm** de pintura (igual que el Excel) y que la explosión multiplique bien.

````php
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

    public function test_resumen_para_la_ia_es_corto(): void
    {
        $doc = Normalizador::normalizar(self::respuestaIa());
        $r = Prompts::resumenBorrador($doc, 200);
        $this->assertLessThan(230, mb_strlen($r));
        $this->assertNotEmpty($r);
    }
}
````

## 12. ARRANCAR

Terminal 1 (base de datos y migración):
```bash
cd ~/dev/sula-core-laravel
docker compose up -d --wait
php artisan migrate
php artisan test --testsuite=Unit        # 21 pruebas en verde
php artisan sula:planos                  # primera sincronización
```

Terminal 2 (la **cola**: aquí trabaja la IA; déjala abierta):
```bash
cd ~/dev/sula-core-laravel
php artisan queue:work --timeout=900
```

Terminal 3 y 4 (como siempre):
```bash
php artisan serve
npm run dev
```

**Si cambias código del Job o del lector**, reinicia la terminal 2 (Ctrl+C y vuelve a correr `queue:work`): el trabajador carga el código una vez y no ve cambios.

---

## 13. CRITERIOS DE ACEPTACIÓN

| # | Prueba | Esperado |
|---|---|---|
| 1 | `php artisan test --testsuite=Unit` | **21** pruebas OK (9 motor + 4 nombre + 8 normalizador) |
| 2 | `php artisan migrate:status` | `2026_10_30_100004_crear_planos` en "Ran" |
| 3 | `php artisan sula:planos` con `PLANOS_FUENTE=local` y el PDF del exhibidor en `datos-privados/planos/` | tabla: 1 encontrado, 1 nuevo |
| 4 | Volver a correr `sula:planos` | 0 nuevos, 0 actualizados |
| 5 | Inicio | versión **0.4.0**; tarjeta PLANOS = 1 |
| 6 | Menú | sección "Ingeniería" → "Planos y despiece" |
| 7 | `/planos`, escribir `exhibidor` | aparece clave `SULA - MODULO EXHIBIDOR PL - FT`, REV-00, chip "Sin despiece" |
| 8 | Abrir el plano | el PDF se ve a la izquierda (3 páginas); "Descargar PDF" lo baja |
| 9 | "Leer con IA" **sin** la terminal 2 | se queda "En la fila…" (correcto: nadie atiende la cola) |
| 10 | Encender `queue:work` | en segundos: "Leyendo texto, páginas 1-2…", luego despiece v1 en BORRADOR |
| 11 | Revisar v1 | subensambles tipo POSTE FINAL, POSTE ADICIONAL, GABINETE ×3, cubiertas, herraje; leyenda LM1, LM2, TC1, ML1, ML2, TM1 |
| 12 | Piezas con ML1/ML2/TC1/LM1 | material del catálogo ya elegido (M05, M06, M01, M02) gracias a los alias |
| 13 | Herrajes (bisagra, jaladera, Kintai) | en rojo "no está en el catálogo" |
| 14 | "Aprobar" con rojos | mensaje "Hay N pieza(s) con avisos en rojo" y **no** aprueba |
| 15 | Elegir M09/M10/M11 en los herrajes, completar medidas en rojo, "Guardar" | los rojos desaparecen |
| 16 | "Aprobar" | chip Aprobado v1; en Materiales → M09 aparece el alias "BISAGRA RECTA CAZOLETA 35MM" |
| 17 | "Volver a leer con IA" | v2 nueva; al terminar, la bisagra **ya sale reconocida** (aprendió) |
| 18 | "Cotizar este plano", cliente vacío, cantidad 10 | abre una cotización BORRADOR con las piezas multiplicadas (ej. 2 costados × GABINETE ×3 = 6) |
| 19 | Journal | eventos de `despieces`, `despiece_piezas`, `material_alias` firmados por `ia-groq` y `gutz` |
| 20 | Tabla `ia_llamadas` (`docker exec -it sula-laravel-db psql -U sula -d sula_core -c "select paso, modelo, tokens_entrada, tokens_salida, ok from ia_llamadas"`) | una fila por llamada con tokens > 0 |
| 21 | Poner un `GROQ_API_KEY` falso y leer | despiece en ERROR con el mensaje de Groq (401), sin tronar la pantalla |
| 22 | "Leer con IA" marcando "revisar también los dibujos" | progreso "Revisando dibujos, página 1 de 3…" y luego BORRADOR con más medidas completas |
| 23 | Con `PLANOS_FUENTE=drive` y la carpeta de SULA MOB | `sula:planos` lista los PDFs con su carpeta; la clave sale del nombre (2-1-0072-0003) |
| 24 | Borrar un PDF de la carpeta local y sincronizar | "1 retirado"; ya no sale en la búsqueda |

**Sobre el criterio 11:** la IA no es determinista. Lo importante no es que acierte todo, sino que **lo que no sepa salga en rojo** y no inventado. Si se equivoca siempre en lo mismo, se ajusta `Prompts.php`.

---

## 14. GIT

```bash
git status                     # datos-privados/ y .env NO deben aparecer
git add -A
git commit -m "Módulo 3: planos desde Drive y despiece con IA (Groq)"
```
(El push lo hago yo.)

---

## 15. LÍMITES CONOCIDOS Y SIGUIENTE

- **Groq gratis** es lento en planos grandes con dibujos (6,000 tokens por minuto; cada imagen ≈ 2,000). Para uso diario en SULA, el plan de pago.
- Los modelos de Groq cambian: si uno desaparece, solo se cambia en el `.env`.
- El despiece de **cantos** (cubrecanto) y **consumibles** (microalambre, gas, pijas) no viene en los planos: se agregan a mano en el despiece o en la cotización.
- **Siguiente:** lo de ClickBalance que conviene meter (clientes/CRM ligero, inventario, cotización → orden) y, al final, el importador de **listas de precios**.
