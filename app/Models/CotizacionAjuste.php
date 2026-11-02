<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Plan de corte, merma y modo de carga de un material dentro de una cotización. */
class CotizacionAjuste extends Model
{
    protected $table = 'cotizacion_ajustes';

    // Llave compuesta (cotizacion_id, material_id): se guarda con upsert(), nunca con save()
    public $incrementing = false;

    protected $primaryKey = null;

    public $timestamps = false;

    protected $guarded = [];
}
