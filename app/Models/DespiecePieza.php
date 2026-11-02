<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una pieza de un subensamble. La cantidad es POR SUBENSAMBLE. */
class DespiecePieza extends Model
{
    protected $table = 'despiece_piezas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['avisos' => 'array'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
