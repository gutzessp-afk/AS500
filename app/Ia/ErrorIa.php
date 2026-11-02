<?php

namespace App\Ia;

use RuntimeException;

/** La IA no respondió o respondió algo que no sirve. El mensaje se le muestra al usuario. */
class ErrorIa extends RuntimeException {}
