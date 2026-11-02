<?php

namespace App\Livewire\Materiales;

use App\Models\Calibre;
use App\Models\CosteoParametro;
use App\Models\Material;
use App\Models\MaterialAlias;
use App\Models\MaterialVista;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Catálogo de materiales: lista con filtros, panel de detalle con historial
 * de precios y alias, alta y edición. Cambiar un precio NUNCA borra el anterior.
 */
#[Title('Materiales')]
class Indice extends Component
{
    // Filtros
    public string $buscar = '';

    public string $categoria = '';

    public bool $inactivos = false;

    // Panel lateral: null = cerrado; 'ver', 'editar' o 'nuevo'
    public ?string $panel = null;

    public ?int $seleccionado = null;

    // Formularios
    public array $form = [];

    public array $precio = [];

    public bool $capturandoPrecio = false;

    public string $nuevoAlias = '';

    public function mount(): void
    {
        $this->precio = $this->precioVacio();
    }

    // ---------------- Datos ----------------

    #[Computed]
    public function materiales()
    {
        return MaterialVista::query()
            ->when(! $this->inactivos, fn ($q) => $q->where('activo', true))
            ->when($this->categoria, fn ($q) => $q->where('categoria', $this->categoria))
            ->when(trim($this->buscar), function ($q) {
                $t = '%'.trim($this->buscar).'%';
                $q->where(fn ($w) => $w->where('clave', 'ilike', $t)->orWhere('descripcion', 'ilike', $t));
            })
            ->orderBy('clave')
            ->get();
    }

    #[Computed]
    public function vigencia(): int
    {
        return (int) CosteoParametro::efectivosPara(null)->vigencia_precios_dias;
    }

    #[Computed]
    public function calibres()
    {
        return Calibre::orderBy('calibre')->get();
    }

    #[Computed]
    public function detalle(): ?Material
    {
        return $this->seleccionado ? Material::with(['precios', 'alias'])->find($this->seleccionado) : null;
    }

    #[Computed]
    public function vista(): ?MaterialVista
    {
        return $this->seleccionado ? MaterialVista::find($this->seleccionado) : null;
    }

    // ---------------- Panel ----------------

    public function ver(int $id): void
    {
        $this->seleccionado = $id;
        $this->panel = 'ver';
        $this->capturandoPrecio = false;
        $this->resetErrorBag();
    }

    public function cerrar(): void
    {
        $this->panel = null;
        $this->seleccionado = null;
    }

    public function nuevo(): void
    {
        $this->resetErrorBag();
        $this->seleccionado = null;
        $this->form = [
            'clave' => '', 'descripcion' => '', 'categoria' => 'METAL', 'tipo_calculo' => 'PIEZA',
            'presentacion' => '', 'contenido' => '1', 'calibre' => null, 'modo_carga' => 'CONSUMIDO',
            'proveedor' => '', 'liga' => '', 'notas' => '', 'activo' => true,
        ];
        $this->precio = $this->precioVacio();
        $this->panel = 'nuevo';
    }

    public function editar(): void
    {
        $m = $this->detalle;
        $this->form = $m->only(['clave', 'descripcion', 'categoria', 'tipo_calculo', 'presentacion', 'contenido',
            'calibre', 'modo_carga', 'proveedor', 'liga', 'notas', 'activo']);
        $this->panel = 'editar';
    }

    // ---------------- Guardar ----------------

