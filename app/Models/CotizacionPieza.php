<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un renglón del despiece (por UN mueble). Cotas en mm. */
class CotizacionPieza extends Model
{
    protected $table = 'cotizacion_piezas';

    public $timestamps = false;

    protected $guarded = ['id'];
}
