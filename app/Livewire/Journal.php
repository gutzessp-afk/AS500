<?php

namespace App\Livewire;

use App\Models\Evento;
use Livewire\Attributes\Title;
use Livewire\Component;

/** El journal: historial de todos los cambios (no se puede editar ni borrar). */
#[Title('Journal')]
class Journal extends Component
{
    public string $tabla = '';

    public function render()
    {
        return view('livewire.journal', [
            'eventos' => Evento::query()
                ->when($this->tabla, fn ($q) => $q->where('tabla', $this->tabla))
                ->orderByDesc('id')
                ->limit(150)
                ->get(),
            'tablas' => Evento::query()->distinct()->orderBy('tabla')->pluck('tabla'),
        ]);
    }
}
