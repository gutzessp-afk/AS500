<?php

namespace App\Planos;

use App\Ia\ErrorIa;
use App\Ia\Groq;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\MaterialAlias;
use Illuminate\Support\Facades\DB;

/**
 * Lee un plano con la IA y deja el resultado en el despiece (estado BORRADOR).
 *
 *   1. Baja el PDF (o usa la copia local) y saca el texto de cada página.
 *   2. PASADA DE TEXTO: manda el texto en grupos de páginas → arma el despiece.
 *   3. PASADA DE DIBUJOS (opcional): manda imágenes de las páginas → completa medidas.
 *   4. Normaliza, cruza materiales con el catálogo y guarda subensambles y piezas.
 *
 * Corre dentro de un Job (en segundo plano) porque puede tardar minutos.
 */
class LectorPlano
{
    public static function leer(Despiece $despiece): void
    {
        $plano = $despiece->plano;
        $avance = fn (string $msg) => $despiece->update(['progreso' => $msg]);

        $avance('Descargando el PDF…');
        $pdf = new ExtractorPdf(AlmacenPdf::ruta($plano));
        $total = $pdf->paginas();

        $textos = [];
        for ($i = 1; $i <= $total; $i++) {
            $textos[$i] = $pdf->texto($i);
        }

        // ---------- Pasada 1: texto ----------
        $doc = Normalizador::normalizar([]);
        $lotes = self::lotesDeTexto($textos, (int) config('sula.planos.caracteres_por_lote', 5000));
        if (! $lotes) {
            throw new ErrorIa('El PDF no trae texto (¿es un escaneo?). Vuelve a leerlo marcando "Revisar también los dibujos".');
        }

        foreach ($lotes as $n => $paginas) {
            $rango = self::rango($paginas);
            $avance('Leyendo texto, páginas '.$rango.' de '.$total.' ('.($n + 1).'/'.count($lotes).')…');
            $crudo = Groq::json(
                config('sula.groq.modelo_texto'),
                Prompts::sistema(),
                Prompts::texto($rango, self::unirTextos($textos, $paginas), $total),
                [],
                ['despiece_id' => $despiece->id, 'paso' => 'TEXTO', 'paginas' => $rango],
            );
            $doc = Normalizador::fusionar($doc, Normalizador::normalizar($crudo));
        }

        // ---------- Pasada 2: dibujos (opcional) ----------
        if ($despiece->con_dibujos) {
            $porLote = max(1, (int) config('sula.planos.imagenes_por_lote', 1));
            $grupos = array_chunk(range(1, $total), $porLote);
            foreach ($grupos as $n => $paginas) {
                $rango = self::rango($paginas);
                $avance('Revisando dibujos, páginas '.$rango.' de '.$total.' ('.($n + 1).'/'.count($grupos).')…');
                $crudo = Groq::json(
                    config('sula.groq.modelo_vision'),
                    Prompts::sistema(),
                    Prompts::dibujos($rango, mb_substr(self::unirTextos($textos, $paginas), 0, 1500), Prompts::resumenBorrador($doc, 2500)),
                    array_map(fn ($p) => $pdf->imagenBase64($p), $paginas),
                    ['despiece_id' => $despiece->id, 'paso' => 'DIBUJOS', 'paginas' => $rango],
                );
                $doc = Normalizador::fusionar($doc, Normalizador::normalizar($crudo));
            }
        }

        if (! $doc['subensambles']) {
            throw new ErrorIa('La IA no encontró subensambles ni piezas en este plano.');
        }

        // ---------- Cruce con el catálogo y guardado ----------
        $avance('Cruzando materiales con el catálogo…');
        [$diccionario, $materiales] = self::catalogo();
        $doc = Normalizador::resolver($doc, $diccionario, $materiales);

        DB::transaction(function () use ($despiece, $doc) {
            self::guardarEstructura($despiece, $doc['subensambles']);
            $despiece->update([
                'estado' => 'BORRADOR',
                'progreso' => null,
                'error' => null,
                'datos' => ['plano' => $doc['plano'], 'leyenda' => $doc['leyenda']],
            ]);
        });
    }

