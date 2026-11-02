<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una cotización = un plano (con su revisión) × cantidad de muebles. */
class Cotizacion extends Model
{
    protected $table = 'cotizaciones';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    // El folio lo pone PostgreSQL (COT-2026-0001)
    protected $guarded = ['id', 'folio'];

    protected function casts(): array
    {
        return ['enviada_en' => 'datetime', 'decidida_en' => 'datetime', 'frente_m' => 'decimal:3'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function piezas(): HasMany
    {
        return $this->hasMany(CotizacionPieza::class)->orderBy('orden')->orderBy('id');
    }

    public function ajustes(): HasMany
    {
        return $this->hasMany(CotizacionAjuste::class);
    }

    public function versiones(): HasMany
    {
        return $this->hasMany(CotizacionVersion::class)->orderByDesc('revision');
    }

    public function esBorrador(): bool
    {
        return $this->estado === 'BORRADOR';
    }

    /** Enviada y ya pasó su vigencia. */
    public function estaVencida(): bool
    {
        return $this->estado === 'ENVIADA' && $this->enviada_en
            && $this->enviada_en->copy()->addDays($this->vigencia_dias)->isPast();
    }
}
