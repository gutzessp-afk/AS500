<!-- SPEC SULA Core · MÓDULO 3 · PARTE 2 DE 5 -->
> **PARTE 2 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte2.md` y responde solo "recibida 2/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Planos/Sincronizador.php`

**NUEVO.**

- Compara lo que hay en la fuente contra la tabla: agrega nuevos, actualiza los que cambiaron (huella o nombre) y marca `activo = false` los que desaparecieron.
- Todo dentro de una **transacción**: o se guarda todo, o nada.
- Las variables con `&` (`&$nuevos`) se pasan "por referencia" para que la función de adentro pueda sumarles.

````php
<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\FuentePlanos;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día la tabla "planos" con lo que hay en la fuente (Drive o local).
 *   - Archivos nuevos → se agregan.
 *   - Archivos que cambiaron → se actualizan (nombre, huella, fecha).
 *   - Archivos que ya no están → activo = false (no se borran: pueden tener despiece).
 */
class Sincronizador
{
    /** @return array{nuevos: int, actualizados: int, retirados: int, total: int} */
    public static function sincronizar(FuentePlanos $fuente): array
    {
        $archivos = $fuente->listar();
        $origen = $fuente->origen();
        $patron = config('sula.planos.patron_clave') ?: NombreArchivo::PATRON_CLAVE;

        $existentes = Plano::where('origen', $origen)->get()->keyBy('archivo_id');
        $vistos = [];
        $nuevos = $actualizados = 0;

        DB::transaction(function () use ($archivos, $origen, $patron, $existentes, &$vistos, &$nuevos, &$actualizados) {
            foreach ($archivos as $a) {
                $vistos[$a->id] = true;
                $datos = [
                    'nombre' => $a->nombre,
                    'clave' => NombreArchivo::clave($a->nombre, $patron),
                    'revision' => NombreArchivo::revision($a->nombre),
                    'carpeta' => $a->carpeta,
                    'tamano_bytes' => $a->tamano,
                    'huella' => $a->huella,
                    'modificado_en_origen' => $a->modificado,
                    'activo' => true,
                    'sincronizado_en' => now(),
                ];

                $plano = $existentes[$a->id] ?? null;
                if (! $plano) {
                    Plano::create($datos + ['origen' => $origen, 'archivo_id' => $a->id]);
                    $nuevos++;
                } elseif ($plano->huella !== $a->huella || $plano->nombre !== $a->nombre || ! $plano->activo) {
                    $plano->update($datos);
                    $actualizados++;
                } else {
                    $plano->update(['sincronizado_en' => now()]);
                }
            }
        });

        // Los que ya no aparecen se marcan como retirados
        $retirados = Plano::where('origen', $origen)->where('activo', true)
            ->whereNotIn('archivo_id', array_keys($vistos) ?: [''])
            ->update(['activo' => false]);

        return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'retirados' => $retirados, 'total' => count($archivos)];
    }
}
````

### `app/Planos/AlmacenPdf.php`

**NUEVO.**

- La primera vez baja el PDF de Drive a `storage/app/private/planos/` (fuera de git y fuera de `public`). Las siguientes veces lo lee de ahí: el visor abre al instante.
- El nombre incluye la huella: si el plano cambia en Drive, se baja la versión nueva y se borra la vieja.
- Revisa que lo descargado empiece con `%PDF` (que sí sea un PDF).

````php
<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\Fuente;

/**
 * Copia local de los PDFs (storage/app/private/planos, fuera de git).
 * Se baja de Drive la primera vez y se reutiliza mientras el archivo no cambie
 * (si cambia la "huella" en Drive, se vuelve a bajar).
 */
