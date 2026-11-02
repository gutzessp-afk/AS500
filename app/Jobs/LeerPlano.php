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
