<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un material del catálogo. El precio vigente se lee de la vista v_materiales (MaterialVista). */
class Material extends Model
{
    protected $table = 'materiales';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $guarded = ['id'];

    /** La unidad de consumo sale sola del tipo de cálculo. */
    public const UNIDAD_POR_TIPO = [
        'LINEAL' => 'm', 'AREA' => 'm2', 'AREA_KG' => 'kg', 'PESO' => 'kg', 'PIEZA' => 'pza',
    ];

    public const CATEGORIAS = ['METAL', 'MADERA', 'ACABADO', 'HERRAJES', 'CONSUMIBLES', 'EMPAQUE'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'contenido' => 'decimal:4'];
    }

    public function precios(): HasMany
    {
        return $this->hasMany(MaterialPrecio::class)->orderByDesc('vigente_desde')->orderByDesc('id');
    }

    public function alias(): HasMany
    {
        return $this->hasMany(MaterialAlias::class)->orderBy('alias');
    }
}