class AlmacenPdf
{
    public static function ruta(Plano $plano): string
    {
        $dir = storage_path('app/private/planos/'.strtolower($plano->origen));
        $prefijo = sha1($plano->archivo_id);
        $ruta = "$dir/$prefijo-".substr(sha1((string) $plano->huella), 0, 12).'.pdf';

        if (is_file($ruta)) {
            return $ruta;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Borra copias viejas de este mismo plano
        foreach (glob("$dir/$prefijo-*.pdf") ?: [] as $vieja) {
            @unlink($vieja);
        }

        $bytes = Fuente::para($plano->origen)->descargar($plano->archivo_id);
        if (! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('Lo que llegó de la fuente no es un PDF.');
        }
        file_put_contents($ruta, $bytes);

        return $ruta;
    }
}
````

### `app/Planos/ExtractorPdf.php`

**NUEVO.**

- `Process::run([...])` ejecuta programas de Poppler **sin pasar por el shell** (cada argumento va separado), así un nombre de archivo raro no puede inyectar comandos.
- `pdftotext -layout`: respeta columnas; las tablas del plano salen legibles.
- `pdftoppm -jpeg -scale-to 1600`: imagen de la página con el lado mayor de 1600 px (~300 KB), suficiente para leer cotas.
- `finally` borra los archivos temporales aunque algo falle.

````php
<?php

namespace App\Planos;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Saca texto e imágenes de las páginas de un PDF con Poppler
 * (pdfinfo, pdftotext, pdftoppm). En Arch: sudo pacman -S poppler
 *
 * Los planos de SolidWorks traen texto (tablas, materiales, cotas),
 * así que leer el texto es rápido y barato. Lo que solo está dibujado
 * se manda como imagen a la IA de visión.
 */
class ExtractorPdf
{
    public function __construct(private string $ruta) {}

    public function paginas(): int
    {
        $salida = $this->correr(['pdfinfo', $this->ruta]);
        if (! preg_match('/^Pages:\s+(\d+)/m', $salida, $m)) {
            throw new RuntimeException('No se pudo leer el número de páginas del PDF.');
        }

        return (int) $m[1];
    }

