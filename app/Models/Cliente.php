<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cliente de SULA (KFC, Liverpool, PepsiCo…). */
class Cliente extends Model
{
    protected $table = 'clientes';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }
}
