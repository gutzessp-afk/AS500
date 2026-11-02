<?php

namespace App\Planos\Fuentes;

use InvalidArgumentException;

/** Arma la fuente correcta según el .env (o según el origen de un plano ya guardado). */
final class Fuente
{
    /** La fuente configurada en PLANOS_FUENTE (drive o local). */
    public static function actual(): FuentePlanos
    {
        return self::para(config('sula.planos.fuente'));
    }

    public static function para(string $origen): FuentePlanos
    {
        return match (strtoupper($origen)) {
            'DRIVE' => new FuenteDrive(
                config('sula.planos.drive_credenciales'),
                (string) config('sula.planos.drive_carpeta'),
                (string) config('sula.planos.drive_escribir_con', 'cuenta_conectada'),
            ),
            'LOCAL' => new FuenteLocal(config('sula.planos.carpeta_local')),
            default => throw new InvalidArgumentException("Fuente de planos desconocida: $origen (usa drive o local)"),
        };
    }
}
