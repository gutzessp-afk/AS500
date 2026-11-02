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

    protected $description = 'Sincroniza carpetas y planos con Google Drive o la carpeta local';

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

        $this->table(['Carpetas', 'PDFs encontrados', 'Nuevos', 'Actualizados', 'Retirados'],
            [[$r['carpetas'], $r['total'], $r['nuevos'], $r['actualizados'], $r['retirados']]]);

        return self::SUCCESS;
    }
}
