<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "GABINETE ×3": cuántos lleva un mueble y qué piezas tiene. */
class DespieceSubensamble extends Model
{
    protected $table = 'despiece_subensambles';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function piezas(): HasMany
    {
        return $this->hasMany(DespiecePieza::class, 'subensamble_id')->orderBy('orden')->orderBy('id');
    }
}