    /** Texto de una página respetando columnas (-layout), sin renglones vacíos repetidos. */
    public function texto(int $pagina): string
    {
        $t = $this->correr(['pdftotext', '-layout', '-f', $pagina, '-l', $pagina, $this->ruta, '-']);
        $t = preg_replace('/[ \t]+$/m', '', $t);

        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    /** Imagen JPEG de una página (lado mayor 1600 px), en base64 lista para la IA. */
    public function imagenBase64(int $pagina): string
    {
        $base = tempnam(sys_get_temp_dir(), 'plano');
        try {
            $this->correr(['pdftoppm', '-jpeg', '-jpegopt', 'quality=80', '-scale-to', '1600',
                '-f', $pagina, '-l', $pagina, '-singlefile', $this->ruta, $base]);

            return base64_encode(file_get_contents("$base.jpg"));
        } finally {
            @unlink($base);
            @unlink("$base.jpg");
        }
    }

    private function correr(array $comando): string
    {
        $r = Process::timeout(120)->run(array_map('strval', $comando));
        if ($r->failed()) {
            $err = trim($r->errorOutput());
            if (str_contains($err, 'not found') || $r->exitCode() === 127) {
                throw new RuntimeException("Falta Poppler ({$comando[0]}). Instálalo: sudo pacman -S poppler");
            }
            throw new RuntimeException("{$comando[0]} falló: $err");
        }

        return $r->output();
    }
}
````

## 8. LA IA

### `app/Ia/ErrorIa.php`

**NUEVO.**

Excepción propia: así se distingue un error de la IA (que se le muestra al usuario) de un error de programación.

````php
<?php

namespace App\Ia;

use RuntimeException;

/** La IA no respondió o respondió algo que no sirve. El mensaje se le muestra al usuario. */
class ErrorIa extends RuntimeException {}
````

### `app/Ia/Groq.php`

**NUEVO.**

- Groq usa el **mismo formato que OpenAI** (`/openai/v1/chat/completions`), así que basta el cliente HTTP de Laravel.
- `response_format: json_object` obliga al modelo a responder JSON. `temperature 0.1` lo hace consistente (menos "creativo").
- **Con imágenes**, las instrucciones se pegan al texto del usuario porque algunos modelos de visión no aceptan mensaje de sistema junto con imágenes.
- **Reintentos:** 429 (límite por minuto) → espera lo que diga `retry-after` (máx. 65 s); `json_validate_failed` → reintenta; sin conexión → espera y reintenta. Máximo 4 intentos.
- **413** = la petición no cabe en tu plan: el mensaje dice qué variable bajar.
- Cada intento queda en `ia_llamadas` (tokens, milisegundos, error).
- `sinMarkdown()`: algunos modelos envuelven el JSON en ```` ```json ````; se lo quitamos.

````php
<?php

namespace App\Ia;

use App\Models\IaLlamada;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de Groq (API compatible con OpenAI).
 *
 *  - Pide la respuesta en JSON ("json_object") y la regresa ya decodificada.
 *  - Si Groq dice 429 (límite por minuto), espera lo que indique y reintenta.
 *  - Registra cada llamada en ia_llamadas (tokens, tiempo, error).
 *
 * La API key y los modelos vienen del .env; los modelos de Groq cambian seguido,
 * por eso NO están escritos en el código.
 */
class Groq
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const INTENTOS = 4;

    /**
     * @param  string  $sistema  instrucciones fijas
     * @param  string  $usuario  el texto a analizar
     * @param  string[]  $imagenes  JPEGs en base64 (solo modelos con visión)
     * @param  array  $bitacora  ['despiece_id' => …, 'paso' => 'TEXTO', 'paginas' => '1-3']
     */
    public static function json(string $modelo, string $sistema, string $usuario, array $imagenes = [], array $bitacora = []): array
    {
        $clave = config('sula.groq.api_key');
        if (! $clave) {
            throw new ErrorIa('Falta GROQ_API_KEY en el .env.');
        }

        $mensajes = [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $usuario]];
        if ($imagenes) {
            // Mensaje "multimodal": texto + imágenes en data URL.
            // Algunos modelos de visión no aceptan mensaje de sistema junto con imágenes,
            // por eso las instrucciones van pegadas al inicio del texto.
            $contenido = [['type' => 'text', 'text' => $sistema."\n\n".$usuario]];
            foreach ($imagenes as $img) {
                $contenido[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$img]];
            }
            $mensajes = [['role' => 'user', 'content' => $contenido]];
        }

        $cuerpo = [
            'model' => $modelo,
            'temperature' => 0.1,   // bajo = respuestas más consistentes, menos "creativas"
            // Lo máximo que puede contestar. Ojo: Groq lo cuenta contra tu límite de tokens por minuto.
            'max_tokens' => (int) ($imagenes ? config('sula.groq.max_tokens_vision', 2000) : config('sula.groq.max_tokens', 3000)),
            'response_format' => ['type' => 'json_object'],
            'messages' => $mensajes,
        ];

        $ultimoError = 'sin respuesta';
        for ($intento = 1; $intento <= self::INTENTOS; $intento++) {
            $inicio = hrtime(true);
            try {
                $r = Http::withToken($clave)->acceptJson()->timeout(180)->post(self::URL, $cuerpo);
            } catch (ConnectionException $e) {
                $ultimoError = 'No hay conexión con Groq: '.$e->getMessage();
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);
                sleep(3 * $intento);

                continue;
            }

            // 429 = te pasaste del límite por minuto: esperar y reintentar
            if ($r->status() === 429) {
                $espera = min(65, max(2, (int) ceil((float) ($r->header('retry-after') ?: 10 * $intento))));
                $ultimoError = "Límite de Groq alcanzado (429), se esperó {$espera}s";
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);
                sleep($espera);

                continue;
            }

            // 400 json_validate_failed = el modelo escribió JSON roto: se vuelve a intentar
            if ($r->status() === 400 && $r->json('error.code') === 'json_validate_failed') {
                $ultimoError = 'La IA escribió un JSON inválido';
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);

                continue;
            }

            if ($r->failed()) {
                $mensaje = $r->json('error.message') ?? $r->body();
                self::registrar($bitacora, $modelo, $inicio, false, "HTTP {$r->status()}: $mensaje");
                // 413 = la petición es más grande de lo que permite tu plan
                $pista = $r->status() === 413 ? ' (la petición es muy grande para tu plan de Groq: baja PLANOS_CARACTERES_POR_LOTE o PLANOS_IMAGENES_POR_LOTE)' : '';
                throw new ErrorIa("Groq respondió {$r->status()}: $mensaje$pista");
            }

            $texto = $r->json('choices.0.message.content') ?? '';
            $datos = json_decode(self::sinMarkdown($texto), true);
            self::registrar($bitacora, $modelo, $inicio, is_array($datos), is_array($datos) ? null : 'JSON inválido',
                (int) $r->json('usage.prompt_tokens', 0), (int) $r->json('usage.completion_tokens', 0));

            if (is_array($datos)) {
                return $datos;
            }
            $ultimoError = 'La IA no regresó un JSON válido';
        }

        throw new ErrorIa("Groq no respondió bien después de ".self::INTENTOS." intentos: $ultimoError");
    }

    /** Algunos modelos envuelven el JSON en ```json … ``` aunque se les pida que no. */
    public static function sinMarkdown(string $texto): string
    {
        $texto = trim($texto);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $texto, $m)) {
            return $m[1];
        }

        return $texto;
    }

    private static function registrar(array $b, string $modelo, int $inicio, bool $ok, ?string $error, int $entrada = 0, int $salida = 0): void
    {
        IaLlamada::create([
            'despiece_id' => $b['despiece_id'] ?? null,
            'paso' => $b['paso'] ?? 'OTRO',
            'paginas' => $b['paginas'] ?? null,
            'modelo' => $modelo,
            'tokens_entrada' => $entrada,
            'tokens_salida' => $salida,
            'milisegundos' => (int) ((hrtime(true) - $inicio) / 1_000_000),
            'ok' => $ok,
            'error' => $error,
        ]);
    }
}
````

### `app/Planos/Prompts.php`

**NUEVO.**

- `FORMA`: el JSON exacto que esperamos. Darle un ejemplo al modelo es lo que más mejora la respuesta.
- `REGLAS`: lo que un dibujante de SULA sabe: renglones con "VARIOS" son subensambles, cantidad por subensamble, leyenda de materiales, mm, **null si no lo ves**.
- `resumenBorrador()`: en la pasada de dibujos no se reenvía todo el JSON, solo un resumen corto, para gastar menos tokens.
- Si la IA se equivoca seguido en algo, **aquí se ajusta** sin tocar la lógica.

````php
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
````

### `app/Planos/Normalizador.php`

**NUEVO.**

Es la pieza que hace que "salga correcto". **No toca la base de datos**, por eso tiene pruebas unitarias.
- `normalizar()`: acepta variaciones de la IA (`piezas` o `componentes`, `"1,800"`, `"37 mm"`, `"VARIOS"` → null) y deja todo en una forma fija. Números como **texto** (nunca `float`).
- `fusionar()`: junta dos lecturas. **Lo primero manda**: de la segunda solo se toma lo que falta o lo nuevo. Compara nombres sin acentos ni mayúsculas ("Gabinete" = "GABINETE").
- `resolver()`: código de leyenda → nombre (ML2 → MELAMINA ROBLE (SMA) 25MM) → material del catálogo (por clave o alias; también tolera espacios: "C.11" = "C. 11"). Pone avisos:
  - 🔴 material que no está en el catálogo · falta largo (tubo) · faltan largo y ancho (lámina, melamina) · cantidad no leída
  - ⚪ pintura estimada: tubo cuadrado 1" → 4 × 25.4 = **101.6 mm** (igual que el Excel); lámina → 2 caras
- `explotar()`: subensamble × pieza = cantidad **por mueble**, con `BigDecimal` (sin errores de redondeo).

````php
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
            if (is_array($h) && ($pieza = self::pieza($h + ['material' => $h['material'] ?? $h['nombre'] ?? $h['descripcion'] ?? '']))) {
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
            if ($sub['cantidad'] === null || (float) $sub['cantidad'] <= 0) {
                $doc['subensambles'][$si]['cantidad'] = '1';
            }
            foreach ($sub['piezas'] as $pi => $p) {
                $doc['subensambles'][$si]['piezas'][$pi] = self::resolverPieza($p, $doc['leyenda'] ?? [], $diccionario, $compacto, $materiales);
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
            $avisos[] = ['nivel' => 'error', 'texto' => 'No se leyó la cantidad: se puso 1.'];
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
````
