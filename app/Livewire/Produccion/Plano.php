<?php

namespace App\Livewire\Produccion;

use App\Costeo\Dec;
use App\Models\Cliente;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\Plano as PlanoModelo;
use App\Planos\ErrorDespiece;
use App\Planos\FlujoDespiece;
use App\Planos\Normalizador;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Un plano: visor grande del PDF + botón "Despiece" que entrega el despiece en PDF.
 *   - Si el despiece no existe, la IA lo lee (en la cola) y al terminar se abre el PDF solo.
 *   - "Corregir piezas" abre el editor (corregir lo rojo, aprobar, cotizar).
 */
class Plano extends Component
{
    public PlanoModelo $plano;

    // Versión del despiece que se está viendo (?v=2 en la dirección)
    #[Url(as: 'v')]
    public ?int $version = null;

    // Copia editable del despiece: [['nombre','cantidad','pagina','notas','piezas'=>[…]]]
    public array $subs = [];

    public bool $conDibujos = false;

    public bool $verPdf = true;

    // Ventana con el PDF del despiece
    public bool $verDespiece = false;

    // Datos del pedido que salen en el PDF (no se guardan: solo arman el PDF)
    public $pdfCantidad = 1;   // sin tipo: si borran el campo no truena (se toma 1)

    public string $pdfPedido = '';

    public string $pdfEntrega = '';

    // Editor de piezas (escondido; se abre con "Corregir piezas")
    public bool $corrigiendo = false;

    // Último estado que vio la pantalla: para darse cuenta cuando la IA termina
    public ?string $estadoVisto = null;

    public ?string $error = null;

    public ?string $mensaje = null;

    // Mandar a cotizar
    public bool $cotizando = false;

    public ?int $clienteId = null;

    public int $cantidad = 1;

    public function mount(PlanoModelo $plano): void
    {
        $this->plano = $plano;
        $this->cargar();
    }

    // ---------------- Datos ----------------

    /** El despiece que se está viendo: la versión pedida, o el aprobado, o el último. */
    #[Computed]
    public function despiece(): ?Despiece
    {
        $q = $this->plano->despieces();
        $d = $this->version ? (clone $q)->where('version', $this->version)->first() : null;

        return $d ?? (clone $q)->where('estado', 'APROBADO')->first() ?? $q->first();
    }

    #[Computed]
    public function versiones()
    {
        return $this->plano->despieces()->get(['id', 'version', 'estado', 'origen', 'creado_en']);
    }

    #[Computed]
    public function materiales()
    {
        return Material::where('activo', true)->orderBy('clave')
            ->get(['id', 'clave', 'descripcion', 'tipo_calculo', 'categoria', 'unidad'])->keyBy('id');
    }

    /** Totales por material para UN mueble (la "explosión"). Solo piezas con material. */
    #[Computed]
    public function explosion(): array
    {
        $porMaterial = [];
        foreach ($this->subs as $s) {
            $veces = Dec::de(Normalizador::numero((string) $s['cantidad']) ?? '1');
            foreach ($s['piezas'] as $p) {
                $m = $this->materiales->get((int) ($p['material_id'] ?? 0));
                if (! $m) {
                    continue;
                }
                $cant = Dec::de(Normalizador::numero((string) $p['cantidad']) ?? '0')->multipliedBy($veces);
                $largo = Dec::de(Normalizador::numero((string) $p['largo_mm']) ?? '0');
                $ancho = Dec::de(Normalizador::numero((string) $p['ancho_mm']) ?? '0');
                $f = $porMaterial[$m->id] ??= ['material' => $m, 'piezas' => Dec::de(0), 'medida' => Dec::de(0)];
                $f['piezas'] = $f['piezas']->plus($cant);
                $medida = match ($m->tipo_calculo) {
                    'LINEAL' => Dec::div($largo, Dec::de(1000))->multipliedBy($cant),                          // m
                    'AREA', 'AREA_KG' => Dec::div($largo->multipliedBy($ancho), Dec::de(1000000))->multipliedBy($cant), // m²
                    default => Dec::de(0),
                };
                $f['medida'] = $f['medida']->plus($medida);
                $porMaterial[$m->id] = $f;
            }
        }
        ksort($porMaterial);

        return array_map(fn ($f) => [
            'material' => $f['material'],
            'piezas' => Dec::d4($f['piezas']),
            'medida' => Dec::d4($f['medida']),
            'unidad' => match ($f['material']->tipo_calculo) { 'LINEAL' => 'm', 'AREA', 'AREA_KG' => 'm²', default => '' },
        ], array_values($porMaterial));
    }

