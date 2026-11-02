<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Una versión del despiece de un plano: subensambles → piezas. */
class Despiece extends Model
{
    protected $table = 'despieces';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'con_dibujos' => 'boolean', 'aprobado_en' => 'datetime'];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function subensambles(): HasMany
    {
        return $this->hasMany(DespieceSubensamble::class)->orderBy('orden')->orderBy('id');
    }

    /** Todas las piezas, pasando por sus subensambles. */
    public function piezas(): HasManyThrough
    {
        return $this->hasManyThrough(DespiecePieza::class, DespieceSubensamble::class, 'despiece_id', 'subensamble_id');
    }

    public function esEditable(): bool
    {
        return $this->estado === 'BORRADOR';
    }
}
