<?php

namespace App\Cotizaciones;

use App\Models\Material;
use App\Models\MaterialAlias;

/**
 * Convierte el despiece pegado desde Excel en piezas. No guarda nada:
 * regresa la vista previa con las piezas reconocidas y los errores.
 *
 * Columnas (en este orden; las últimas son opcionales):
 *   Descripción | Material (clave o nombre del plano) | Cantidad | Largo mm | Ancho mm | Pintura | Subensamble
 *
 * "Pintura" = perímetro pintable en mm para tubo (LINEAL) o número de caras
 * para lámina/tablero (AREA, AREA_KG).
 */
class Importador
{
    /** @return array{piezas: array, errores: array} */
    public static function importar(string $texto): array
    {
        // Diccionario: clave (M01) o alias del plano (LAMINA CRS C. 11) → material
        $materiales = Material::where('activo', true)->get(['id', 'clave', 'tipo_calculo'])->keyBy('id');
        $porNombre = $materiales->pluck('id', 'clave')->all();
        foreach (MaterialAlias::all(['alias', 'material_id']) as $a) {
            $porNombre[$a->alias] = $a->material_id;
        }

        $piezas = [];
        $errores = [];
        $lineas = explode("\n", str_replace("\r", '', $texto));

        foreach ($lineas as $i => $linea) {
            $n = $i + 1;
            if (trim($linea) === '') {
                continue;
            }
            $cols = self::separar($linea);
            if (count($cols) < 3) {
                $errores[] = ['linea' => $n, 'mensaje' => 'Se esperan al menos 3 columnas: descripción, material, cantidad.'];
                continue;
            }

            $cantidad = self::numero($cols[2]);
            if ($cantidad === null) {
                if ($i === 0) {
                    continue; // renglón de encabezados
                }
                $errores[] = ['linea' => $n, 'mensaje' => "La cantidad \"{$cols[2]}\" no es un número."];
                continue;
            }

            $nombre = mb_strtoupper(trim($cols[1]));
            $materialId = $porNombre[$nombre] ?? null;
            if ($materialId === null) {
                $errores[] = ['linea' => $n, 'mensaje' => "No se reconoce el material \"{$cols[1]}\": usa la clave (M01) o registra el alias en el catálogo."];
                continue;
            }
            if (trim($cols[0]) === '') {
                $errores[] = ['linea' => $n, 'mensaje' => 'Falta la descripción.'];
                continue;
            }
            if ((float) $cantidad <= 0) {
                $errores[] = ['linea' => $n, 'mensaje' => 'La cantidad debe ser mayor a 0.'];
                continue;
            }

            $pintura = self::numero($cols[5] ?? '') ?? '0';
            $tipo = $materiales[$materialId]->tipo_calculo ?? '';

            $piezas[] = [
                'subensamble' => trim($cols[6] ?? ''),
                'descripcion' => trim($cols[0]),
                'material_id' => $materialId,
                'cantidad' => $cantidad,
                'largo_mm' => self::numero($cols[3] ?? '') ?? '0',
                'ancho_mm' => self::numero($cols[4] ?? '') ?? '0',
                'perimetro_pintable_mm' => $tipo === 'LINEAL' ? $pintura : '0',
                'caras_pintables' => in_array($tipo, ['AREA', 'AREA_KG'], true) ? $pintura : '0',
                'notas' => '',
            ];
        }

        return ['piezas' => $piezas, 'errores' => $errores];
    }

    /** Excel pega con tabuladores; a mano se acepta ; o | */
    private static function separar(string $linea): array
    {
        $sep = str_contains($linea, "\t") ? "\t" : (str_contains($linea, ';') ? ';' : (str_contains($linea, '|') ? '|' : "\t"));

        return array_map('trim', explode($sep, $linea));
    }

    /** Acepta "1800", "1,800.5", "0.25" y "0,25". Vacío = "0". Texto no numérico = null. */
    private static function numero(string $s): ?string
    {
        $s = str_replace(' ', '', trim($s));
        if ($s === '') {
            return '0';
        }
        $s = (str_contains($s, ',') && str_contains($s, '.')) ? str_replace(',', '', $s) : str_replace(',', '.', $s);

        return is_numeric($s) ? $s : null;
    }
}
