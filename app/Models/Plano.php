<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Un PDF de plano en Drive (o en la carpeta local). El PDF no vive en la base de datos. */
class Plano extends Model
{
    protected $table = 'planos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'subido_en_sistema' => 'boolean', 'modificado_en_origen' => 'datetime', 'sincronizado_en' => 'datetime'];
    }

    public function carpetaPadre(): BelongsTo
    {
        return $this->belongsTo(Carpeta::class, 'carpeta_id');
    }

    public function despieces(): HasMany
    {
        return $this->hasMany(Despiece::class)->orderByDesc('version');
    }

    /** El despiece aprobado (si hay). */
    public function aprobado(): HasOne
    {
        return $this->hasOne(Despiece::class)->where('estado', 'APROBADO');
    }

    /** El despiece más reciente, sea cual sea su estado. */
    public function ultimo(): HasOne
    {
        return $this->hasOne(Despiece::class)->latestOfMany('version');
    }
}