    /** Copia el despiece de la base de datos al arreglo editable. */
    private function cargar(): void
    {
        unset($this->despiece, $this->versiones, $this->explosion);
        $d = $this->despiece;
        $this->version = $d?->version;
        $this->estadoVisto = $d?->estado;
        $this->subs = [];
        if (! $d) {
            return;
        }

        foreach ($d->subensambles()->with('piezas')->get() as $s) {
            $this->subs[] = [
                'nombre' => $s->nombre,
                'cantidad' => Dec::d4(Dec::de($s->cantidad)),
                'pagina' => $s->pagina,
                'notas' => (string) $s->notas,
                'piezas' => $s->piezas->map(fn ($p) => [
                    'descripcion' => $p->descripcion,
                    'material_texto' => (string) $p->material_texto,
                    'material_id' => $p->material_id,
                    'cantidad' => Dec::d4(Dec::de($p->cantidad)),
                    'largo_mm' => Dec::d4(Dec::de($p->largo_mm)),
                    'ancho_mm' => Dec::d4(Dec::de($p->ancho_mm)),
                    // Una sola columna "pintura": perímetro (tubo) o caras (lámina)
                    'pintura' => Dec::d4(Dec::de((float) $p->perimetro_pintable_mm > 0 ? $p->perimetro_pintable_mm : $p->caras_pintables)),
                    'pagina' => $p->pagina,
                    'notas' => (string) $p->notas,
                    'avisos' => $p->avisos ?? [],
                ])->all(),
            ];
        }
    }

    // ---------------- Acciones ----------------

    /** Ejecuta una acción y muestra el error de negocio si lo hay. */
    private function intentar(callable $accion, ?string $ok = null): void
    {
        $this->error = $this->mensaje = null;
        try {
            $accion();
            $this->mensaje = $ok;
        } catch (ErrorDespiece $e) {
            $this->error = $e->getMessage();
        }
        $this->cargar();
    }

    public function verVersion(int $version): void
    {
        $this->version = $version;
        $this->error = $this->mensaje = null;
        $this->cargar();
    }

    public function leerConIa(): void
    {
        $this->intentar(function () {
            $d = FlujoDespiece::leerConIa($this->plano, $this->conDibujos);
            $this->version = $d->version;
        });
    }

    /**
     * Lo llama wire:poll cada 3 s mientras la IA trabaja.
     * Compara contra $estadoVisto (guardado en la pantalla) para saber si cambió;
     * cuando la IA termina bien, abre solo la ventana con el PDF.
     */
    public function refrescar(): void
    {
        unset($this->despiece);
        $ahora = $this->despiece?->estado;
        if ($ahora !== $this->estadoVisto) {
            $this->cargar();
            if (in_array($ahora, ['BORRADOR', 'APROBADO'], true)) {
                $this->verDespiece = true;
            }
        }
    }

    /** ¿Ya hay un despiece con piezas para mostrar en PDF? */
    #[Computed]
    public function despieceListo(): bool
    {
        $d = $this->despiece;

        return $d && in_array($d->estado, ['BORRADOR', 'APROBADO', 'REEMPLAZADO'], true) && $d->piezas()->exists();
    }

    /**
     * Botón "Despiece": si ya está, abre el PDF; si no, la IA lo lee
     * (y al terminar, refrescar() abre el PDF solo).
     */
    public function pedirDespiece(): void
    {
        $d = $this->despiece;
        if ($this->despieceListo) {
            $this->verDespiece = true;

            return;
        }
        if ($d?->estado === 'LEYENDO') {
            return; // ya está trabajando
        }
        $this->leerConIa();
    }

    /** "Volver a generar": lee otra vez el plano con la IA (versión nueva). */
    public function volverAGenerar(): void
    {
        $this->verDespiece = false;
        $this->corrigiendo = false;
        $this->leerConIa();
    }

    /** Desde la ventana del PDF: abrir el editor de piezas. */
    public function corregir(): void
    {
        $this->verDespiece = false;
        $this->corrigiendo = true;
    }

