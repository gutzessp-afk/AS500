<?php

namespace App\Cotizaciones;

use RuntimeException;

/** Una regla de negocio impide la acción (ej. enviar sin despiece). El mensaje se muestra tal cual. */
class ErrorCotizacion extends RuntimeException {}
