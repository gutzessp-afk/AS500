<!-- SPEC SULA Core · MÓDULO 3 · PARTE 4 DE 5 -->
> **PARTE 4 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte4.md` y responde solo "recibida 4/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Livewire/Planos/Ver.php`

**NUEVO.**

- `$subs`: **copia editable** del despiece en memoria. Lo que escribes no se guarda hasta "Guardar" (igual que el despiece de cotizaciones).
- `despiece()` (computed): la versión pedida (`?v=2`), o la aprobada, o la última.
- `explosion()`: totales por material **por mueble** (piezas, metros lineales, m²), calculados en vivo con `BigDecimal`.
- `refrescar()`: lo llama `wire:poll.3s` mientras la IA lee; cuando cambia el estado, recarga todo.
- `cancelarLectura()`: si la cola estaba apagada y algo se quedó "Leyendo" más de 20 min, permite soltarlo.
- `aprobar()` primero **guarda** lo que tengas en pantalla y luego aprueba (para que no se pierdan cambios).
- La pintura se edita en **una columna**: perímetro (tubo) o caras (lámina), como en cotizaciones.

````php
<?php

namespace App\Livewire\Planos;

use App\Costeo\Dec;
use App\Models\Cliente;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\Plano;
use App\Planos\ErrorDespiece;
use App\Planos\FlujoDespiece;
use App\Planos\Normalizador;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Un plano: visor del PDF + su despiece (subensambles → piezas).
 * Leer con IA · capturar a mano · editar · aprobar · cotizar.
 */
class Ver extends Component
{
    public Plano $plano;

    // Versión del despiece que se está viendo (?v=2 en la dirección)
    #[Url(as: 'v')]
    public ?int $version = null;

    // Copia editable del despiece: [['nombre','cantidad','pagina','notas','piezas'=>[…]]]
    public array $subs = [];

    public bool $conDibujos = false;

    public bool $verPdf = true;

    public ?string $error = null;

    public ?string $mensaje = null;

    // Mandar a cotizar
    public bool $cotizando = false;

    public ?int $clienteId = null;

    public int $cantidad = 1;

    public function mount(Plano $plano): void
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

    /** Lo llama wire:poll mientras la IA trabaja. */
    public function refrescar(): void
    {
        $d = $this->despiece;
        $antes = $d?->estado;
        $d?->refresh();
        if ($antes !== $d?->estado) {
            $this->cargar();
        }
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

        return view('livewire.planos.ver', [
            'd' => $d,
            'editable' => $d?->esEditable() ?? false,
            'mats' => $this->materiales,
            'clientes' => $this->cotizando ? Cliente::where('activo', true)->orderBy('nombre')->get() : collect(),
            'conteo' => [
                'subensambles' => count($this->subs),
                'piezas' => $piezas->count(),
                'errores' => $piezas->filter(fn ($p) => collect($p['avisos'])->contains('nivel', 'error'))->count(),
            ],
        ])->title($this->plano->clave.' · Plano');
    }
}
````

### `resources/views/livewire/planos/ver.blade.php`

**NUEVO.**