    /** Reemplaza los subensambles y piezas de un despiece. */
    public static function guardarEstructura(Despiece $despiece, array $subensambles): void
    {
        $despiece->subensambles()->delete(); // las piezas se borran en cascada
        foreach (array_values($subensambles) as $i => $s) {
            $sub = $despiece->subensambles()->create([
                'orden' => $i + 1,
                'nombre' => mb_strtoupper(trim($s['nombre'])) ?: Normalizador::SIN_SUBENSAMBLE,
                'cantidad' => $s['cantidad'] ?: 1,
                'pagina' => $s['pagina'] ?? null,
                'notas' => trim((string) ($s['notas'] ?? '')) ?: null,
            ]);
            foreach (array_values($s['piezas']) as $j => $p) {
                $sub->piezas()->create([
                    'orden' => $j + 1,
                    'descripcion' => trim($p['descripcion']),
                    'material_texto' => trim((string) ($p['material_texto'] ?? '')) ?: null,
                    'material_id' => $p['material_id'] ?: null,
                    'cantidad' => $p['cantidad'] ?: 1,
                    'largo_mm' => $p['largo_mm'] ?: 0,
                    'ancho_mm' => $p['ancho_mm'] ?: 0,
                    'perimetro_pintable_mm' => $p['perimetro_pintable_mm'] ?: 0,
                    'caras_pintables' => $p['caras_pintables'] ?: 0,
                    'pagina' => $p['pagina'] ?? null,
                    'notas' => trim((string) ($p['notas'] ?? '')) ?: null,
                    'avisos' => $p['avisos'] ?? [],
                ]);
            }
        }
    }

    /**
     * Diccionario para reconocer materiales: clave del catálogo (M05) y alias (MELAMINA ROBLE (SMA) 15MM).
     * @return array{0: array<string,int>, 1: array<int,array>}
     */
    public static function catalogo(): array
    {
        $mats = Material::where('activo', true)->get(['id', 'clave', 'descripcion', 'tipo_calculo', 'categoria']);
        $diccionario = [];
        $materiales = [];
        foreach ($mats as $m) {
            $diccionario[Normalizador::llaveMaterial($m->clave)] = $m->id;
            $materiales[$m->id] = ['tipo' => $m->tipo_calculo, 'categoria' => $m->categoria, 'descripcion' => $m->descripcion];
        }
        foreach (MaterialAlias::all(['alias', 'material_id']) as $a) {
            if (isset($materiales[$a->material_id])) {
                $diccionario[Normalizador::llaveMaterial($a->alias)] = $a->material_id;
            }
        }

        return [$diccionario, $materiales];
    }

    /** Agrupa páginas seguidas hasta juntar ~N caracteres de texto. Las páginas sin texto se saltan. */
    public static function lotesDeTexto(array $textos, int $maxCaracteres): array
    {
        $lotes = [];
        $actual = [];
        $tamano = 0;
        foreach ($textos as $pagina => $texto) {
            $n = mb_strlen($texto);
            if ($n === 0) {
                continue;
            }
            if ($actual && $tamano + $n > $maxCaracteres) {
                $lotes[] = $actual;
                $actual = [];
                $tamano = 0;
            }
            $actual[] = $pagina;
            $tamano += $n;
        }
        if ($actual) {
            $lotes[] = $actual;
        }

        return $lotes;
    }

    private static function unirTextos(array $textos, array $paginas): string
    {
        return implode("\n\n", array_map(fn ($p) => "--- PÁGINA $p ---\n".$textos[$p], $paginas));
    }

    private static function rango(array $paginas): string
    {
        return count($paginas) === 1 ? (string) $paginas[0] : reset($paginas).'-'.end($paginas);
    }
}
