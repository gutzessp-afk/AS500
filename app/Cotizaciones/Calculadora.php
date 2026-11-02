<?php

namespace App\Cotizaciones;

use App\Costeo\Motor;
use App\Models\Cotizacion;
use InvalidArgumentException;

/**
 * Lo que ve la pantalla: el resultado del motor para una cotización,
 * en vivo si es borrador o congelado si ya se envió.
 */
class Calculadora
{
    /**
     * @return array{resultado: ?array, congelada: bool, origen: string, error: ?string}
     */
    public static function para(Cotizacion $c): array
    {
        if (! $c->esBorrador()) {
            [, $resultado] = ArmadorEntrada::congelada($c);

            return ['resultado' => $resultado, 'congelada' => true, 'origen' => 'CONGELADO', 'error' => null];
        }

        [$entrada, $origen] = ArmadorEntrada::enVivo($c);
        try {
            return ['resultado' => Motor::calcular($entrada), 'congelada' => false, 'origen' => $origen, 'error' => null];
        } catch (InvalidArgumentException $e) {
            return ['resultado' => null, 'congelada' => false, 'origen' => $origen, 'error' => $e->getMessage()];
        }
    }
}