- Dos columnas en pantalla grande: plano a la izquierda (fijo al hacer scroll) y despiece a la derecha. "Ocultar plano" da todo el ancho a la tabla.
- `<iframe>` + visor del navegador: zoom, páginas e imprimir sin instalar nada. `wire:ignore` evita que Livewire recargue el PDF en cada clic.
- Estados: sin despiece → botones "Leer con IA" / "Capturar a mano"; `LEYENDO` → avance en vivo; `ERROR` → mensaje; `BORRADOR` → editable; `APROBADO` → "Cotizar" / "Nueva versión".
- `wire:confirm`: pide confirmación antes de aprobar o volver a gastar IA.

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="max-w-[100rem]">
    {{-- Encabezado --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('planos') }}" wire:navigate class="font-mono text-xs tracking-widest text-tenue hover:text-texto">← PLANOS</a>
            <h1 class="mt-1 font-mono text-3xl font-semibold">{{ $plano->clave }}</h1>
            <p class="mt-1 truncate text-sm text-tenue">
                {{ $plano->nombre }} · <span class="font-mono">{{ $plano->revision }}</span>
                @if ($plano->carpeta) · {{ $plano->carpeta }} @endif
                @unless ($plano->activo) · <span class="text-sula">ya no está en {{ $plano->origen }}</span> @endunless
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button class="boton" wire:click="$toggle('verPdf')">{{ $verPdf ? 'Ocultar plano' : 'Ver plano' }}</button>
            <a class="boton" href="{{ route('planos.pdf', $plano) }}?descargar=1">Descargar PDF</a>
        </div>
    </div>

    @if ($mensaje)<div class="mt-4 border-l-2 border-ok bg-ok/10 px-4 py-3 text-sm">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mt-4">{{ $error }}</div>@endif

    <div @class(['mt-6 grid gap-6', 'xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]' => $verPdf])>
        {{-- Visor del PDF: el navegador trae su propio visor (zoom, páginas, imprimir) --}}
        @if ($verPdf)
            <div class="h-[70vh] border border-borde bg-panel xl:sticky xl:top-6 xl:h-[85vh]" wire:ignore>
                <iframe src="{{ route('planos.pdf', $plano) }}" class="h-full w-full" title="Plano {{ $plano->clave }}"></iframe>
            </div>
        @endif

        {{-- Despiece --}}
        <div class="min-w-0 space-y-4">
            {{-- Versiones --}}
            <div class="flex flex-wrap items-center gap-2">
                <p class="etiqueta mb-0 mr-2">Despiece</p>
                @foreach ($this->versiones as $v)
                    <button wire:click="verVersion({{ $v->version }})"
                        @class(['chip py-1', 'border-sula text-texto' => $d?->id === $v->id, 'border-borde text-tenue hover:text-texto' => $d?->id !== $v->id])>
                        v{{ $v->version }} · {{ $v->estado }}
                    </button>
                @endforeach
            </div>

            @if (! $d)
                {{-- Sin despiece todavía --}}
                <div class="border border-borde bg-panel p-6">
                    <h2 class="text-lg font-semibold">Este plano todavía no tiene despiece</h2>
                    <p class="mt-2 text-sm text-tenue">
                        La IA lee el texto del plano (tablas, materiales, cotas) y propone subensambles y piezas.
                        Tú lo revisas, corriges lo que esté en rojo y lo apruebas.
                    </p>
                    <label class="mt-4 flex items-start gap-2 text-sm">
                        <input type="checkbox" class="mt-1 accent-sula" wire:model="conDibujos">
                        <span>Revisar también los dibujos <span class="text-tenue">(más lento: manda imágenes de las páginas para completar medidas que solo están dibujadas)</span></span>
                    </label>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <button class="boton-primario" wire:click="leerConIa">Leer con IA</button>
                        <button class="boton" wire:click="capturarManual">Capturar a mano</button>
                    </div>
                </div>
            @elseif ($d->estado === 'LEYENDO')
                {{-- La IA está trabajando: se pregunta el avance cada 3 s --}}
                <div class="border border-alerta/50 bg-alerta/5 p-6" wire:poll.3s="refrescar">
                    <p class="font-mono text-xs tracking-widest text-alerta">LEYENDO CON IA · v{{ $d->version }}</p>
                    <p class="mt-2 animate-pulse text-lg">{{ $d->progreso ?? 'Trabajando…' }}</p>
                    <p class="mt-3 text-sm text-tenue">
                        Puedes cambiar de pantalla; sigue en segundo plano.
                        Si se queda en "En la fila…", revisa que esté corriendo <span class="font-mono">php artisan queue:work</span>.
                    </p>
                    @if ($d->actualizado_en->lt(now()->subMinutes(20)))
                        <button class="boton mt-4" wire:click="cancelarLectura">Cancelar lectura atorada</button>
                    @endif
                </div>
            @else
                @if ($d->estado === 'ERROR')
                    <div class="aviso-error">
                        <p class="font-semibold">La IA no pudo leer el plano</p>
                        <p class="mt-1">{{ $d->error }}</p>
                    </div>
                @endif

                {{-- Datos que la IA leyó del cuadro del plano --}}
                @php($dp = $d->datos['plano'] ?? [])
                @if (array_filter($dp) || ! empty($d->datos['leyenda']))
                    <div class="grid gap-px border border-borde bg-borde text-sm sm:grid-cols-2">
                        <div class="bg-panel px-4 py-3">
                            @foreach (['descripcion' => 'Mueble', 'modelo' => 'Modelo', 'cliente' => 'Cliente', 'acabado' => 'Acabado'] as $k => $n)
                                @if (! empty($dp[$k]))<p><span class="etiqueta inline">{{ $n }}</span> {{ $dp[$k] }}</p>@endif
                            @endforeach
                        </div>
                        <div class="bg-panel px-4 py-3 font-mono text-xs">
                            <p class="etiqueta">Leyenda de materiales</p>
                            @forelse ($d->datos['leyenda'] ?? [] as $codigo => $texto)
                                <p><span class="text-tenue">{{ $codigo }}</span> {{ $texto }}</p>
                            @empty
                                <p class="text-tenue">—</p>
                            @endforelse
                        </div>
                    </div>
                @endif

                {{-- Barra de acciones --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-chip-despiece :despiece="$d" />
                    <span class="font-mono text-xs text-tenue">
                        {{ $conteo['subensambles'] }} subensambles · {{ $conteo['piezas'] }} piezas
                        @if ($conteo['errores']) · <span class="text-sula">{{ $conteo['errores'] }} en rojo</span> @endif
                        · {{ $d->origen }}{{ $d->con_dibujos ? ' + dibujos' : '' }}
                        @if ($d->aprobado_en) · aprobado {{ F::fecha($d->aprobado_en) }} por {{ $d->aprobado_por }} @endif
                    </span>
                    <div class="ml-auto flex flex-wrap gap-2">
                        @if ($editable)
                            <button class="boton" wire:click="guardar">
                                <span wire:loading.remove wire:target="guardar">Guardar</span>
                                <span wire:loading wire:target="guardar">Guardando…</span>
                            </button>
                            <button class="boton-primario" wire:click="aprobar" wire:confirm="¿Aprobar este despiece? Ya no se podrá editar (sí se puede sacar una versión nueva).">Aprobar</button>
                        @elseif ($d->estado === 'APROBADO')
                            <button class="boton" wire:click="nuevaVersion">Nueva versión</button>
                            <button class="boton-primario" wire:click="$set('cotizando', true)">Cotizar este plano</button>
                        @elseif ($d->estado === 'REEMPLAZADO')
                            <button class="boton" wire:click="nuevaVersion">Copiar a versión nueva</button>
                        @endif
                        @if ($d->estado !== 'LEYENDO')
                            <button class="boton" wire:click="leerConIa" wire:confirm="Se creará una versión nueva leyendo el plano otra vez con la IA. ¿Seguir?">Volver a leer con IA</button>
                        @endif
                    </div>
                </div>
                @if ($d->estado !== 'LEYENDO')
                    <label class="flex items-center gap-2 text-xs text-tenue">
                        <input type="checkbox" class="accent-sula" wire:model="conDibujos"> Al volver a leer, revisar también los dibujos
                    </label>
                @endif

                @include('livewire.planos._despiece')

                {{-- Explosión por mueble --}}
                @if ($this->explosion)
                    <div>
                        <p class="etiqueta">Explosión: total por mueble</p>
                        <div class="overflow-x-auto border border-borde">
                            <table class="w-full text-sm">
                                <thead class="tabla-cabecera">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Material</th>
                                        <th class="px-4 py-2 text-right">Piezas</th>
                                        <th class="px-4 py-2 text-right">Medida</th>
                                    </tr>
                                </thead>
                                <tbody class="cifras divide-y divide-borde font-mono text-xs">
                                    @foreach ($this->explosion as $f)
                                        <tr>
                                            <td class="px-4 py-2"><span class="font-semibold">{{ $f['material']->clave }}</span> <span class="font-sans text-tenue">{{ $f['material']->descripcion }}</span></td>
                                            <td class="px-4 py-2 text-right">{{ F::num($f['piezas']) }}</td>
                                            <td class="px-4 py-2 text-right">{{ $f['unidad'] ? F::num($f['medida']).' '.$f['unidad'] : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-1 font-mono text-[11px] text-tenue">Medida neta (sin merma ni plan de corte): eso se decide en la cotización.</p>
                    </div>
                @endif
            @endif
        </div>
    </div>
</section>

{{-- Ventana: cotizar --}}
@if ($cotizando)
    <button class="fixed inset-0 z-30 bg-black/60" aria-label="Cerrar" wire:click="$set('cotizando', false)"></button>
    <div class="fixed inset-x-4 top-24 z-40 mx-auto max-w-md border border-borde bg-panel p-6 shadow-2xl">
        <p class="font-mono text-xs tracking-widest text-tenue">COTIZAR · {{ $plano->clave }}</p>
        <h2 class="mt-1 text-lg font-semibold">Nueva cotización con este despiece</h2>
        <p class="mt-2 text-sm text-tenue">Se crea en BORRADOR con las piezas ya multiplicadas por subensamble. Ahí ajustas merma, plan de corte y parámetros.</p>
        <div class="mt-4 space-y-3">
            <div>
                <label class="etiqueta" for="cot-cliente">Cliente</label>
                <select id="cot-cliente" class="campo" wire:model="clienteId">
                    <option value="">Sin cliente (parámetros de SULA)</option>
                    @foreach ($clientes as $cl)
                        <option value="{{ $cl->id }}">{{ $cl->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="etiqueta" for="cot-cantidad">Cantidad de muebles</label>
                <input id="cot-cantidad" type="number" min="1" class="campo w-32 font-mono" wire:model="cantidad">
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button class="boton" wire:click="$set('cotizando', false)">Cancelar</button>
            <button class="boton-primario" wire:click="cotizar">Crear cotización</button>
        </div>
    </div>
@endif
</div>
````

### `resources/views/livewire/planos/_despiece.blade.php`

**NUEVO.**

- Un bloque por subensamble (nombre × cantidad por mueble) con su tabla de piezas.
- Debajo del selector de material se ve **cómo venía en el plano** ("plano: MELAMINA ROBLE 25MM"), para que sepas qué elegir.
- Rojo = bloquea aprobar; gris = informativo (pintura estimada).
- Lleva su propio `@use(...)` porque cada archivo Blade necesita el suyo.

````blade
@use('App\Support\Formato', 'F')
{{-- Árbol del despiece: subensamble (× cantidad por mueble) → piezas (cantidad por subensamble).
     Variables: $subs, $mats (catálogo), $editable --}}
<div class="space-y-4">
    @foreach ($subs as $i => $s)
        <div wire:key="sub-{{ $i }}" class="border border-borde">
            {{-- Encabezado del subensamble --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-borde bg-panel px-3 py-2">
                @if ($editable)
                    <input class="campo w-64 px-2 py-1 font-mono text-sm font-semibold uppercase" placeholder="NOMBRE DEL SUBENSAMBLE"
                           wire:model="subs.{{ $i }}.nombre">
                    <span class="font-mono text-sm text-tenue">×</span>
                    <input class="campo w-20 px-2 py-1 text-right font-mono text-sm" inputmode="decimal" title="Cuántos lleva un mueble"
                           wire:model="subs.{{ $i }}.cantidad">
                    <span class="text-xs text-tenue">por mueble</span>
                    <button class="ml-auto px-2 text-tenue hover:text-sula" title="Quitar subensamble"
                            wire:click="quitarSubensamble({{ $i }})" wire:confirm="¿Quitar el subensamble {{ $s['nombre'] }} con todas sus piezas?">✕</button>
                @else
                    <span class="font-mono text-sm font-semibold">{{ $s['nombre'] }}</span>
                    <span class="font-mono text-sm text-tenue">× {{ F::num($s['cantidad']) }} por mueble</span>
                @endif
                @if ($s['pagina'])<span class="font-mono text-[11px] text-tenue">pág. {{ $s['pagina'] }}</span>@endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="tabla-cabecera">
                        <tr>
                            <th class="px-3 py-2">Pieza</th>
                            <th class="px-2 py-2">Material</th>
                            <th class="px-2 py-2 text-right" title="Por subensamble">Cant.</th>
                            <th class="px-2 py-2 text-right">Largo mm</th>
                            <th class="px-2 py-2 text-right">Ancho mm</th>
                            <th class="px-2 py-2 text-right" title="Perímetro pintable mm (tubo) o caras (lámina)">Pintura</th>
                            <th class="px-2 py-2 text-right">Pág.</th>
                            @if ($editable)<th class="w-8"></th>@endif
                        </tr>
                    </thead>
                    <tbody class="cifras divide-y divide-borde">
                        @foreach ($s['piezas'] as $j => $p)
                            @php
                                $errores = collect($p['avisos'])->where('nivel', 'error');
                                $info = collect($p['avisos'])->where('nivel', 'info');
                                $m = $mats->get((int) $p['material_id']);
                            @endphp
                            <tr wire:key="pz-{{ $i }}-{{ $j }}" @class(['align-top', 'bg-sula/5' => $errores->isNotEmpty()])>
                                @if ($editable)
                                    <td class="px-2 py-1">
                                        <input class="campo min-w-48 px-2 py-1 text-xs" wire:model="subs.{{ $i }}.piezas.{{ $j }}.descripcion">
                                    </td>
                                    <td class="px-1 py-1">
                                        <select @class(['campo min-w-52 px-2 py-1 text-xs', 'border-sula' => ! $m]) wire:model="subs.{{ $i }}.piezas.{{ $j }}.material_id">
                                            <option value="">— elegir material —</option>
                                            @foreach ($mats as $op)
                                                <option value="{{ $op->id }}">{{ $op->clave }} · {{ $op->descripcion }}</option>
                                            @endforeach
                                        </select>
                                        @if ($p['material_texto'])
                                            <p class="mt-0.5 truncate font-mono text-[10px] text-tenue" title="Como viene en el plano">plano: {{ $p['material_texto'] }}</p>
                                        @endif
                                    </td>
                                    @foreach (['cantidad' => 'w-16', 'largo_mm' => 'w-20', 'ancho_mm' => 'w-20', 'pintura' => 'w-20', 'pagina' => 'w-14'] as $campo => $ancho)
                                        <td class="px-1 py-1">
                                            <input class="campo {{ $ancho }} px-2 py-1 text-right font-mono text-xs" inputmode="decimal"
                                                   wire:model="subs.{{ $i }}.piezas.{{ $j }}.{{ $campo }}">
                                        </td>
                                    @endforeach
                                    <td class="px-1 py-1">
                                        <button class="px-1 text-tenue hover:text-sula" title="Quitar pieza" wire:click="quitarPieza({{ $i }}, {{ $j }})">✕</button>
                                    </td>
                                @else
                                    <td class="px-3 py-2">{{ $p['descripcion'] }}</td>
                                    <td class="px-2 py-2 text-xs">
                                        @if ($m)<span class="font-mono font-semibold">{{ $m->clave }}</span> <span class="text-tenue">{{ $m->descripcion }}</span>@else<span class="text-sula">sin material</span>@endif
                                    </td>
                                    <td class="px-2 py-2 text-right font-mono">{{ F::num($p['cantidad']) }}</td>
                                    <td class="px-2 py-2 text-right font-mono">{{ (float) $p['largo_mm'] ? F::num($p['largo_mm']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono">{{ (float) $p['ancho_mm'] ? F::num($p['ancho_mm']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono text-tenue">{{ (float) $p['pintura'] ? F::num($p['pintura']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono text-tenue">{{ $p['pagina'] ?? '—' }}</td>
                                @endif
                            </tr>
                            @if ($p['avisos'] || ($p['notas'] && ! $editable))
                                <tr wire:key="av-{{ $i }}-{{ $j }}" @class(['bg-sula/5' => $errores->isNotEmpty()])>
                                    <td colspan="{{ $editable ? 8 : 7 }}" class="px-3 pb-2 text-[11px]">
                                        @foreach ($errores as $a)<span class="mr-3 text-sula">⚠ {{ $a['texto'] }}</span>@endforeach
                                        @foreach ($info as $a)<span class="mr-3 text-tenue">ℹ {{ $a['texto'] }}</span>@endforeach
                                        @if ($p['notas'] && ! $editable)<span class="text-tenue">Notas: {{ $p['notas'] }}</span>@endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($editable)
                <div class="border-t border-borde px-3 py-2">
                    <button class="font-mono text-xs text-tenue hover:text-texto" wire:click="agregarPieza({{ $i }})">+ Pieza</button>
                </div>
            @endif
        </div>
    @endforeach

    @if ($editable)
        <button class="boton" wire:click="agregarSubensamble">+ Subensamble</button>
        <p class="font-mono text-[11px] text-tenue">Los avisos se recalculan al dar "Guardar". Lo que no esté en rojo ya está listo.</p>
    @endif
</div>
````

### `app/Livewire/Inicio.php`

**REEMPLAZA.**

Agrega el conteo de planos activos.

````php
<?php

namespace App\Livewire;

use App\Models\Cotizacion;
use App\Models\Evento;
use App\Models\Material;
use App\Models\Plano;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Pantalla de inicio: estado del sistema. Se refresca sola cada 10 s (wire:poll). */
#[Title('Inicio')]
class Inicio extends Component
{
    public function render()
    {
        try {
            DB::select('SELECT 1');
            $bd = 'OK';
        } catch (\Throwable) {
            $bd = 'SIN CONEXIÓN';
        }

        return view('livewire.inicio', [
            'bd' => $bd,
            'materiales' => $bd === 'OK' ? Material::where('activo', true)->count() : 0,
            'borradores' => $bd === 'OK' ? Cotizacion::where('estado', 'BORRADOR')->count() : 0,
            'planos' => $bd === 'OK' ? Plano::where('activo', true)->count() : 0,
            'eventosHoy' => $bd === 'OK' ? Evento::whereDate('ocurrido_en', today())->count() : 0,
        ]);
    }
}
````

### `resources/views/livewire/inicio.blade.php`

**REEMPLAZA.**

Cuatro tarjetas abajo: Planos, Materiales, Cotizaciones en borrador, Journal.

````blade
<section class="max-w-5xl" wire:poll.10s>
    <p class="font-mono text-xs tracking-widest text-tenue">INI · ESTADO DEL SISTEMA</p>
    <h1 class="mt-1 text-3xl font-semibold">SULA Core</h1>
    <p class="mt-2 max-w-xl text-sm text-tenue">
        El sistema nervioso de la planta: costeo, despiece, inventario y producción en una sola fuente de verdad.
    </p>

    <div class="mt-8 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        @foreach ([
            ['Base de datos', $bd, $bd === 'OK'],
            ['Versión', config('sula.version'), true],
            ['Entorno', strtoupper(app()->environment()), true],
            ['Usuario', config('sula.usuario'), true],
        ] as [$etiqueta, $valor, $ok])
            <div class="kpi">
                <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">{{ $etiqueta }}</p>
                <p class="mt-3 flex items-center gap-2 font-mono text-xl font-semibold">
                    <span @class(['h-2 w-2 rounded-full', 'bg-ok' => $ok, 'bg-sula' => ! $ok])></span>{{ $valor }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-px grid grid-cols-2 gap-px lg:grid-cols-4 border border-t-0 border-borde bg-borde">
        <a href="{{ route('planos') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">PLANOS</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $planos }}</p>
        </a>
        <a href="{{ route('materiales') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">MATERIALES ACTIVOS</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $materiales }}</p>
        </a>
        <a href="{{ route('cotizaciones') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">COTIZACIONES EN BORRADOR</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $borradores }}</p>
        </a>
        <a href="{{ route('journal') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">CAMBIOS HOY (JOURNAL)</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $eventosHoy }}</p>
        </a>
    </div>

    <p class="cifras mt-4 font-mono text-xs text-tenue">Última revisión: {{ now()->format('H:i:s') }}</p>
</section>
````

## 11. PRUEBAS

### `tests/Unit/NombreArchivoTest.php`

**NUEVO.**

4 pruebas: clave SULA, nombre sin clave (el exhibidor), revisiones y patrón propio.

````php
<?php

namespace Tests\Unit;

use App\Planos\NombreArchivo;
use PHPUnit\Framework\TestCase;

/** La clave y la revisión salen del nombre del PDF. */
class NombreArchivoTest extends TestCase
{
    public function test_clave_sula_en_el_nombre(): void
    {
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('2-1-0072-0003 VITRINA PARA REFACCIONES REV-01.pdf'));
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('VENTO_2-1-0072-0003.pdf'));
    }

    public function test_sin_clave_usa_el_nombre_limpio(): void
    {
        $this->assertSame('SULA - MODULO EXHIBIDOR PL - FT', NombreArchivo::clave('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
        $this->assertSame('GONDOLA CENTRAL', NombreArchivo::clave('gondola_central REV-02.pdf'));
    }

    public function test_revision(): void
    {
        $this->assertSame('REV-01', NombreArchivo::revision('2-1-0072-0003 VITRINA REV-01.pdf'));
        $this->assertSame('REV-03', NombreArchivo::revision('mesa rev 3.pdf'));
        $this->assertSame('REV-00', NombreArchivo::revision('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
    }

    public function test_patron_propio(): void
    {
        $this->assertSame('KFC-0012', NombreArchivo::clave('Mostrador KFC-0012.pdf', '/KFC-\d{4}/'));
    }
}
````
