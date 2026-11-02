<?php

namespace App\Http\Controllers;

use App\Planos\Fuentes\GoogleOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Conectar Google":
 *   GET  /google/conectar     → manda a la pantalla de permiso de Google
 *   GET  /google/callback     → Google regresa aquí con el código; se guarda la conexión
 *   POST /google/desconectar  → quita el permiso
 */
class GoogleController
{
    public function conectar(Request $request)
    {
        if (! GoogleOAuth::configurado()) {
            return redirect()->route('produccion')->with('error', 'Faltan GOOGLE_OAUTH_CLIENT_ID y GOOGLE_OAUTH_CLIENT_SECRET en el .env.');
        }
        // Google regresa a GOOGLE_OAUTH_REDIRECT: la sesión debe nacer en ese mismo dominio (localhost, no 127.0.0.1)
        $redirect = (string) config('sula.google.redirect');
        if ($request->getHost() !== parse_url($redirect, PHP_URL_HOST)) {
            return redirect()->away(str_replace('/google/callback', '/google/conectar', $redirect));
        }
        // "estado" aleatorio guardado en la sesión: al regresar se compara (protege contra CSRF)
        $estado = Str::random(40);
        $request->session()->put('google_estado', $estado);

        return redirect()->away(GoogleOAuth::urlAutorizacion($estado));
    }

    public function callback(Request $request)
    {
        $esperado = $request->session()->pull('google_estado');
        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state'))) {
            return redirect()->route('produccion')->with('error', 'La respuesta de Google no coincide. Intenta conectar otra vez.');
        }
        if ($request->query('error')) {
            return redirect()->route('produccion')->with('error', 'No se dio permiso en Google ('.$request->query('error').').');
        }

        try {
            $c = GoogleOAuth::canjear((string) $request->query('code'), config('sula.usuario'));
        } catch (Throwable $e) {
            return redirect()->route('produccion')->with('error', $e->getMessage());
        }

        return redirect()->route('produccion')->with('mensaje', "Google conectado como {$c->email}. Ya puedes crear carpetas y subir PDFs.");
    }

    public function desconectar()
    {
        GoogleOAuth::desconectar();

        return redirect()->route('produccion')->with('mensaje', 'Se desconectó la cuenta de Google.');
    }
}
