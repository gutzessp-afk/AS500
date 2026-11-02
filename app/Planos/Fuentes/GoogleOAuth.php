<?php

namespace App\Planos\Fuentes;

use App\Models\ConexionGoogle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Conectar Google" (OAuth 2.0): el sistema trabaja en Drive COMO una persona.
 *
 * Por qué: la carpeta PLANOS vive en el Drive de una persona (Mi unidad).
 * Una cuenta de servicio puede LEER ahí, pero no puede GUARDAR archivos
 * (no tiene espacio propio). Una persona con permiso de Editor sí puede:
 * lo que se sube cuenta en su espacio, igual que si lo subiera a mano.
 *
 * Flujo:
 *   1. urlAutorizacion() → Google pregunta "¿Permites que SULA Core use tu Drive?"
 *   2. Google regresa a /google/callback con un "code" → canjear() lo cambia por un
 *      refresh_token (llave de larga duración) que se guarda CIFRADO.
 *   3. tokenDeAcceso() usa ese refresh_token para pedir tokens de 1 hora cuando haga falta.
 */
final class GoogleOAuth
{
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const PERFIL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const REVOCAR = 'https://oauth2.googleapis.com/revoke';

    // drive: leer y escribir en lo que la persona ya tiene acceso. openid email: saber quién se conectó.
    public const PERMISOS = 'https://www.googleapis.com/auth/drive openid email';

    public static function configurado(): bool
    {
        return config('sula.google.client_id') && config('sula.google.client_secret');
    }

    /** A dónde mandar a la persona para que dé permiso. $estado evita que otro sitio "finja" la respuesta. */
    public static function urlAutorizacion(string $estado): string
    {
        return self::AUTORIZAR.'?'.http_build_query([
            'client_id' => config('sula.google.client_id'),
            'redirect_uri' => config('sula.google.redirect'),
            'response_type' => 'code',
            'scope' => self::PERMISOS,
            'access_type' => 'offline',   // para recibir refresh_token
            'prompt' => 'consent',        // fuerza a Google a darlo aunque ya se haya conectado antes
            'include_granted_scopes' => 'true',
            'state' => $estado,
        ]);
    }

    /** Cambia el "code" por el refresh_token y guarda la conexión (reemplaza la anterior). */
    public static function canjear(string $codigo, ?string $quien): ConexionGoogle
    {
        $r = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $codigo,
            'client_id' => config('sula.google.client_id'),
            'client_secret' => config('sula.google.client_secret'),
            'redirect_uri' => config('sula.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);
        if ($r->failed()) {
            throw new RuntimeException('Google no aceptó la conexión: '.($r->json('error_description') ?? $r->body()));
        }
        $refresh = $r->json('refresh_token');
        if (! $refresh) {
            throw new RuntimeException('Google no regresó la llave de larga duración. Quita el acceso de "SULA Core" en myaccount.google.com/permissions y vuelve a conectar.');
        }
        if (! str_contains((string) $r->json('scope'), 'auth/drive')) {
            throw new RuntimeException('No se dio permiso de Drive. Vuelve a conectar y marca la casilla de Google Drive.');
        }

        $email = Http::withToken($r->json('access_token'))->timeout(20)->get(self::PERFIL)->json('email') ?? 'desconocido';

        ConexionGoogle::query()->delete();   // solo una cuenta conectada a la vez
        Cache::forget('drive_token_oauth');

        return ConexionGoogle::create([
            'email' => $email,
            'refresh_token' => $refresh,
            'permisos' => (string) $r->json('scope'),
            'conectado_por' => $quien,
        ]);
    }

    /** Token de 1 hora de la cuenta conectada (se reutiliza 50 min). */
    public static function tokenDeAcceso(): string
    {
        return Cache::remember('drive_token_oauth', now()->addMinutes(50), function () {
            $c = ConexionGoogle::actual();
            if (! $c) {
                throw new RuntimeException('No hay cuenta de Google conectada. En Producción dale "Conectar Google".');
            }
            $r = Http::asForm()->timeout(20)->post(self::TOKEN, [
                'client_id' => config('sula.google.client_id'),
                'client_secret' => config('sula.google.client_secret'),
                'refresh_token' => $c->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
            if ($r->json('error') === 'invalid_grant') {
                throw new RuntimeException("La conexión con Google de {$c->email} venció o se quitó. Vuelve a darle \"Conectar Google\".");
            }
            if ($r->failed()) {
                throw new RuntimeException('Google rechazó la cuenta conectada: '.($r->json('error_description') ?? $r->body()));
            }

            return $r->json('access_token');
        });
    }

    /** Desconectar: le dice a Google que anule el permiso y lo borra de aquí. */
    public static function desconectar(): void
    {
        $c = ConexionGoogle::actual();
        if ($c) {
            Http::asForm()->timeout(10)->post(self::REVOCAR, ['token' => $c->refresh_token]);
        }
        ConexionGoogle::query()->delete();
        Cache::forget('drive_token_oauth');
    }
}
