<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de cada llamada a la IA (tokens, tiempo, error). */
class IaLlamada extends Model
{
    protected $table = 'ia_llamadas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }
}
