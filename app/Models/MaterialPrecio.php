<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historial de precios: nunca se sobreescribe, se agrega un renglón nuevo. */
class MaterialPrecio extends Model
{
    protected $table = 'material_precios';

    public $timestamps = false;

    // precio_sin_iva la calcula PostgreSQL (columna generada): nunca se escribe
    protected $guarded = ['id', 'precio_sin_iva'];

    protected function casts(): array
    {
        return ['incluye_iva' => 'boolean', 'vigente_desde' => 'datetime'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
