<?php

namespace App\Planos;

use RuntimeException;

/** Regla de negocio del despiece que no se cumple. El mensaje se le muestra al usuario. */
class ErrorDespiece extends RuntimeException {}
