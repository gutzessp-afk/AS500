<?php

namespace App\Livewire;

use App\Models\Cotizacion;
use App\Models\Evento;
use App\Models\Material;
use App\Models\Plano;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Inicio: qué hay en el sistema y a dónde ir. Se refresca sola cada 30 s (wire:poll). */
#[Title('Inicio')]
class Inicio extends Component
{
    public function render()
    {
        try {
            DB::select('SELECT 1');
            $bd = true;
        } catch (\Throwable) {
            $bd = false;
        }

        return view('livewire.inicio', [
            'bd' => $bd,
            'planos' => $bd ? Plano::where('activo', true)->count() : 0,
            'borradores' => $bd ? Cotizacion::where('estado', 'BORRADOR')->count() : 0,
            'materiales' => $bd ? Material::where('activo', true)->count() : 0,
            'eventosHoy' => $bd ? Evento::whereDate('ocurrido_en', today())->count() : 0,
        ]);
    }
}
