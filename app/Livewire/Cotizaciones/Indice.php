<?php

namespace App\Livewire\Cotizaciones;

use App\Cotizaciones\Calculadora;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Material;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Lista de cotizaciones con el embudo comercial y el alta de una nueva. */
#[Title('Cotizaciones')]
class Indice extends Component
{
    public string $buscar = '';

    public string $estado = '';

    public bool $creando = false;

    public array $form = [];

    #[Computed]
    public function lista()
    {
        $cotizaciones = Cotizacion::with(['cliente', 'versiones'])
            ->when($this->estado, fn ($q) => $q->where('estado', $this->estado))
            ->when(trim($this->buscar), function ($q) {
                $t = '%'.trim($this->buscar).'%';
                $q->where(fn ($w) => $w->where('folio', 'ilike', $t)->orWhere('plano_codigo', 'ilike', $t)
                    ->orWhere('descripcion', 'ilike', $t)
                    ->orWhereHas('cliente', fn ($c) => $c->where('nombre', 'ilike', $t)));
            })
            ->orderByDesc('id')
            ->get();

        // Borradores: se calculan en vivo. Enviadas: el precio de su foto.
        return $cotizaciones->map(function (Cotizacion $c) {
            if ($c->esBorrador()) {
                $r = Calculadora::para($c)['resultado'];
                $c->precio_lista = $r['resumen']['precio_venta'] ?? null;
                $c->total_lista = $r['resumen']['total_pedido'] ?? null;
                $c->riesgo_lista = $r['riesgo']['nivel'] ?? null;
            } else {
                $v = $c->versiones->firstWhere('revision', $c->revision);
                $c->precio_lista = $v?->precio_venta;
                $c->total_lista = $v?->total_pedido;
                $c->riesgo_lista = null;
            }

            return $c;
        });
    }

    #[Computed]
    public function kpis(): array
    {
        $suma = fn ($estado) => $this->lista->where('estado', $estado)->sum(fn ($c) => (float) $c->total_lista);
        $aprobadas = $this->lista->where('estado', 'APROBADA')->count();
        $decididas = $aprobadas + $this->lista->where('estado', 'RECHAZADA')->count();

        return [
            'borradores' => $this->lista->where('estado', 'BORRADOR')->count(),
            'enviadas' => $suma('ENVIADA'),
            'n_enviadas' => $this->lista->where('estado', 'ENVIADA')->count(),
            'aprobadas' => $suma('APROBADA'),
            'n_aprobadas' => $aprobadas,
            'tasa' => $decididas ? round($aprobadas / $decididas * 100) : null,
        ];
    }

    public function nueva(): void
    {
        $this->form = [
            'plano_codigo' => '', 'descripcion' => '', 'plano_rev' => 'REV-00', 'cliente_id' => null,
            'dibujo' => '', 'cantidad' => 1, 'frente_m' => '', 'pintura_material_id' => null,
            'vigencia_dias' => 15, 'notas' => '',
        ];
        $this->resetErrorBag();
        $this->creando = true;
    }

    public function crear()
    {
        $datos = Editor::validarEncabezado($this, 'form');
        $c = Cotizacion::create($datos);

        return $this->redirectRoute('cotizaciones.editor', $c, navigate: true);
    }

    public function render()
    {
        return view('livewire.cotizaciones.indice', [
            'clientes' => Cliente::orderBy('nombre')->get(),
            'acabados' => Material::where('categoria', 'ACABADO')->where('activo', true)->orderBy('clave')->get(),
        ]);
    }
}
