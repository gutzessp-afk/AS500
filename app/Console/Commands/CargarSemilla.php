<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan sula:semilla
 * Carga los precios reales de SULA desde datos-privados/ (carpeta fuera de git).
 */
class CargarSemilla extends Command
{
    protected $signature = 'sula:semilla {archivo=datos-privados/semilla_catalogo.sql}';

    protected $description = 'Carga materiales, precios, alias y clientes reales de SULA';

    public function handle(): int
    {
        $ruta = base_path($this->argument('archivo'));
        if (! is_file($ruta)) {
            $this->error("No existe $ruta");

            return self::FAILURE;
        }

        DB::unprepared(file_get_contents($ruta));
        $this->info('Semilla cargada: '.DB::table('materiales')->count().' materiales.');

        return self::SUCCESS;
    }
}
