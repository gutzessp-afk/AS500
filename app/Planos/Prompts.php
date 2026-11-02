<?php

namespace App\Planos;

/**
 * Las instrucciones que se le dan a la IA. Están aparte para poder
 * mejorarlas sin tocar la lógica. Regla de oro: NO INVENTAR datos.
 */
final class Prompts
{
    /** Forma exacta del JSON que pedimos (la misma para texto y para dibujos). */
    private const FORMA = <<<'JSON'
{
  "plano": {"modelo": "", "descripcion": "", "cliente": "", "revision": "", "acabado": ""},
  "leyenda": [{"codigo": "ML2", "material": "MELAMINA ROBLE (SMA) 25mm"}],
  "subensambles": [
    {"nombre": "GABINETE", "cantidad": 3, "pagina": 1, "notas": "",
     "piezas": [
       {"descripcion": "COSTADO", "material": "ML1", "cantidad": 2, "largo_mm": 669, "ancho_mm": 333,
        "pintura": null, "pagina": 2, "notas": ""}
     ]}
  ],
  "herrajes": [{"descripcion": "BISAGRA RECTA CAZOLETA 35mm", "cantidad": 12, "material": ""}]
}
JSON;

    private const REGLAS = <<<'TXT'
Eres ingeniero de manufactura en SULA, fábrica mexicana de mobiliario comercial
(góndolas, exhibidores, vitrinas, mesas) de metal (tubo, lámina) y melamina.
Lees planos de SolidWorks y armas el DESPIECE: mueble → subensambles → piezas.

REGLAS
1. Responde SOLO con JSON válido con esta forma exacta (sin texto extra, sin markdown):
FORMA
2. NO INVENTES. Si un dato no aparece, pon null. Es mejor null que un número inventado.
3. "leyenda": la tabla MATERIALES / ACABADOS del cuadro de datos (código → nombre).
4. "subensambles": cada renglón de la tabla general (NO. | DESCRIPCION | MATERIAL | CANTIDAD | LARGO | ANCHO)
   cuyo material sea "VARIOS" es un subensamble; su "cantidad" es cuántos lleva UN mueble.
   Las piezas que van directo al mueble (con material concreto) van en el subensamble "ENSAMBLE GENERAL" con cantidad 1,
   y la cantidad de cada pieza es la del renglón.
5. La "cantidad" de una pieza es cuántas lleva UN subensamble (no el total del mueble).
6. "material": copia el código de la leyenda (ML2, LM1, TC1) o el texto exacto del plano.
7. Medidas en milímetros. Si la acotación del plano está en pulgadas, conviértelas (× 25.4).
   "largo_mm" es la medida mayor y "ancho_mm" la menor. Tubos y perfiles solo llevan largo.
8. "pintura": para tubo, el perímetro pintable en mm; para lámina, las caras pintadas (1 o 2);
   para melamina, vidrio y herrajes, null. Si no estás seguro, null.
9. "herrajes": tornillería, bisagras, jaladeras, soportes, niveladores, etc., con la cantidad POR MUEBLE.
10. Usa nombres en MAYÚSCULAS como vienen en el plano. Pon en "pagina" el número de página donde lo viste.
TXT;

    public static function sistema(): string
    {
        return str_replace('FORMA', self::FORMA, self::REGLAS);
    }

    /** Pasada 1: solo texto de un grupo de páginas. */
    public static function texto(string $paginas, string $texto, int $total): string
    {
        return "Texto extraído de las páginas {$paginas} (de {$total}) del plano. "
            ."Las columnas pueden venir desalineadas: usa el sentido común para juntar descripción, material, cantidad y medidas.\n\n"
            ."=== TEXTO ===\n{$texto}\n=== FIN ===";
    }

    /** Pasada 2: imágenes de páginas + lo que ya se tiene, para completar lo que falta. */
    public static function dibujos(string $paginas, string $texto, string $borrador): string
    {
        return "Te mando las imágenes de las páginas {$paginas} del plano y su texto.\n"
            ."Ya tenemos este despiece BORRADOR (resumido). Las piezas con null necesitan datos:\n"
            ."{$borrador}\n\n"
            ."Mira las vistas y cotas de las imágenes y regresa, con la misma forma JSON, SOLO:\n"
            ."- las piezas del borrador a las que les puedas completar datos (usa el MISMO nombre de subensamble y de pieza), y\n"
            ."- las piezas que se ven en estas páginas y faltan en el borrador.\n"
            ."No repitas piezas que ya están completas. Si no hay nada que agregar, regresa {\"subensambles\": []}.\n\n"
            ."=== TEXTO DE ESAS PÁGINAS ===\n{$texto}\n=== FIN ===";
    }

    /** Resumen compacto del borrador para no gastar tokens (solo lo que hace falta). */
    public static function resumenBorrador(array $doc, int $maxCaracteres = 3500): string
    {
        $lineas = [];
        foreach ($doc['subensambles'] as $s) {
            $lineas[] = "[{$s['nombre']} ×".($s['cantidad'] ?? 'null').']';
            foreach ($s['piezas'] as $p) {
                $lineas[] = "  - {$p['descripcion']} | mat: ".($p['material_texto'] ?: 'null')
                    .' | cant: '.($p['cantidad'] ?? 'null')
                    .' | largo: '.($p['largo_mm'] ?? 'null')
                    .' | ancho: '.($p['ancho_mm'] ?? 'null');
            }
        }
        $texto = implode("\n", $lineas);

        return mb_strlen($texto) > $maxCaracteres ? mb_substr($texto, 0, $maxCaracteres)."\n  …(recortado)" : $texto;
    }
}
