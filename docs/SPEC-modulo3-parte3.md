<!-- SPEC SULA Core · MÓDULO 3 · PARTE 3 DE 5 -->
> **PARTE 3 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte3.md` y responde solo "recibida 3/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Planos/LectorPlano.php`

**NUEVO.**

- Paso a paso: baja el PDF → texto de cada página → **lotes** de páginas (~5,000 caracteres para caber en el plan gratis) → Groq → `fusionar` lote por lote → (opcional) imágenes, una página por llamada → `resolver` con el catálogo → guarda.
- `$avance(...)` escribe en `despieces.progreso` lo que va haciendo; la pantalla lo muestra en vivo.
- Si el PDF no trae texto (un escaneo), avisa que se use "revisar también los dibujos".
- `catalogo()`: arma el diccionario clave/alias → id. También lo usa `FlujoDespiece::guardar`.
- `guardarEstructura()`: reemplaza subensambles y piezas del despiece (borrar + insertar dentro de la transacción).

````php
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
````

## 9. REGLAS, COLA Y COMANDO

### `app/Planos/ErrorDespiece.php`

**NUEVO.**

Igual que `ErrorCotizacion` del Módulo 2: errores de negocio con mensaje para el usuario.

````php
<?php

namespace App\Planos;

use RuntimeException;

/** Regla de negocio del despiece que no se cumple. El mensaje se le muestra al usuario. */
class ErrorDespiece extends RuntimeException {}
````

### `app/Planos/FlujoDespiece.php`

**NUEVO.**

- `leerConIa()`: crea la versión en `LEYENDO` y **manda el trabajo a la cola** (`LeerPlano::dispatch`). Regresa al instante.
- `guardar()`: limpia lo que capturaste, vuelve a calcular avisos con el catálogo y reemplaza la estructura.
- `aprobar()`: bloquea si hay rojos; **registra alias** de lo que elegiste a mano; pasa el aprobado anterior a `REEMPLAZADO` (en ese orden, si no el índice único de la BD truena).
- `cotizar()`: crea la cotización en BORRADOR y le mete las piezas **explotadas** usando `Flujo::guardarPiezas` del Módulo 2 (se reutiliza, no se duplica).
- `nuevaVersion()` usa `Despiece::where(...)->max('version')` sin `orderBy` (PostgreSQL no permite ORDER BY en un MAX sin GROUP BY).

````php
<?php

namespace App\Planos;

use App\Cotizaciones\Flujo;
use App\Jobs\LeerPlano;
use App\Models\Cotizacion;
use App\Models\Despiece;
use App\Models\MaterialAlias;
use App\Models\Plano;
use Illuminate\Support\Facades\DB;

/**
 * Reglas del despiece de un plano:
 *   leerConIa → (LEYENDO) → BORRADOR → guardar… → aprobar → APROBADO → cotizar
 *   nuevoManual / copiar → BORRADOR
 */
class FlujoDespiece
{
    /** Crea una versión nueva en LEYENDO y manda el trabajo a la cola. */
    public static function leerConIa(Plano $plano, bool $conDibujos): Despiece
    {
        if ($plano->despieces()->where('estado', 'LEYENDO')->exists()) {
            throw new ErrorDespiece('Este plano ya se está leyendo. Espera a que termine.');
        }
        if (! config('sula.groq.api_key')) {
            throw new ErrorDespiece('Falta GROQ_API_KEY en el .env.');
        }

        $d = self::nuevaVersion($plano, ['estado' => 'LEYENDO', 'origen' => 'IA', 'con_dibujos' => $conDibujos, 'progreso' => 'En la fila…']);
        LeerPlano::dispatch($d->id);

        return $d;
    }

    /** Despiece vacío para capturar a mano. */
    public static function nuevoManual(Plano $plano): Despiece
    {
        return DB::transaction(function () use ($plano) {
            $d = self::nuevaVersion($plano, ['estado' => 'BORRADOR', 'origen' => 'MANUAL']);
            $d->subensambles()->create(['orden' => 1, 'nombre' => Normalizador::SIN_SUBENSAMBLE, 'cantidad' => 1]);

            return $d;
        });
    }

