<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Configuración general (empresa, moneda, IVA). */
class Configuracion extends Model
{
    protected $table = 'configuracion';

    protected $primaryKey = 'clave';

    public $incrementing = false;

    protected $keyType = 'string';

    const CREATED_AT = null;

    const UPDATED_AT = 'actualizado_en';

    protected $guarded = [];

    public static function valor(string $clave, ?string $porDefecto = null): ?string
    {
        return static::find($clave)?->valor ?? $porDefecto;
    }
}
