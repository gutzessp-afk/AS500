<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Le dice a PostgreSQL quién está haciendo los cambios de esta petición.
 * El trigger de auditoría lo lee con current_setting('app.usuario') y lo
 * guarda en la columna "usuario" del journal.
 * (Mientras no hay login, el usuario viene de SULA_USUARIO en el .env)
 */
class FirmarJournal
{
    public function handle(Request $request, Closure $next): Response
    {
        DB::statement("SELECT set_config('app.usuario', ?, false)", [config('sula.usuario')]);

        return $next($request);
    }
}
