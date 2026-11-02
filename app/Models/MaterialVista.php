<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Vista v_materiales: cada material con su precio vigente ya convertido a $ por unidad y por presentación. */
class MaterialVista extends Model
{
    protected $table = 'v_materiales';

    public $timestamps = false;

    protected $guarded = ['*']; // es una vista: solo lectura

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'incluye_iva' => 'boolean', 'vigente_desde' => 'datetime'];
    }

    /** ¿El precio vigente ya pasó su vigencia? */
    public function vencido(int $vigencia): bool
    {
        return $this->dias_antiguedad !== null && $this->dias_antiguedad > $vigencia;
    }
}