    /** Si la cola se cayó, permite soltar una lectura atorada (más de 20 min). */
    public function cancelarLectura(): void
    {
        $d = $this->despiece;
        if ($d && $d->estado === 'LEYENDO' && $d->actualizado_en->lt(now()->subMinutes(20))) {
            $d->update(['estado' => 'ERROR', 'progreso' => null, 'error' => 'Cancelada a mano (¿estaba corriendo php artisan queue:work?).']);
        }
        $this->cargar();
    }

    public function capturarManual(): void
    {
        $this->intentar(function () {
            $this->version = FlujoDespiece::nuevoManual($this->plano)->version;
        });
    }

    public function nuevaVersion(): void
    {
        $this->intentar(function () {
            $this->version = FlujoDespiece::copiar($this->despiece)->version;
        }, 'Se creó una versión nueva en BORRADOR a partir de la anterior.');
    }

    public function guardar(): void
    {
        $this->intentar(fn () => FlujoDespiece::guardar($this->despiece, $this->subs), 'Guardado. Los avisos se recalcularon.');
    }

    public function aprobar(): void
    {
        $this->intentar(function () {
            // Guardar recalcula los avisos: antes hay que ver que nadie deje pasar una cantidad no leída
            if ($n = FlujoDespiece::cantidadesPorConfirmar($this->subs)) {
                throw new ErrorDespiece("Hay {$n} pieza(s) con cantidad no leída. Confírmala y dale Guardar antes de aprobar.");
            }
            FlujoDespiece::guardar($this->despiece, $this->subs);
            FlujoDespiece::aprobar($this->despiece->refresh());
        }, 'Despiece aprobado. Los materiales que elegiste a mano quedaron como alias en el catálogo.');
    }

    public function cotizar()
    {
        $this->error = null;
        try {
            $c = FlujoDespiece::cotizar($this->despiece, $this->clienteId, max(1, $this->cantidad));
        } catch (ErrorDespiece $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('cotizaciones.editor', $c, navigate: true);
    }

    // ---------------- Edición del arreglo (no guarda hasta "Guardar") ----------------

    public function agregarSubensamble(): void
    {
        $this->subs[] = ['nombre' => '', 'cantidad' => '1', 'pagina' => null, 'notas' => '', 'piezas' => [$this->piezaVacia()]];
    }

    public function quitarSubensamble(int $i): void
    {
        unset($this->subs[$i]);
        $this->subs = array_values($this->subs);
    }

    public function agregarPieza(int $i): void
    {
        $this->subs[$i]['piezas'][] = $this->piezaVacia();
    }

    public function quitarPieza(int $i, int $j): void
    {
        unset($this->subs[$i]['piezas'][$j]);
        $this->subs[$i]['piezas'] = array_values($this->subs[$i]['piezas']);
    }

    private function piezaVacia(): array
    {
        return ['descripcion' => '', 'material_texto' => '', 'material_id' => null, 'cantidad' => '1', 'largo_mm' => '0',
            'ancho_mm' => '0', 'pintura' => '0', 'pagina' => null, 'notas' => '', 'avisos' => []];
    }

    public function render()
    {
        $d = $this->despiece;
        $piezas = collect($this->subs)->flatMap(fn ($s) => $s['piezas']);

        /** @var mixed $vista title() es un macro de Livewire: el editor no lo ve en la clase View */
        $vista = view('livewire.produccion.plano', [
            'd' => $d,
            'editable' => $d?->esEditable() ?? false,
            // Dirección del PDF del despiece; "t" cambia cuando se edita, así el navegador no muestra uno viejo
            'pdfDespiece' => $this->despieceListo
                ? route('produccion.despiece', array_filter([
                    'plano' => $this->plano,
                    'v' => $d->version,
                    'cantidad' => max(1, (int) $this->pdfCantidad),
                    'pedido' => trim($this->pdfPedido),
                    'entrega' => $this->pdfEntrega,
                    't' => $d->actualizado_en?->timestamp,
                ]))
                : null,
            'mats' => $this->materiales,
            'clientes' => $this->cotizando ? Cliente::where('activo', true)->orderBy('nombre')->get() : collect(),
            'conteo' => [
                'subensambles' => count($this->subs),
                'piezas' => $piezas->count(),
                'errores' => $piezas->filter(fn ($p) => collect($p['avisos'])->contains('nivel', 'error'))->count(),
            ],
        ]);

        return $vista->title($this->plano->clave.' · Plano');
    }
}
