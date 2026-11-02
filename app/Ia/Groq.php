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
