<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parámetros de costeo: un renglón por defecto (cliente_id NULL) y uno opcional por cliente. */
class CosteoParametro extends Model
{
    protected $table = 'costeo_parametros';

    const CREATED_AT = null;

    const UPDATED_AT = 'actualizado_en';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['overhead_incluye_mo' => 'boolean'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    /** Los del cliente si tiene propios; si no, los de SULA (cliente_id NULL). */
    public static function efectivosPara(?int $clienteId): self
    {
        return static::query()
            ->where(fn ($q) => $q->where('cliente_id', $clienteId)->orWhereNull('cliente_id'))
            ->orderByRaw('cliente_id NULLS LAST')
            ->firstOrFail();
    }
}
