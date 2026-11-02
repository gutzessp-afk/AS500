<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Un renglón del journal: quién cambió qué y cuándo (solo lectura). */
class Evento extends Model
{
    protected $table = 'eventos';

    public $timestamps = false;

    protected $guarded = ['*']; // el journal solo lo escribe PostgreSQL

    protected function casts(): array
    {
        return ['ocurrido_en' => 'datetime', 'antes' => 'array', 'despues' => 'array'];
    }

    /** Para un UPDATE, solo los campos que cambiaron: ["valor: MXN → USD"]. */
    public function cambios(): array
    {
        if ($this->operacion !== 'UPDATE' || ! $this->antes || ! $this->despues) {
            return [];
        }
        $lista = [];
        foreach ($this->despues as $campo => $nuevo) {
            $viejo = $this->antes[$campo] ?? null;
            if ($viejo !== $nuevo) {
                $lista[] = $campo.': '.json_encode($viejo, JSON_UNESCAPED_UNICODE).' → '.json_encode($nuevo, JSON_UNESCAPED_UNICODE);
            }
        }
        return $lista;
    }
}