    public function guardarMaterial(): void
    {
        $esNuevo = $this->panel === 'nuevo';
        $esLamina = ($this->form['tipo_calculo'] ?? '') === 'AREA_KG';

        $this->validate([
            'form.clave' => $esNuevo ? ['required', 'string', 'max:20', Rule::unique('materiales', 'clave')] : [],
            'form.descripcion' => ['required', 'string', 'max:200'],
            'form.categoria' => ['required', Rule::in(Material::CATEGORIAS)],
            'form.tipo_calculo' => ['required', Rule::in(array_keys(Material::UNIDAD_POR_TIPO))],
            'form.presentacion' => ['required', 'string', 'max:80'],
            'form.contenido' => $esLamina ? [] : ['required', 'numeric', 'gt:0'],
            'form.calibre' => $esLamina ? ['required', 'exists:calibres,calibre'] : [],
            'form.modo_carga' => ['required', Rule::in(['CONSUMIDO', 'COMPLETA'])],
            'form.liga' => ['nullable', 'url'],
        ] + ($esNuevo ? $this->reglasPrecio() : []), [], [
            'form.clave' => 'clave', 'form.descripcion' => 'descripción', 'form.presentacion' => 'presentación',
            'form.contenido' => 'contenido', 'form.calibre' => 'calibre', 'precio.precio_publicado' => 'precio',
        ]);

        $datos = [
            'descripcion' => trim($this->form['descripcion']),
            'categoria' => $this->form['categoria'],
            'tipo_calculo' => $this->form['tipo_calculo'],
            'unidad' => Material::UNIDAD_POR_TIPO[$this->form['tipo_calculo']],
            'presentacion' => trim($this->form['presentacion']),
            // En lámina el contenido es el peso de la hoja según su calibre
            'contenido' => $esLamina ? Calibre::find($this->form['calibre'])->peso_hoja_4x8 : $this->form['contenido'],
            'calibre' => $esLamina ? $this->form['calibre'] : null,
            'modo_carga' => $this->form['modo_carga'],
            'proveedor' => trim($this->form['proveedor'] ?? '') ?: null,
            'liga' => trim($this->form['liga'] ?? '') ?: null,
            'notas' => trim($this->form['notas'] ?? '') ?: null,
        ];

        if ($esNuevo) {
            $material = DB::transaction(function () use ($datos) {
                $m = Material::create($datos + ['clave' => mb_strtoupper(trim($this->form['clave']))]);
                $m->precios()->create($this->datosPrecio());

                return $m;
            });
            $this->seleccionado = $material->id;
        } else {
            Material::findOrFail($this->seleccionado)->update($datos + ['activo' => (bool) $this->form['activo']]);
        }

        $this->panel = 'ver';
        unset($this->detalle, $this->vista, $this->materiales);
    }

    public function registrarPrecio(): void
    {
        $this->validate($this->reglasPrecio(), [], ['precio.precio_publicado' => 'precio']);
        Material::findOrFail($this->seleccionado)->precios()->create($this->datosPrecio());

        $this->capturandoPrecio = false;
        $this->precio = $this->precioVacio();
        unset($this->detalle, $this->vista, $this->materiales);
    }

    public function agregarAlias(): void
    {
        $alias = mb_strtoupper(trim($this->nuevoAlias));
        $this->validate(['nuevoAlias' => ['required']], [], ['nuevoAlias' => 'alias']);
        if (MaterialAlias::find($alias)) {
            $this->addError('nuevoAlias', 'Ese alias ya está asignado a otro material.');

            return;
        }
        MaterialAlias::create(['alias' => $alias, 'material_id' => $this->seleccionado]);
        $this->nuevoAlias = '';
        unset($this->detalle);
    }

    public function quitarAlias(string $alias): void
    {
        MaterialAlias::where('alias', $alias)->delete();
        unset($this->detalle);
    }

    // ---------------- Utilidades ----------------

    private function precioVacio(): array
    {
        return ['precio_publicado' => '', 'precio_por' => 'PRESENTACION', 'incluye_iva' => false, 'confianza' => 'MEDIA', 'fuente' => ''];
    }

    private function reglasPrecio(): array
    {
        return [
            'precio.precio_publicado' => ['required', 'numeric', 'gte:0'],
            'precio.precio_por' => ['required', Rule::in(['PRESENTACION', 'UNIDAD'])],
            'precio.confianza' => ['required', Rule::in(['ALTA', 'MEDIA', 'BAJA'])],
        ];
    }

    private function datosPrecio(): array
    {
        return [
            'precio_publicado' => $this->precio['precio_publicado'],
            'precio_por' => $this->precio['precio_por'],
            'incluye_iva' => (bool) $this->precio['incluye_iva'],
            'tasa_iva' => '0.16',
            'confianza' => $this->precio['confianza'],
            'fuente' => trim($this->precio['fuente'] ?? '') ?: null,
            'registrado_por' => config('sula.usuario'),
        ];
    }

    public function render()
    {
        $lista = $this->materiales;

        return view('livewire.materiales.indice', [
            'calibres' => $this->calibres,
            'kpis' => [
                ['Materiales', $lista->count(), false],
                ["Precios vencidos (> {$this->vigencia} días)", $n = $lista->filter(fn ($m) => $m->vencido($this->vigencia))->count(), $n > 0],
                ['Confianza baja', $b = $lista->where('confianza', 'BAJA')->count(), $b > 0],
                ['Sin precio', $s = $lista->whereNull('precio_id')->count(), $s > 0],
            ],
        ]);
    }
}
