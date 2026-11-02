<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La cuenta de Google conectada (solo hay una). El refresh_token se guarda cifrado. */
class ConexionGoogle extends Model
{
    protected $table = 'conexion_google';

    const CREATED_AT = 'conectado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['refresh_token'];   // nunca sale en JSON ni en dumps

    protected function casts(): array
    {
        // 'encrypted': Laravel lo cifra al guardar y lo descifra al leer (usa APP_KEY)
        return ['refresh_token' => 'encrypted', 'conectado_en' => 'datetime'];
    }

    public static function actual(): ?self
    {
        return static::latest('id')->first();
    }
}
