<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto congelada de una revisión enviada: entrada y resultado del motor. */
class CotizacionVersion extends Model
{
    protected $table = 'cotizacion_versiones';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['entrada' => 'array', 'resultado' => 'array'];
    }
}
