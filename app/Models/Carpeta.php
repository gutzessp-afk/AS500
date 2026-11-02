<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una carpeta de planos (espejo de Drive o de la carpeta local). padre_id NULL = en la raíz. */
class Carpeta extends Model
{
    protected $table = 'carpetas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'creada_en_sistema' => 'boolean', 'sincronizado_en' => 'datetime'];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Carpeta::class, 'padre_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(Carpeta::class, 'padre_id')->where('activo', true);
    }

    public function planos(): HasMany
    {
        return $this->hasMany(Plano::class)->where('activo', true);
    }

    /** De la raíz hasta esta carpeta: [VENTO, VITRINAS] (para la "ruta de migas"). */
    public function camino(): array
    {
        $camino = [];
        $c = $this;
        $vueltas = 0;
        while ($c && $vueltas++ < 50) {
            array_unshift($camino, $c);
            $c = $c->padre;
        }

        return $camino;
    }
}
