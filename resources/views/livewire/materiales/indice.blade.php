@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-7xl">
    <x-encabezado area="Catálogo" titulo="Materiales"
        descripcion="Los materiales que usa SULA con su precio vigente (sin IVA, por unidad de consumo). Cambiar un precio no borra el anterior: queda en el historial.">
        <x-slot:acciones>
            <button class="boton-primario" wire:click="nuevo">+ Nuevo material</button>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>Haz clic en un material para ver su precio, su historial y sus alias (cómo se llama en los planos).</li>
            <li><b>Nuevo precio</b> registra el precio de hoy; el anterior se guarda.</li>
            <li>Rojo = precio vencido, de confianza baja o sin precio: revisa esos antes de cotizar.</li>
        </x-slot:ayuda>
    </x-encabezado>

    {{-- Indicadores --}}
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        @foreach ($kpis as [$etiqueta, $valor, $alerta])
            <div class="kpi">
                <p class="text-xs text-tenue">{{ $etiqueta }}</p>
                <p @class(['cifras mt-2 font-mono text-3xl font-semibold', 'text-sula' => $alerta])>{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    {{-- Filtros --}}
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-xs" placeholder="Buscar clave o descripción…" wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (array_merge([''], \App\Models\Material::CATEGORIAS) as $c)
                <button wire:click="$set('categoria', '{{ $c }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $categoria === $c, 'border-borde text-tenue hover:text-texto' => $categoria !== $c])>
                    {{ $c ?: 'Todas' }}
                </button>
            @endforeach
        </div>
        <label class="ml-auto flex items-center gap-2 text-sm text-tenue">
            <input type="checkbox" class="accent-sula" wire:model.live="inactivos"> Mostrar inactivos
        </label>
    </div>

    {{-- Tabla --}}
    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Clave</th>
                    <th class="px-4 py-3">Descripción</th>
                    <th class="px-4 py-3">Categoría</th>
                    <th class="px-4 py-3">Presentación</th>
                    <th class="px-4 py-3 text-right">$ Presentación</th>
                    <th class="px-4 py-3 text-right">$ Unidad</th>
                    <th class="px-4 py-3">Confianza</th>
                    <th class="px-4 py-3 text-right">Antigüedad</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($this->materiales as $m)
                    <tr wire:key="mat-{{ $m->id }}" wire:click="ver({{ $m->id }})"
                        @class(['cursor-pointer hover:bg-panel', 'opacity-40' => ! $m->activo, 'bg-panel-alto' => $seleccionado === $m->id])>
                        <td class="px-4 py-3 font-mono font-semibold">{{ $m->clave }}</td>
                        <td class="max-w-sm px-4 py-3"><span class="line-clamp-1">{{ $m->descripcion }}</span></td>
                        <td class="px-4 py-3 font-mono text-xs text-tenue">{{ $m->categoria }}</td>
                        <td class="px-4 py-3 text-tenue">{{ $m->presentacion }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::dinero($m->precio_presentacion_sin_iva) }}</td>
                        <td class="px-4 py-3 text-right font-mono whitespace-nowrap">
                            {{ F::dineroFino($m->precio_unitario_sin_iva) }} <span class="text-tenue">/{{ F::unidad($m->unidad) }}</span>
                        </td>
                        <td class="px-4 py-3"><x-chip-confianza :valor="$m->confianza" /></td>
                        <td @class(['px-4 py-3 text-right font-mono text-xs', 'text-sula' => $m->vencido($this->vigencia), 'text-tenue' => ! $m->vencido($this->vigencia)])>
                            {{ $m->dias_antiguedad === null ? '—' : $m->dias_antiguedad.' d' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-tenue">No hay materiales con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- Panel lateral --}}
@if ($panel)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="cerrar"></button>
    <aside class="fixed inset-y-0 right-0 z-40 flex w-full max-w-xl flex-col border-l border-borde bg-panel shadow-2xl">
        @if ($panel === 'nuevo')
            <div class="overflow-y-auto px-6 py-6">
                <p class="font-mono text-xs tracking-widest text-tenue">MAT · NUEVO</p>
                <h2 class="mt-1 mb-6 text-lg font-semibold">Nuevo material</h2>
                @include('livewire.materiales._form-material', ['esNuevo' => true])
            </div>
        @elseif ($this->detalle && $this->vista)
            @php($m = $this->vista)
            <header class="flex items-start justify-between gap-4 border-b border-borde px-6 py-5">
                <div class="min-w-0">
                    <p class="font-mono text-xs tracking-widest text-tenue">
                        {{ $m->clave }} · {{ $m->categoria }} @unless ($m->activo)<span class="text-sula">· INACTIVO</span>@endunless
                    </p>
                    <h2 class="mt-1 text-lg leading-snug font-semibold">{{ $m->descripcion }}</h2>
                </div>
                <button class="boton px-3" wire:click="cerrar" aria-label="Cerrar">✕</button>
            </header>

            <div class="flex-1 space-y-8 overflow-y-auto px-6 py-6">
                @if ($panel === 'editar')
                    @include('livewire.materiales._form-material', ['esNuevo' => false])
                @else
                    {{-- Precio vigente --}}
                    <section>
                        <div class="flex items-center justify-between">
                            <p class="etiqueta">Precio vigente sin IVA</p>
                            <x-chip-confianza :valor="$m->confianza" />
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-px border border-borde bg-borde">
                            <div class="bg-fondo px-4 py-4">
                                <p class="text-xs text-tenue">POR {{ mb_strtoupper(F::unidad($m->unidad)) }}</p>
                                <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dineroFino($m->precio_unitario_sin_iva) }}</p>
                            </div>
                            <div class="bg-fondo px-4 py-4">
                                <p class="text-xs text-tenue">{{ $m->presentacion }}</p>
                                <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($m->precio_presentacion_sin_iva) }}</p>
                            </div>
                        </div>
                        @if ($m->vigente_desde)
                            <p @class(['mt-2 font-mono text-xs', 'text-sula' => $m->vencido($this->vigencia), 'text-tenue' => ! $m->vencido($this->vigencia)])>
                                Desde {{ F::fecha($m->vigente_desde) }} · hace {{ $m->dias_antiguedad }} días
                                @if ($m->vencido($this->vigencia)) · VENCIDO (vigencia {{ $this->vigencia }} días) @endif
                            </p>
                        @endif

                        @if ($capturandoPrecio)
                            <form wire:submit="registrarPrecio" class="mt-4 space-y-4 border border-borde bg-fondo p-4">
                                @include('livewire.materiales._form-precio', ['tipo' => $m->tipo_calculo, 'presentacion' => $m->presentacion])
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="boton" wire:click="$set('capturandoPrecio', false)">Cancelar</button>
                                    <button type="submit" class="boton-primario">Registrar precio</button>
                                </div>
                            </form>
                        @else
                            <div class="mt-4 flex gap-2">
                                <button class="boton-primario" wire:click="$set('capturandoPrecio', true)">Nuevo precio</button>
                                <button class="boton" wire:click="editar">Editar datos</button>
                            </div>
                        @endif
                    </section>

                    {{-- Datos --}}
                    <section>
                        <p class="etiqueta">Datos</p>
                        <dl class="cifras grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                            <dt class="text-tenue">Cálculo</dt><dd class="font-mono">{{ $m->tipo_calculo }} · por {{ F::unidad($m->unidad) }}</dd>
                            <dt class="text-tenue">Presentación</dt><dd>{{ $m->presentacion }} · {{ F::num($m->contenido) }} {{ F::unidad($m->unidad) }}</dd>
                            @if ($m->calibre)
                                <dt class="text-tenue">Calibre</dt><dd class="font-mono">Cal {{ $m->calibre }} · {{ $m->kg_m2 }} kg/m²</dd>
                            @endif
                            <dt class="text-tenue">Se cobra</dt><dd>{{ $m->modo_carga === 'COMPLETA' ? 'Presentación completa' : 'Solo lo consumido' }}</dd>
                            <dt class="text-tenue">Proveedor</dt><dd>{{ $m->proveedor ?? '—' }}</dd>
                            @if ($m->liga)
                                <dt class="text-tenue">Liga</dt><dd class="truncate"><a href="{{ $m->liga }}" target="_blank" rel="noopener" class="text-sula underline">{{ $m->liga }}</a></dd>
                            @endif
                            @if ($m->notas)
                                <dt class="text-tenue">Notas</dt><dd class="text-tenue">{{ $m->notas }}</dd>
                            @endif
                        </dl>
                    </section>

                    {{-- Alias --}}
                    <section>
                        <p class="etiqueta">Cómo aparece en los planos (alias)</p>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($this->detalle->alias as $a)
                                <span class="chip border-borde text-texto" wire:key="alias-{{ $a->alias }}">
                                    {{ $a->alias }}
                                    <button class="text-tenue hover:text-sula" wire:click="quitarAlias(@js($a->alias))" aria-label="Quitar">✕</button>
                                </span>
                            @empty
                                <span class="text-sm text-tenue">Sin alias todavía.</span>
                            @endforelse
                        </div>
                        <form wire:submit="agregarAlias" class="mt-3 flex gap-2">
                            <input class="campo font-mono uppercase" placeholder="Nombre del material en el plano" wire:model="nuevoAlias">
                            <button class="boton shrink-0" type="submit">Agregar</button>
                        </form>
                        @error('nuevoAlias') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
                    </section>

                    {{-- Historial --}}
                    <section>
                        <p class="etiqueta">Historial de precios</p>
                        <ol class="divide-y divide-borde border border-borde">
                            @foreach ($this->detalle->precios as $i => $p)
                                <li wire:key="precio-{{ $p->id }}" @class(['cifras grid grid-cols-[1fr_auto] gap-2 px-4 py-3 text-sm', 'bg-fondo' => $i === 0])>
                                    <div>
                                        <p class="font-mono">
                                            {{ F::dineroFino($p->precio_publicado) }}
                                            <span class="text-tenue">/ {{ $p->precio_por === 'UNIDAD' ? F::unidad($m->unidad) : $m->presentacion }} · {{ $p->incluye_iva ? 'con IVA' : 'sin IVA' }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-tenue">{{ F::fecha($p->vigente_desde) }} · {{ $p->fuente ?? 'sin fuente' }} · {{ $p->registrado_por ?? '—' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <x-chip-confianza :valor="$p->confianza" />
                                        @if ($i === 0)<p class="mt-1 font-mono text-[10px] text-ok">VIGENTE</p>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>
        @endif
    </aside>
@endif
</div>
