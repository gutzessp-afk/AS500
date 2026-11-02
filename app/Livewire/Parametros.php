<?php

namespace App\Livewire;

use App\Models\Cliente;
use App\Models\CosteoParametro;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Parámetros de costeo: los de SULA por defecto y, si se quiere, unos propios por cliente.
 * Se capturan en porcentaje "humano" (25) y se guardan como fracción (0.25).
 */
#[Title('Parámetros')]
class Parametros extends Component
{
    /** Formulario abierto: null = cerrado */
    public ?array $editando = null;

    public string $nuevoCliente = '';

    #[Computed]
    public function parametros()
    {
        return CosteoParametro::with('cliente')->orderByRaw('cliente_id NULLS FIRST')->get();
    }

    #[Computed]
    public function clientesSinPropios()
    {
        return Cliente::whereNotIn('id', CosteoParametro::whereNotNull('cliente_id')->pluck('cliente_id'))
            ->orderBy('nombre')->get();
    }

    /** Abre el formulario partiendo de un renglón existente (o de los de SULA para un cliente nuevo). */
    public function abrir(?int $clienteId): void
    {
        $base = CosteoParametro::efectivosPara($clienteId);
        $this->editando = [
            'cliente_id' => $clienteId,
            'titulo' => $clienteId ? Cliente::find($clienteId)->nombre : 'SULA · por defecto',
            'mo' => self::humano($base->mano_obra_pct),
            'overhead' => self::humano($base->overhead_pct),
            'overhead_incluye_mo' => (bool) $base->overhead_incluye_mo,
            'margen' => self::humano($base->margen_pct),
            'pintura' => (string) (float) $base->rendimiento_pintura_m2kg,
            'vigencia' => (string) $base->vigencia_precios_dias,
            'notas' => $base->cliente_id === $clienteId ? (string) $base->notas : '',
        ];
        $this->resetErrorBag();
    }

    public function guardar(): void
    {
        $this->validate([
            'editando.mo' => ['required', 'numeric', 'between:0,100'],
            'editando.overhead' => ['required', 'numeric', 'between:0,100'],
            'editando.margen' => ['required', 'numeric', 'gte:0', 'lt:100'],
            'editando.pintura' => ['required', 'numeric', 'gt:0'],
            'editando.vigencia' => ['required', 'integer', 'min:1'],
        ], [], [
            'editando.mo' => 'mano de obra', 'editando.overhead' => 'overhead', 'editando.margen' => 'margen',
            'editando.pintura' => 'rendimiento de pintura', 'editando.vigencia' => 'vigencia',
        ]);

        $e = $this->editando;
        CosteoParametro::updateOrCreate(
            ['cliente_id' => $e['cliente_id']],
            [
                'mano_obra_pct' => $e['mo'] / 100,
                'overhead_pct' => $e['overhead'] / 100,
                'overhead_incluye_mo' => (bool) $e['overhead_incluye_mo'],
                'margen_pct' => $e['margen'] / 100,
                'rendimiento_pintura_m2kg' => $e['pintura'],
                'vigencia_precios_dias' => (int) $e['vigencia'],
                'notas' => trim($e['notas']) ?: null,
            ],
        );
        $this->editando = null;
        unset($this->parametros, $this->clientesSinPropios);
    }

    /** El cliente vuelve a usar los parámetros de SULA. */
    public function quitar(int $clienteId): void
    {
        CosteoParametro::where('cliente_id', $clienteId)->delete();
        unset($this->parametros, $this->clientesSinPropios);
    }

    public function agregarCliente(): void
    {
        $this->nuevoCliente = mb_strtoupper(trim($this->nuevoCliente));
        $this->validate(['nuevoCliente' => ['required', 'max:80', Rule::unique('clientes', 'nombre')]], [], ['nuevoCliente' => 'cliente']);
        Cliente::create(['nombre' => $this->nuevoCliente]);
        $this->nuevoCliente = '';
        unset($this->clientesSinPropios);
    }

    /** Ejemplo en vivo: cuánto quedaría un mueble con $1,000 de materiales. */
    #[Computed]
    public function ejemplo(): ?array
    {
        if (! $this->editando || ! is_numeric($this->editando['margen'] ?? null) || $this->editando['margen'] >= 100) {
            return null;
        }
        $e = $this->editando;
        $mat = 1000;
        $mo = $mat * ((float) $e['mo'] / 100);
        $oh = ($e['overhead_incluye_mo'] ? $mat + $mo : $mat) * ((float) $e['overhead'] / 100);
        $costo = $mat + $mo + $oh;

        return ['mo' => $mo, 'oh' => $oh, 'costo' => $costo, 'precio' => $costo / (1 - (float) $e['margen'] / 100)];
    }

    private static function humano(mixed $fraccion): string
    {
        return (string) round((float) $fraccion * 100, 2);
    }

    public function render()
    {
        return view('livewire.parametros');
    }
}