    /** Copia un despiece (por ejemplo el aprobado) a una versión nueva en BORRADOR. */
    public static function copiar(Despiece $origen): Despiece
    {
        return DB::transaction(function () use ($origen) {
            $d = self::nuevaVersion($origen->plano, ['estado' => 'BORRADOR', 'origen' => 'COPIA', 'datos' => $origen->datos]);
            foreach ($origen->subensambles()->with('piezas')->get() as $s) {
                $nuevo = $d->subensambles()->create($s->only(['orden', 'nombre', 'cantidad', 'pagina', 'notas']));
                foreach ($s->piezas as $p) {
                    $nuevo->piezas()->create($p->only(['orden', 'descripcion', 'material_texto', 'material_id', 'cantidad',
                        'largo_mm', 'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'pagina', 'notas', 'avisos']));
                }
            }

            return $d;
        });
    }

    /**
     * Guarda lo que el usuario editó. Vuelve a revisar cada pieza (avisos) con el catálogo.
     * @param  array  $subensambles  como los arma la pantalla: [['nombre','cantidad','piezas'=>[…, 'pintura']]]
     */
    public static function guardar(Despiece $d, array $subensambles): void
    {
        if (! $d->esEditable()) {
            throw new ErrorDespiece('Solo se edita un despiece en BORRADOR. Usa "Nueva versión" para cambiar uno aprobado.');
        }

        $limpio = [];
        foreach ($subensambles as $s) {
            $piezas = [];
            foreach ($s['piezas'] ?? [] as $p) {
                if (trim((string) ($p['descripcion'] ?? '')) === '') {
                    continue; // renglón vacío
                }
                $piezas[] = [
                    'descripcion' => trim($p['descripcion']),
                    'material_texto' => Normalizador::llaveMaterial($p['material_texto'] ?? ''),
                    'material_id' => ($p['material_id'] ?? '') !== '' ? (int) $p['material_id'] : null,
                    'cantidad' => Normalizador::numero((string) ($p['cantidad'] ?? '')),
                    'largo_mm' => Normalizador::numero((string) ($p['largo_mm'] ?? '')),
                    'ancho_mm' => Normalizador::numero((string) ($p['ancho_mm'] ?? '')),
                    'pintura' => Normalizador::numero((string) ($p['pintura'] ?? '')) ?? '0',
                    'pagina' => is_numeric($p['pagina'] ?? null) ? (int) $p['pagina'] : null,
                    'notas' => trim((string) ($p['notas'] ?? '')),
                ];
            }
            $limpio[] = [
                'nombre' => mb_strtoupper(trim((string) ($s['nombre'] ?? ''))) ?: Normalizador::SIN_SUBENSAMBLE,
                'cantidad' => Normalizador::numero((string) ($s['cantidad'] ?? '')),
                'pagina' => is_numeric($s['pagina'] ?? null) ? (int) $s['pagina'] : null,
                'notas' => trim((string) ($s['notas'] ?? '')),
                'piezas' => $piezas,
            ];
        }

        [$diccionario, $materiales] = LectorPlano::catalogo();
        $doc = Normalizador::resolver(['leyenda' => $d->datos['leyenda'] ?? [], 'subensambles' => $limpio], $diccionario, $materiales);

        DB::transaction(function () use ($d, $doc) {
            LectorPlano::guardarEstructura($d, $doc['subensambles']);
            $d->touch();
        });
    }

    /**
     * Aprueba el despiece. Requisitos: sin avisos de error.
     * Además "aprende": cada material del plano que se resolvió a mano queda como alias.
     */
    public static function aprobar(Despiece $d): void
    {
        if (! $d->esEditable()) {
            throw new ErrorDespiece('Solo se aprueba un despiece en BORRADOR.');
        }
        $piezas = $d->piezas()->get();
        if ($piezas->isEmpty()) {
            throw new ErrorDespiece('El despiece no tiene piezas.');
        }
        $conError = $piezas->filter(fn ($p) => collect($p->avisos)->contains('nivel', 'error'))->count();
        if ($conError) {
            throw new ErrorDespiece("Hay {$conError} pieza(s) con avisos en rojo. Corrígelas y guarda antes de aprobar.");
        }

        DB::transaction(function () use ($d, $piezas) {
            foreach ($piezas as $p) {
                $alias = Normalizador::llaveMaterial($p->material_texto ?? '');
                if ($alias !== '' && $p->material_id && ! MaterialAlias::find($alias)) {
                    MaterialAlias::create(['alias' => $alias, 'material_id' => $p->material_id]);
                }
            }
            Despiece::where('plano_id', $d->plano_id)->where('estado', 'APROBADO')->update(['estado' => 'REEMPLAZADO']);
            $d->update(['estado' => 'APROBADO', 'aprobado_en' => now(), 'aprobado_por' => config('sula.usuario')]);
        });
    }

    /** Crea una cotización (BORRADOR) con el despiece aprobado ya "explotado" por mueble. */
    public static function cotizar(Despiece $d, ?int $clienteId, int $cantidad): Cotizacion
    {
        if ($d->estado !== 'APROBADO') {
            throw new ErrorDespiece('Solo se cotiza un despiece APROBADO.');
        }
        if ($cantidad < 1) {
            throw new ErrorDespiece('La cantidad de muebles debe ser al menos 1.');
        }

        $subensambles = $d->subensambles()->with('piezas')->get()->map(fn ($s) => [
            'nombre' => $s->nombre,
            'cantidad' => (string) $s->cantidad,
            'piezas' => $s->piezas->map(fn ($p) => $p->only(['descripcion', 'material_id', 'cantidad', 'largo_mm',
                'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'notas']))->all(),
        ])->all();

        return DB::transaction(function () use ($d, $clienteId, $cantidad, $subensambles) {
            $plano = $d->plano;
            $c = Cotizacion::create([
                'plano_codigo' => $plano->clave,
                'descripcion' => $d->datos['plano']['descripcion'] ?? $plano->nombre,
                'plano_rev' => $plano->revision,
                'cliente_id' => $clienteId,
                'cantidad' => $cantidad,
                'notas' => "Desde el despiece v{$d->version} del plano {$plano->nombre}",
            ]);
            Flujo::guardarPiezas($c, Normalizador::explotar($subensambles));

            return $c;
        });
    }

    private static function nuevaVersion(Plano $plano, array $datos): Despiece
    {
        $version = (int) Despiece::where('plano_id', $plano->id)->max('version') + 1;

        return $plano->despieces()->create($datos + ['version' => $version]);
    }
}
````

### `app/Jobs/LeerPlano.php`

**NUEVO.**

- `ShouldQueue` = no se ejecuta en la petición, sino en `php artisan queue:work`.
- `$timeout = 900` (15 min) y `$tries = 1`: si falla, tú decides si lo vuelves a leer (no se gasta IA sola).
- En la cola no corre el middleware del journal: por eso firma a mano como `ia-groq`.
- `failed()`: si el trabajo muere (por tiempo), deja el despiece en `ERROR` para que no se quede "Leyendo…" para siempre.

````php
<?php

namespace App\Jobs;

use App\Models\Despiece;
use App\Planos\LectorPlano;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trabajo en segundo plano: leer un plano con la IA.
 * Lo ejecuta "php artisan queue:work" (otra terminal), así la pantalla
 * no se congela mientras Groq trabaja; la pantalla solo va preguntando el avance.
 */
class LeerPlano implements ShouldQueue
{
    use Queueable;

    /** Máximo 15 minutos (planos grandes + esperas por el límite de Groq). */
    public int $timeout = 900;

    /** Un solo intento: si falla, el usuario decide si lo vuelve a leer. */
    public int $tries = 1;

    public function __construct(public int $despieceId) {}

    public function handle(): void
    {
        // En la cola no pasa el middleware: firmamos el journal a mano
        DB::statement("SELECT set_config('app.usuario', ?, false)", ['ia-groq']);

        $despiece = Despiece::with('plano')->find($this->despieceId);
        if (! $despiece || $despiece->estado !== 'LEYENDO') {
            return;
        }

        try {
            LectorPlano::leer($despiece);
        } catch (Throwable $e) {
            $despiece->update(['estado' => 'ERROR', 'progreso' => null, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e); // queda también en storage/logs/laravel.log
        }
    }

    /** Laravel lo llama si el trabajo muere (por ejemplo, se pasó del tiempo). */
    public function failed(?Throwable $e): void
    {
        Despiece::where('id', $this->despieceId)->where('estado', 'LEYENDO')->update([
            'estado' => 'ERROR',
            'progreso' => null,
            'error' => 'La lectura se detuvo: '.mb_substr($e?->getMessage() ?? 'tiempo agotado', 0, 900),
        ]);
    }
}
````

### `app/Console/Commands/SincronizarPlanos.php`

**NUEVO.**

`php artisan sula:planos` (o `--fuente=local`). Úsalo para la **primera** sincronización con Drive: en la terminal no hay límite de 30 s como en el navegador.

````php
<?php

namespace App\Console\Commands;

use App\Planos\Fuentes\Fuente;
use App\Planos\Sincronizador;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan sula:planos
 * Lee la carpeta de planos (Drive o local, según PLANOS_FUENTE) y pone al día el índice.
 */
class SincronizarPlanos extends Command
{
    protected $signature = 'sula:planos {--fuente= : drive o local (por defecto la del .env)}';

    protected $description = 'Sincroniza el índice de planos con Google Drive o la carpeta local';

    public function handle(): int
    {
        try {
            $fuente = $this->option('fuente') ? Fuente::para($this->option('fuente')) : Fuente::actual();
            $this->info('Leyendo planos de '.$fuente->origen().'…');
            $r = Sincronizador::sincronizar($fuente);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['PDFs encontrados', 'Nuevos', 'Actualizados', 'Retirados'], [[$r['total'], $r['nuevos'], $r['actualizados'], $r['retirados']]]);

        return self::SUCCESS;
    }
}
````

### `app/Http/Controllers/PdfPlanoController.php`

**NUEVO.**

- `__invoke`: controlador de una sola acción.
- Solo sirve planos que están en la tabla (Laravel busca el `{plano}` por id; si no existe, 404). Nadie puede usar la ruta para bajar cualquier archivo de tu Drive.
- `inline` = el navegador lo muestra con su visor; `?descargar=1` = lo descarga.

````php
<?php

namespace App\Http\Controllers;

use App\Models\Plano;
use App\Planos\AlmacenPdf;
use Throwable;

/**
 * GET /planos/{plano}/pdf            → muestra el PDF en el navegador
 * GET /planos/{plano}/pdf?descargar=1 → lo descarga
 * Solo sirve planos que están en la tabla (nadie puede pedir cualquier archivo de Drive).
 */
class PdfPlanoController
{
    public function __invoke(Plano $plano)
    {
        try {
            $ruta = AlmacenPdf::ruta($plano);
        } catch (Throwable $e) {
            abort(502, 'No se pudo traer el plano: '.$e->getMessage());
        }

        $nombre = str_replace('"', '', $plano->nombre);
        $modo = request()->boolean('descargar') ? 'attachment' : 'inline';

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$modo; filename=\"$nombre\"",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
````

### `config/sula.php`

**REEMPLAZA** (versión 0.4.0).

Todo lo de planos y Groq sale del `.env` con valores por defecto seguros. **Nada secreto vive aquí**: solo lee variables.

````php
<?php

// Configuración propia de SULA Core
return [
    'version' => '0.4.0',

    // Quién firma los cambios en el journal (temporal, hasta tener login)
    'usuario' => env('SULA_USUARIO', 'desarrollo'),

    // Módulo 3: de dónde salen los planos
    'planos' => [
        'fuente' => env('PLANOS_FUENTE', 'local'),                         // drive o local
        'drive_credenciales' => env('PLANOS_DRIVE_CREDENCIALES', 'datos-privados/drive-service-account.json'),
        'drive_carpeta' => env('PLANOS_DRIVE_CARPETA'),                    // id de la carpeta raíz en Drive
        'carpeta_local' => env('PLANOS_CARPETA_LOCAL', base_path('datos-privados/planos')),
        'patron_clave' => env('PLANOS_PATRON_CLAVE'),                      // vacío = 2-1-0072-0003
        // Tamaño de cada envío a la IA. Los valores por defecto caben en el plan GRATIS de Groq
        // (6,000 tokens por minuto). Con plan de pago puedes subirlos (12000 y 3).
        'caracteres_por_lote' => (int) env('PLANOS_CARACTERES_POR_LOTE', 5000),
        'imagenes_por_lote' => (int) env('PLANOS_IMAGENES_POR_LOTE', 1),
    ],

    // IA: Groq (API compatible con OpenAI)
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'modelo_texto' => env('GROQ_MODELO_TEXTO', 'llama-3.3-70b-versatile'),
        'modelo_vision' => env('GROQ_MODELO_VISION', 'qwen/qwen3.8-27b'),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 3000),
        'max_tokens_vision' => (int) env('GROQ_MAX_TOKENS_VISION', 2000),
    ],
];
````

### `routes/web.php`

**REEMPLAZA.**

Dos pantallas Livewire y una ruta normal para el PDF. `/planos/{plano}/pdf` no choca con `/planos/{plano}` porque tiene un segmento más.

````php
<?php

use App\Http\Controllers\PdfPlanoController;
use App\Livewire\Cotizaciones\Editor as EditorCotizacion;
use App\Livewire\Cotizaciones\Indice as Cotizaciones;
use App\Livewire\Inicio;
use App\Livewire\Journal;
use App\Livewire\Materiales\Indice as Materiales;
use App\Livewire\Parametros;
use App\Livewire\Planos\Indice as Planos;
use App\Livewire\Planos\Ver as VerPlano;
use Illuminate\Support\Facades\Route;

// Cada pantalla es un componente Livewire de página completa
Route::livewire('/', Inicio::class)->name('inicio');
Route::livewire('/journal', Journal::class)->name('journal');
Route::livewire('/materiales', Materiales::class)->name('materiales');
Route::livewire('/parametros', Parametros::class)->name('parametros');
Route::livewire('/cotizaciones', Cotizaciones::class)->name('cotizaciones');
Route::livewire('/cotizaciones/{cotizacion}', EditorCotizacion::class)->name('cotizaciones.editor');

// Módulo 3: planos y despiece con IA
Route::livewire('/planos', Planos::class)->name('planos');
Route::livewire('/planos/{plano}', VerPlano::class)->name('planos.ver');
Route::get('/planos/{plano}/pdf', PdfPlanoController::class)->name('planos.pdf');
````

## 10. PANTALLAS

### `resources/views/layouts/app.blade.php`

**CAMBIO PUNTUAL** (no reemplazar el archivo). En el arreglo `$menu`, agregar la sección **Ingeniería** antes de **Ingeniería de costos**:

**BUSCAR:**
````php
        'Ingeniería de costos' => [
````

**REEMPLAZAR:**
````php
        'Ingeniería' => [
            ['planos', 'Planos y despiece', 'PLN'],
        ],
        'Ingeniería de costos' => [
````

### `resources/views/components/chip-despiece.blade.php`

**NUEVO.**

Componente anónimo de Blade: `<x-chip-despiece :despiece="$d" />`. `match` elige texto y color según el estado.

````blade
{{-- Estado del despiece de un plano. Uso: <x-chip-despiece :despiece="$d" /> --}}
@props(['despiece' => null])
@php
    [$texto, $clase] = match ($despiece?->estado) {
        null => ['Sin despiece', 'border-borde text-tenue'],
        'LEYENDO' => ['Leyendo…', 'border-alerta text-alerta animate-pulse'],
        'BORRADOR' => ['En revisión', 'border-alerta text-alerta'],
        'APROBADO' => ['Aprobado', 'border-ok text-ok'],
        'ERROR' => ['Error de IA', 'border-sula text-sula'],
        default => [$despiece->estado, 'border-borde text-tenue'],
    };
@endphp
<span {{ $attributes->class(['chip', $clase]) }}>
    {{ $texto }}@if ($despiece) <span class="opacity-60">v{{ $despiece->version }}</span>@endif
</span>
````

### `app/Livewire/Planos/Indice.php`

**NUEVO.**

- `#[Url(as: 'q')]`: lo que buscas queda en la dirección (`/planos?q=0072`) y se puede compartir o recargar.
- Busca **cada palabra** por separado: "vitrina 0072" encuentra "2-1-0072-0003 VITRINA…".
- `limit(200)`: con miles de planos la tabla no se vuelve lenta; se afina escribiendo más.

````php
<?php

namespace App\Livewire\Planos;

use App\Models\IaLlamada;
use App\Models\Plano;
use App\Planos\Fuentes\Fuente;
use App\Planos\Sincronizador;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/** Buscador de planos por clave o nombre, con el estado de su despiece. */
#[Title('Planos')]
class Indice extends Component
{
    // #[Url] = el texto buscado queda en la dirección (?q=0072) y se puede compartir
    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $filtro = '';   // '', SIN, BORRADOR, APROBADO

    public ?string $mensaje = null;

    public ?string $error = null;

    #[Computed]
    public function planos()
    {
        return Plano::query()
            ->where('activo', true)
            ->with(['aprobado', 'ultimo'])
            ->when(trim($this->buscar), function ($q) {
                // Busca cada palabra por separado: "vitrina 0072" encuentra "2-1-0072-0003 VITRINA…"
                foreach (preg_split('/\s+/', trim($this->buscar)) as $palabra) {
                    $t = '%'.$palabra.'%';
                    $q->where(fn ($w) => $w->where('clave', 'ilike', $t)->orWhere('nombre', 'ilike', $t)->orWhere('carpeta', 'ilike', $t));
                }
            })
            ->when($this->filtro === 'SIN', fn ($q) => $q->whereDoesntHave('despieces'))
            ->when($this->filtro === 'APROBADO', fn ($q) => $q->whereHas('aprobado'))
            ->when($this->filtro === 'BORRADOR', fn ($q) => $q->whereDoesntHave('aprobado')->whereHas('despieces'))
            ->orderBy('clave')
            ->orderByDesc('revision')
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function kpis(): array
    {
        $activos = Plano::where('activo', true);

        return [
            'planos' => (clone $activos)->count(),
            'aprobados' => (clone $activos)->whereHas('aprobado')->count(),
            'tokens_mes' => (int) IaLlamada::where('creado_en', '>=', now()->startOfMonth())->sum(DB::raw('tokens_entrada + tokens_salida')),
            'ultima_sync' => Plano::max('sincronizado_en'),
        ];
    }

    public function sincronizar(): void
    {
        $this->mensaje = $this->error = null;
        try {
            $r = Sincronizador::sincronizar(Fuente::actual());
            $this->mensaje = "{$r['total']} PDFs revisados · {$r['nuevos']} nuevos · {$r['actualizados']} actualizados · {$r['retirados']} retirados.";
        } catch (Throwable $e) {
            $this->error = 'No se pudo sincronizar: '.$e->getMessage();
        }
        unset($this->planos, $this->kpis);
    }

    public function render()
    {
        return view('livewire.planos.indice', ['fuente' => strtoupper(config('sula.planos.fuente'))]);
    }
}
````

### `resources/views/livewire/planos/indice.blade.php`

**NUEVO.**

`wire:model.live.debounce.250ms`: busca mientras escribes, pero espera 250 ms de pausa para no consultar en cada tecla.

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="max-w-7xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="font-mono text-xs tracking-widest text-tenue">PLN · PLANOS</p>
            <h1 class="mt-1 text-3xl font-semibold">Planos y despiece</h1>
            <p class="mt-2 max-w-xl text-sm text-tenue">
                Busca la clave, abre el plano y arma su despiece (subensambles y piezas) con la IA.
                Un despiece aprobado se puede mandar a cotizar con un clic.
            </p>
        </div>
        <button class="boton" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar">
            <span wire:loading.remove wire:target="sincronizar">↻ Sincronizar {{ $fuente }}</span>
            <span wire:loading wire:target="sincronizar">Leyendo {{ $fuente }}…</span>
        </button>
    </div>

    @if ($mensaje)<div class="mt-4 border-l-2 border-ok bg-ok/10 px-4 py-3 text-sm">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mt-4">{{ $error }}</div>@endif

    @php($k = $this->kpis)
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Planos</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ number_format($k['planos']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Con despiece aprobado</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold text-ok">{{ number_format($k['aprobados']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Tokens de IA este mes</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ number_format($k['tokens_mes']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Última sincronización</p>
            <p class="cifras mt-2 font-mono text-sm">{{ $k['ultima_sync'] ? F::fecha($k['ultima_sync']) : 'Nunca' }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-md font-mono" placeholder="Clave o nombre: 0072, vitrina, exhibidor…" autofocus
               wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (['' => 'Todos', 'SIN' => 'Sin despiece', 'BORRADOR' => 'En revisión', 'APROBADO' => 'Aprobados'] as $valor => $nombre)
                <button wire:click="$set('filtro', '{{ $valor }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $filtro === $valor, 'border-borde text-tenue hover:text-texto' => $filtro !== $valor])>
                    {{ $nombre }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Clave</th>
                    <th class="px-4 py-3">Archivo</th>
                    <th class="px-4 py-3">Carpeta</th>
                    <th class="px-4 py-3">Rev.</th>
                    <th class="px-4 py-3">Despiece</th>
                    <th class="px-4 py-3">Modificado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-borde">
                @forelse ($this->planos as $p)
                    <tr wire:key="plano-{{ $p->id }}" class="hover:bg-panel">
                        <td class="px-4 py-3 font-mono font-semibold whitespace-nowrap">
                            <a href="{{ route('planos.ver', $p) }}" wire:navigate class="hover:text-sula">{{ $p->clave }}</a>
                        </td>
                        <td class="min-w-64 px-4 py-3">
                            <a href="{{ route('planos.ver', $p) }}" wire:navigate class="line-clamp-1 hover:text-sula">{{ $p->nombre }}</a>
                        </td>
                        <td class="px-4 py-3 text-xs text-tenue">{{ $p->carpeta ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $p->revision }}</td>
                        <td class="px-4 py-3">
                            <x-chip-despiece :despiece="$p->aprobado ?? $p->ultimo" />
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-tenue">
                            {{ $p->modificado_en_origen ? F::fecha($p->modificado_en_origen) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-tenue">
                            @if ($k['planos'] === 0)
                                Todavía no hay planos. Dale a <span class="font-mono">Sincronizar</span> o corre
                                <span class="font-mono">php artisan sula:planos</span>.
                            @else
                                Ningún plano coincide con la búsqueda.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->planos->count() === 200)
        <p class="mt-2 font-mono text-xs text-tenue">Se muestran los primeros 200: escribe más de la clave para afinar.</p>
    @endif
</section>
</div>
````
