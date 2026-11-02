<?php

namespace App\Livewire\Cotizaciones;

use App\Costeo\Dec;
use App\Costeo\Motor;
use App\Cotizaciones\ArmadorEntrada;
use App\Cotizaciones\Calculadora;
use App\Cotizaciones\ErrorCotizacion;
use App\Cotizaciones\Flujo;
use App\Cotizaciones\Importador;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Material;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Editor de una cotización, con pestañas:
 * Despiece · Compras · Resumen · Simulador · Versiones · Datos
 */
class Editor extends Component
{
    public Cotizacion $cotizacion;

    public string $pestana = 'despiece';

    public ?string $error = null;

    // Despiece editable (se guarda completo con "Guardar y calcular")
    public array $filas = [];

    // Pegar desde Excel
    public bool $pegando = false;

    public string $textoPegado = '';

    public ?array $importacion = null;

    // Encabezado (pestaña Datos)
    public array $datos = [];

    // Simulador "¿y si…?"
    public string $simMargen = '';

    public string $simMo = '';

    public string $simOh = '';

    public int $simCantidad = 1;

    public int $simMetal = 0;

    public int $simMadera = 0;

    public int $simHerrajes = 0;

    public string $precioCliente = '';

    // Versiones: revisión contra la que se compara
    public ?int $comparar = null;

    public function mount(Cotizacion $cotizacion): void
    {
        $this->cotizacion = $cotizacion;
        $this->recargar();
    }

    /** Vuelve a leer todo de la base de datos después de un cambio. */
    private function recargar(): void
    {
        $this->cotizacion->refresh()->load(['piezas', 'ajustes', 'versiones', 'cliente']);
        unset($this->calculo);

        $this->filas = $this->cotizacion->piezas->map(fn ($p) => [
            'subensamble' => (string) $p->subensamble,
            'descripcion' => $p->descripcion,
            'material_id' => $p->material_id,
            'cantidad' => Dec::d4(Dec::de($p->cantidad)),
            'largo_mm' => Dec::d4(Dec::de($p->largo_mm)),
            'ancho_mm' => Dec::d4(Dec::de($p->ancho_mm)),
            'perimetro_pintable_mm' => Dec::d4(Dec::de($p->perimetro_pintable_mm)),
            'caras_pintables' => Dec::d4(Dec::de($p->caras_pintables)),
            'notas' => (string) $p->notas,
        ])->all();

        $this->datos = $this->cotizacion->only(['plano_codigo', 'descripcion', 'plano_rev', 'cliente_id', 'dibujo',
            'cantidad', 'pintura_material_id', 'vigencia_dias', 'notas']);
        $this->datos['frente_m'] = $this->cotizacion->frente_m ? Dec::d4(Dec::de($this->cotizacion->frente_m)) : '';

        $this->reiniciarSimulador();
    }

    // ---------------- Datos calculados ----------------

    /** Resultado del motor: en vivo (borrador) o la foto (enviada). */
    #[Computed]
    public function calculo(): array
    {
        return Calculadora::para($this->cotizacion);
    }

    #[Computed]
    public function materiales()
    {
        return Material::where('activo', true)->orderBy('clave')->get(['id', 'clave', 'descripcion', 'tipo_calculo', 'categoria'])->keyBy('id');
    }

    // ---------------- Acciones de estado ----------------

    /** Ejecuta una acción del flujo y muestra el error de negocio si lo hay. */
    private function intentar(callable $accion): void
    {
        $this->error = null;
        try {
            $accion();
            $this->recargar();
        } catch (ErrorCotizacion $e) {
            $this->error = $e->getMessage();
        }
    }

    public function enviar(): void
    {
        $this->intentar(fn () => Flujo::enviar($this->cotizacion));
    }

    public function decidir(string $estado): void
    {
        $this->intentar(fn () => Flujo::decidir($this->cotizacion, $estado));
    }

    public function abrirRevision(): void
    {
        $this->intentar(fn () => Flujo::abrirRevision($this->cotizacion));
    }

    public function duplicar()
    {
        $nueva = Flujo::duplicar($this->cotizacion);

        return $this->redirectRoute('cotizaciones.editor', $nueva, navigate: true);
    }

    // ---------------- Despiece ----------------

    public function agregarFila(): void
    {
        $ultima = end($this->filas) ?: null;
        $this->filas[] = [
            'subensamble' => $ultima['subensamble'] ?? '', 'descripcion' => '',
            'material_id' => $ultima['material_id'] ?? $this->materiales->keys()->first(),
            'cantidad' => '1', 'largo_mm' => '0', 'ancho_mm' => '0',
            'perimetro_pintable_mm' => '0', 'caras_pintables' => '0', 'notas' => '',
        ];
    }

