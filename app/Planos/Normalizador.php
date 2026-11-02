<?php

namespace App\Planos;

use App\Costeo\Dec;

/**
 * Convierte lo que responde la IA en un despiece limpio y confiable.
 * NO toca la base de datos (por eso se puede probar sin Laravel).
 *
 *   normalizar() → acomoda el JSON de la IA a una forma fija (números como texto, nombres limpios)
 *   fusionar()   → junta dos lecturas (otro grupo de páginas, o la pasada con dibujos)
 *   resolver()   → material del plano → material del catálogo, estima pintura y pone avisos
 *   explotar()   → subensambles × piezas = renglones POR MUEBLE para la cotización
 */
final class Normalizador
{
    public const SIN_SUBENSAMBLE = 'ENSAMBLE GENERAL';

    /** Inicio del aviso de cantidad no leída: "Aprobar" lo busca y no lo deja pasar. */
    public const AVISO_CANTIDAD = 'No se leyó la cantidad';

    // ------------------------------------------------------------------
    // 1. NORMALIZAR
    // ------------------------------------------------------------------

    public static function normalizar(array $crudo): array
    {
        $plano = (array) ($crudo['plano'] ?? []);
        $doc = [
            'plano' => [
                'modelo' => self::textoONulo($plano['modelo'] ?? null),
                'descripcion' => self::textoONulo($plano['descripcion'] ?? null),
                'cliente' => self::textoONulo($plano['cliente'] ?? null),
                'revision' => self::textoONulo($plano['revision'] ?? null),
                'acabado' => self::textoONulo($plano['acabado'] ?? null),
            ],
            'leyenda' => [],
            'subensambles' => [],
        ];

        // Leyenda de materiales del plano: "ML2" → "MELAMINA ROBLE (SMA) 25MM"
        foreach ((array) ($crudo['leyenda'] ?? []) as $k => $l) {
            if (is_array($l)) {
                $codigo = self::llaveMaterial($l['codigo'] ?? '');
                $texto = self::llaveMaterial($l['material'] ?? $l['nombre'] ?? '');
            } else {
                $codigo = self::llaveMaterial((string) $k);
                $texto = self::llaveMaterial((string) $l);
            }
            if ($codigo !== '' && $texto !== '' && $codigo !== $texto) {
                $doc['leyenda'][$codigo] = $texto;
            }
        }

        foreach ((array) ($crudo['subensambles'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $sub = [
                'nombre' => mb_strtoupper(self::texto($s['nombre'] ?? $s['descripcion'] ?? '')) ?: self::SIN_SUBENSAMBLE,
                'cantidad' => self::numero($s['cantidad'] ?? $s['cantidad_por_unidad'] ?? null),
                'pagina' => self::entero($s['pagina'] ?? $s['pagina_plano'] ?? null),
                'notas' => self::texto($s['notas'] ?? ''),
                'piezas' => [],
            ];
            foreach ((array) ($s['piezas'] ?? $s['componentes'] ?? []) as $p) {
                if (is_array($p) && ($pieza = self::pieza($p))) {
                    $sub['piezas'][] = $pieza;
                }
            }
            $doc = self::agregarSubensamble($doc, $sub);
        }

        // Herraje suelto (por mueble) → subensamble "HERRAJE DE ARMADO"
        $herrajes = [];
        foreach ((array) ($crudo['herrajes'] ?? $crudo['herraje_armado'] ?? []) as $h) {
            // Con ?: porque la IA suele mandar "material": "" y el herraje se reconoce por su descripción
            if (is_array($h) && ($pieza = self::pieza(['material' => ($h['material'] ?? '') ?: ($h['nombre'] ?? $h['descripcion'] ?? '')] + $h))) {
                $herrajes[] = $pieza;
            }
        }
        if ($herrajes) {
            $doc = self::agregarSubensamble($doc, ['nombre' => 'HERRAJE DE ARMADO', 'cantidad' => '1', 'pagina' => null, 'notas' => '', 'piezas' => $herrajes]);
        }

        return $doc;
    }

    private static function pieza(array $p): ?array
    {
        $descripcion = self::texto($p['descripcion'] ?? $p['nombre'] ?? '');
        if ($descripcion === '') {
            return null;
        }

        return [
            'descripcion' => $descripcion,
            'material_texto' => self::llaveMaterial($p['material'] ?? ''),
            'cantidad' => self::numero($p['cantidad'] ?? $p['cantidad_por_subensamble'] ?? null),
            'largo_mm' => self::numero($p['largo_mm'] ?? $p['largo'] ?? null),
            'ancho_mm' => self::numero($p['ancho_mm'] ?? $p['ancho'] ?? null),
            'pintura' => self::numero($p['pintura'] ?? null),
            'pagina' => self::entero($p['pagina'] ?? $p['pagina_plano'] ?? null),
            'notas' => self::texto($p['notas'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // 2. FUSIONAR: lo que ya teníamos ($a) + una lectura nueva ($b)
    //    Lo de $a manda; de $b solo se toma lo que a $a le falta o lo nuevo.
    // ------------------------------------------------------------------

    public static function fusionar(array $a, array $b): array
    {
        foreach ($b['plano'] ?? [] as $k => $v) {
            if (($a['plano'][$k] ?? null) === null && $v !== null) {
                $a['plano'][$k] = $v;
            }
        }
        $a['leyenda'] = ($a['leyenda'] ?? []) + ($b['leyenda'] ?? []);
        foreach ($b['subensambles'] ?? [] as $sub) {
            $a = self::agregarSubensamble($a, $sub);
        }

        return $a;
    }

    /** Agrega un subensamble; si ya existe uno con el mismo nombre, mezcla sus piezas. */
    private static function agregarSubensamble(array $doc, array $sub): array
    {
        foreach ($doc['subensambles'] as $i => $existente) {
            if (self::llave($existente['nombre']) !== self::llave($sub['nombre'])) {
                continue;
            }
            $e = &$doc['subensambles'][$i];
            $e['cantidad'] ??= $sub['cantidad'];
            $e['pagina'] ??= $sub['pagina'];
            if ($e['notas'] === '') {
                $e['notas'] = $sub['notas'];
            }
            foreach ($sub['piezas'] as $p) {
                $e['piezas'] = self::agregarPieza($e['piezas'], $p);
            }
            unset($e);

            return $doc;
        }
        // Subensamble nuevo (sin repetir piezas dentro de él)
        $piezas = [];
        foreach ($sub['piezas'] as $p) {
            $piezas = self::agregarPieza($piezas, $p);
        }
        $sub['piezas'] = $piezas;
        $doc['subensambles'][] = $sub;

        return $doc;
    }

    private static function agregarPieza(array $piezas, array $p): array
    {
        foreach ($piezas as $i => $e) {
            if (self::llave($e['descripcion']) === self::llave($p['descripcion'])) {
                foreach (['cantidad', 'largo_mm', 'ancho_mm', 'pintura', 'pagina'] as $k) {
                    $piezas[$i][$k] ??= $p[$k];
                }
                if ($e['material_texto'] === '') {
                    $piezas[$i]['material_texto'] = $p['material_texto'];
                }
                if ($p['notas'] !== '' && ! str_contains($e['notas'], $p['notas'])) {
                    $piezas[$i]['notas'] = trim($e['notas'].' · '.$p['notas'], ' ·');
                }

                return $piezas;
            }
        }
        $piezas[] = $p;

        return $piezas;
    }

    // ------------------------------------------------------------------
    // 3. RESOLVER contra el catálogo
    // ------------------------------------------------------------------

    /**
     * @param  array  $diccionario  llaveMaterial(texto) → material_id  (claves M01… y alias)
     * @param  array  $materiales  material_id → ['tipo' => 'LINEAL', 'categoria' => 'METAL', 'descripcion' => '…']
     */
    public static function resolver(array $doc, array $diccionario, array $materiales): array
    {
        // Versión "sin espacios" del diccionario: tolera "LAMINA CRS C.11" vs "LAMINA CRS C. 11"
        $compacto = [];
        foreach ($diccionario as $texto => $id) {
            $compacto[self::compacta($texto)] = $id;
        }

        foreach ($doc['subensambles'] as $si => $sub) {
            $sinCantidad = $sub['cantidad'] === null || (float) $sub['cantidad'] <= 0;
            if ($sinCantidad) {
                $doc['subensambles'][$si]['cantidad'] = '1';
            }
            foreach ($sub['piezas'] as $pi => $p) {
                $pieza = self::resolverPieza($p, $doc['leyenda'] ?? [], $diccionario, $compacto, $materiales);
                if ($sinCantidad) {
                    // Multiplica todas sus piezas: por eso el aviso va en cada una
                    $pieza['avisos'][] = ['nivel' => 'error', 'texto' => self::AVISO_CANTIDAD.' del subensamble: se puso 1.'];
                }
                $doc['subensambles'][$si]['piezas'][$pi] = $pieza;
            }
        }

        return $doc;
    }

    private static function resolverPieza(array $p, array $leyenda, array $dic, array $compacto, array $materiales): array
    {
        $avisos = [];

        // Si viene el código de la leyenda (ML2) se cambia por el nombre completo
        $texto = $p['material_texto'];
        if (isset($leyenda[$texto])) {
            $texto = $leyenda[$texto];
        } elseif (preg_match('/^([A-Z]{1,4}\d{1,3})\s*[-–:]?\s+(.+)$/u', $texto, $m) && isset($leyenda[$m[1]])) {
            $texto = $m[2]; // "ML2 - MELAMINA…" → "MELAMINA…"
        }
        $p['material_texto'] = $texto;

        $id = $p['material_id'] ?? null;
        $id ??= $dic[$texto] ?? $compacto[self::compacta($texto)] ?? null;
        $p['material_id'] = $id;

        if ($p['cantidad'] === null || (float) $p['cantidad'] <= 0) {
            $p['cantidad'] = '1';
            $avisos[] = ['nivel' => 'error', 'texto' => self::AVISO_CANTIDAD.': se puso 1.'];
        }

        $mat = $id ? ($materiales[$id] ?? null) : null;
        if (! $mat) {
            $avisos[] = ['nivel' => 'error', 'texto' => $texto === ''
                ? 'Sin material: elige uno del catálogo.'
                : "«{$texto}» no está en el catálogo: elige el material y se guardará como alias."];
        }

        $tipo = $mat['tipo'] ?? null;
        $largo = $p['largo_mm'] ?? null;
        $ancho = $p['ancho_mm'] ?? null;
        if ($tipo === 'LINEAL' && ! self::positivo($largo)) {
            $avisos[] = ['nivel' => 'error', 'texto' => 'Falta el largo.'];
        }
        if (in_array($tipo, ['AREA', 'AREA_KG'], true) && (! self::positivo($largo) || ! self::positivo($ancho))) {
            $avisos[] = ['nivel' => 'error', 'texto' => 'Faltan medidas: se necesitan largo y ancho.'];
        }

        // Pintura: perímetro (tubo) o caras (lámina). Si la IA no la dio, se estima.
        $perimetro = '0';
        $caras = '0';
        if ($tipo === 'LINEAL') {
            $perimetro = $p['pintura'] ?? null;
            if ($perimetro === null && ($mat['categoria'] ?? '') === 'METAL') {
                $perimetro = self::perimetroTubo($texto.' '.($mat['descripcion'] ?? ''));
                $avisos[] = $perimetro !== null
                    ? ['nivel' => 'info', 'texto' => "Pintura estimada: perímetro {$perimetro} mm."]
                    : ['nivel' => 'info', 'texto' => 'Sin dato de pintura (perímetro).'];
            }
        } elseif (in_array($tipo, ['AREA', 'AREA_KG'], true)) {
            $caras = $p['pintura'] ?? null;
            if ($caras === null && ($mat['categoria'] ?? '') === 'METAL') {
                $caras = '2';
                $avisos[] = ['nivel' => 'info', 'texto' => 'Pintura estimada: 2 caras.'];
            }
        }

        $p['largo_mm'] = $largo ?? '0';
        $p['ancho_mm'] = $ancho ?? '0';
        $p['perimetro_pintable_mm'] = $perimetro ?? '0';
        $p['caras_pintables'] = $caras ?? '0';
        $p['avisos'] = $avisos;
        unset($p['pintura']);

        return $p;
    }

    /** Perímetro de un tubo según su medida en pulgadas: 1" cuadrado = 101.6 mm. */
    public static function perimetroTubo(string $texto): ?string
    {
        $t = mb_strtoupper($texto);
        $num = '(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)';
        if (preg_match("/{$num}\s*\"\s*[X×]\s*{$num}\s*\"/u", $t, $m)) {
            $mm = Dec::de(self::pulgadas($m[1]))->plus(Dec::de(self::pulgadas($m[2])))->multipliedBy(2)->multipliedBy('25.4');

            return Dec::d4($mm);
        }
        if (preg_match("/{$num}\s*\"/u", $t, $m)) {
            $lado = Dec::de(self::pulgadas($m[1]))->multipliedBy('25.4');
            if (preg_match('/REDOND|CIRCULAR|CEDULA|CÉDULA/u', $t)) {
                return Dec::d4($lado->multipliedBy('3.14159265'));
            }
            if (preg_match('/CUADRAD|PTR|T-CUAD/u', $t)) {
                return Dec::d4($lado->multipliedBy(4));
            }
        }

        return null;
    }

    /** "1" → 1, "3/4" → 0.75, "1 1/2" → 1.5 */
    private static function pulgadas(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $s, $m)) {
            return (string) ((int) $m[1] + (int) $m[2] / (int) $m[3]);
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $s, $m)) {
            return (string) ((int) $m[1] / (int) $m[2]);
        }

        return $s;
    }

    // ------------------------------------------------------------------
    // 4. EXPLOTAR: renglones POR MUEBLE (lo que espera la cotización)
    // ------------------------------------------------------------------

    /** @param  array  $subensambles  [['nombre', 'cantidad', 'piezas' => [pieza resuelta…]]] */
    public static function explotar(array $subensambles): array
    {
        $filas = [];
        foreach ($subensambles as $sub) {
            $veces = Dec::de($sub['cantidad'] ?? '1');
            foreach ($sub['piezas'] as $p) {
                $filas[] = [
                    'subensamble' => $sub['nombre'],
                    'descripcion' => $p['descripcion'],
                    'material_id' => $p['material_id'],
                    'cantidad' => Dec::d4(Dec::de($p['cantidad'])->multipliedBy($veces)),
                    'largo_mm' => (string) ($p['largo_mm'] ?? '0'),
                    'ancho_mm' => (string) ($p['ancho_mm'] ?? '0'),
                    'perimetro_pintable_mm' => (string) ($p['perimetro_pintable_mm'] ?? '0'),
                    'caras_pintables' => (string) ($p['caras_pintables'] ?? '0'),
                    'notas' => (string) ($p['notas'] ?? ''),
                ];
            }
        }

        return $filas;
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** "2,000" → "2000" · "1800 mm" → "1800" · "0,25" → "0.25" · null/"—"/"VARIOS" → null */
    public static function numero(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? Dec::d4(Dec::de($v)) : ($v == 0 ? '0' : null);
        }
        if (! is_string($v)) {
            return null;
        }
        $s = str_replace(' ', '', trim($v));
        if (preg_match('/^[1-9]\d{0,2}(,\d{3})+(?!\d)/', $s)) {
            $s = str_replace(',', '', $s);   // 1,800.5
        } else {
            $s = str_replace(',', '.', $s);  // 0,25
        }
        if (! preg_match('/^\d+(\.\d+)?/', $s, $m)) {
            return null;
        }

        return Dec::d4(Dec::de($m[0]));
    }

    /** Llave para comparar nombres: mayúsculas, sin acentos, espacios simples. */
    public static function llave(string $s): string
    {
        $s = strtr(mb_strtoupper(trim($s)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/', ' ', $s);
    }

    /** Así se guardan los alias del catálogo: MAYÚSCULAS y espacios simples (con acentos). */
    public static function llaveMaterial(mixed $s): string
    {
        return is_scalar($s) ? preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $s))) : '';
    }

    /** "ML1", "TC1": código de leyenda; significa otra cosa en cada plano, no sirve como alias. */
    public static function esCodigoLeyenda(string $s): bool
    {
        return preg_match('/^[A-Z]{1,4}\d{1,3}$/u', $s) === 1;
    }

    private static function compacta(string $s): string
    {
        return preg_replace('/[\s.]+/', '', self::llave($s));
    }

    private static function texto(mixed $v): string
    {
        return is_scalar($v) ? preg_replace('/\s+/', ' ', trim((string) $v)) : '';
    }

    private static function textoONulo(mixed $v): ?string
    {
        $t = self::texto($v);

        return $t === '' ? null : $t;
    }

    private static function entero(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function positivo(?string $v): bool
    {
        return $v !== null && (float) $v > 0;
    }
}