    public function quitarFila(int $i): void
    {
        unset($this->filas[$i]);
        $this->filas = array_values($this->filas);
    }

    public function duplicarFila(int $i): void
    {
        array_splice($this->filas, $i + 1, 0, [$this->filas[$i]]);
    }

    public function descartarCambios(): void
    {
        $this->recargar();
    }

    public function guardarPiezas(): void
    {
        $this->validate([
            'filas.*.descripcion' => ['required', 'string', 'max:200'],
            'filas.*.material_id' => ['required', 'integer', 'exists:materiales,id'],
            'filas.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'filas.*.largo_mm' => ['nullable', 'numeric', 'gte:0'],
            'filas.*.ancho_mm' => ['nullable', 'numeric', 'gte:0'],
            'filas.*.perimetro_pintable_mm' => ['nullable', 'numeric', 'gte:0'],
            'filas.*.caras_pintables' => ['nullable', 'numeric', 'gte:0'],
        ], [
            'filas.*.descripcion.required' => 'Pieza :position: falta la descripción.',
            'filas.*.cantidad.gt' => 'Pieza :position: la cantidad debe ser mayor a 0.',
        ]);
        $this->intentar(fn () => Flujo::guardarPiezas($this->cotizacion, $this->filas));
    }

    public function revisarPegado(): void
    {
        $this->importacion = Importador::importar($this->textoPegado);
    }

    public function aplicarPegado(bool $reemplazar): void
    {
        $nuevas = $this->importacion['piezas'] ?? [];
        $this->filas = $reemplazar ? $nuevas : array_merge($this->filas, $nuevas);
        $this->pegando = false;
        $this->textoPegado = '';
        $this->importacion = null;
    }

    // ---------------- Compras ----------------

    /** Guarda plan de corte, merma o modo de un material (se llama al salir del campo). */
    public function guardarAjuste(int $materialId, string $campo, string $valor): void
    {
        $compra = collect($this->calculo['resultado']['compras'] ?? [])->firstWhere('material_id', $materialId);
        if (! $compra) {
            return;
        }
        $plan = $compra['plan_corte'];
        $merma = $compra['merma_pct'];
        $modo = $compra['modo_carga'];

        match ($campo) {
            'plan' => $plan = trim($valor) === '' ? null : $valor,
            'merma' => $merma = (string) (((float) $valor) / 100),
            'modo' => $modo = $valor,
        };

        if ($plan !== null && ! is_numeric($plan)) {
            $this->error = 'El plan de corte debe ser un número.';

            return;
        }
        $this->intentar(fn () => Flujo::guardarAjuste($this->cotizacion, $materialId, $plan, $merma, $modo, $compra['nota'] ?: null));
    }

    public function quitarAjuste(int $materialId): void
    {
        $this->intentar(fn () => Flujo::quitarAjuste($this->cotizacion, $materialId));
    }

    // ---------------- Datos (encabezado) ----------------

    /** Valida el encabezado de una cotización (lo usan Editor e Indice). */
    public static function validarEncabezado(Component $c, string $campo): array
    {
        $datos = $c->validate([
            "$campo.plano_codigo" => ['required', 'string', 'max:60'],
            "$campo.descripcion" => ['required', 'string', 'max:200'],
            "$campo.plano_rev" => ['nullable', 'string', 'max:20'],
            "$campo.cliente_id" => ['nullable', 'exists:clientes,id'],
            "$campo.dibujo" => ['nullable', 'string', 'max:80'],
            "$campo.cantidad" => ['required', 'integer', 'min:1'],
            "$campo.frente_m" => ['nullable', 'numeric', 'gt:0'],
            "$campo.pintura_material_id" => ['nullable', 'exists:materiales,id'],
            "$campo.vigencia_dias" => ['required', 'integer', 'min:1'],
            "$campo.notas" => ['nullable', 'string'],
        ], [], [
            "$campo.plano_codigo" => 'código del plano', "$campo.descripcion" => 'descripción',
            "$campo.cantidad" => 'cantidad', "$campo.frente_m" => 'frente', "$campo.vigencia_dias" => 'vigencia',
        ])[$campo];

        return [
            'plano_codigo' => mb_strtoupper(trim($datos['plano_codigo'])),
            'descripcion' => trim($datos['descripcion']),
            'plano_rev' => mb_strtoupper(trim($datos['plano_rev'] ?? '')) ?: 'REV-00',
            'cliente_id' => $datos['cliente_id'] ?: null,
            'dibujo' => trim($datos['dibujo'] ?? '') ?: null,
            'cantidad' => (int) $datos['cantidad'],
            'frente_m' => ($datos['frente_m'] ?? '') === '' ? null : $datos['frente_m'],
            'pintura_material_id' => $datos['pintura_material_id'] ?: null,
            'vigencia_dias' => (int) $datos['vigencia_dias'],
            'notas' => trim($datos['notas'] ?? '') ?: null,
        ];
    }

    public function guardarDatos(): void
    {
        if (! $this->cotizacion->esBorrador()) {
            $this->error = 'Abre una nueva revisión para cambiar los datos.';

            return;
        }
        $this->cotizacion->update(self::validarEncabezado($this, 'datos'));
        $this->recargar();
        $this->pestana = 'despiece';
    }

    // ---------------- Simulador ----------------

    public function reiniciarSimulador(): void
    {
        $p = $this->calculo['resultado']['parametros'] ?? null;
        $this->simMargen = $p ? (string) round((float) $p['margen_pct'] * 100, 2) : '30';
        $this->simMo = $p ? (string) round((float) $p['mano_obra_pct'] * 100, 2) : '25';
        $this->simOh = $p ? (string) round((float) $p['overhead_pct'] * 100, 2) : '15';
        $this->simCantidad = (int) $this->cotizacion->cantidad;
        $this->simMetal = $this->simMadera = $this->simHerrajes = 0;
    }

    /** Corre el motor con los "¿y si…?" sin guardar nada. */
    #[Computed]
    public function simulacion(): ?array
    {
        if (! $this->cotizacion->piezas->count() || ! is_numeric($this->simMargen) || (float) $this->simMargen >= 100) {
            return null;
        }
        [$e] = $this->cotizacion->esBorrador()
            ? ArmadorEntrada::enVivo($this->cotizacion)
            : ArmadorEntrada::congelada($this->cotizacion);

        try {
            $base = Motor::calcular($e);
            $e->parametros->margenPct = Dec::de((float) $this->simMargen / 100);
            $e->parametros->manoObraPct = Dec::de((float) $this->simMo / 100);
            $e->parametros->overheadPct = Dec::de((float) $this->simOh / 100);
            $e->cantidad = max(1, $this->simCantidad);
            $variacion = ['METAL' => $this->simMetal, 'MADERA' => $this->simMadera, 'HERRAJES' => $this->simHerrajes];
            foreach ($e->materiales as $m) {
                if (! empty($variacion[$m->categoria])) {
                    $m->precioPublicado = $m->precioPublicado->multipliedBy(Dec::de(1 + $variacion[$m->categoria] / 100));
                }
            }

            return ['base' => $base, 'sim' => Motor::calcular($e)];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    // ---------------- Versiones ----------------

    #[Computed]
    public function comparacion(): ?array
    {
        $actual = $this->calculo['resultado'] ?? null;
        $opciones = $this->cotizacion->versiones
            ->reject(fn ($v) => ! $this->cotizacion->esBorrador() && $v->revision === $this->cotizacion->revision);
        $rev = $this->comparar ?? $opciones->first()?->revision;
        $version = $opciones->firstWhere('revision', $rev);
        if (! $actual || ! $version) {
            return null;
        }

        $antes = collect($version->resultado['compras'])->keyBy('clave');
        $ahora = collect($actual['compras'])->keyBy('clave');
        $materiales = $antes->keys()->merge($ahora->keys())->unique()
            ->map(fn ($clave) => [
                'clave' => $clave,
                'descripcion' => ($ahora[$clave] ?? $antes[$clave])['descripcion'],
                'a' => (float) ($antes[$clave]['costo_por_mueble'] ?? 0),
                'b' => (float) ($ahora[$clave]['costo_por_mueble'] ?? 0),
            ])
            ->filter(fn ($f) => abs($f['b'] - $f['a']) >= 0.01)
            ->sortByDesc(fn ($f) => abs($f['b'] - $f['a']))
            ->values();

        return [
            'revision' => $rev,
            'opciones' => $opciones->pluck('revision'),
            'resumen' => $version->resultado['resumen'],
            'actual' => $actual['resumen'],
            'materiales' => $materiales,
        ];
    }

    public function render()
    {
        // Las pestañas son @include: reciben sus datos aquí (solo se calcula la pestaña abierta)
        return view('livewire.cotizaciones.editor', [
            'clientes' => Cliente::orderBy('nombre')->get(),
            'acabados' => Material::where('categoria', 'ACABADO')->where('activo', true)->orderBy('clave')->get(),
            'mats' => $this->materiales,
            'sim' => $this->pestana === 'simulador' ? $this->simulacion : null,
            'cmp' => $this->pestana === 'versiones' ? $this->comparacion : null,
        ])->title($this->cotizacion->folio);
    }
}
