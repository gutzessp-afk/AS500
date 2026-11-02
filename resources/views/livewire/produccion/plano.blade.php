@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-[100rem]">
    {{-- Ruta de regreso: Planos › VENTO › VITRINAS › clave --}}
    <nav class="mb-3 flex flex-wrap items-center gap-1 text-sm text-tenue">
        <a href="{{ route('produccion') }}" wire:navigate class="hover:text-texto">Planos</a>
        @foreach ($plano->carpetaPadre?->camino() ?? [] as $paso)
            <x-icono nombre="flecha" class="size-3.5" />
            <a href="{{ route('produccion', ['carpeta' => $paso->id]) }}" wire:navigate class="hover:text-texto">{{ $paso->nombre }}</a>
        @endforeach
        <x-icono nombre="flecha" class="size-3.5" />
        <span class="font-medium text-texto">{{ $plano->clave }}</span>
    </nav>

    <x-encabezado area="Producción · plano" :titulo="$plano->clave"
        :descripcion="$plano->nombre.' · '.$plano->revision.($plano->activo ? '' : ' · ya no está en '.$plano->origen)">
        <x-slot:acciones>
            {{-- Botón principal: despiece en PDF --}}
            <button class="boton-primario" wire:click="pedirDespiece" wire:loading.attr="disabled" wire:target="pedirDespiece"
                    @disabled($d?->estado === 'LEYENDO')>
                <x-icono nombre="pdf" class="size-4" />
                {{ $d?->estado === 'LEYENDO' ? 'Generando despiece…' : 'Despiece' }}
            </button>
            <a class="boton" href="{{ route('produccion.pdf', $plano) }}" target="_blank" rel="noopener" title="Ver el plano en una pestaña completa">
                <x-icono nombre="ojo" class="size-4" /> Abrir plano en pestaña
            </a>
            <a class="boton" href="{{ route('produccion.pdf', $plano) }}?descargar=1">
                <x-icono nombre="descargar" class="size-4" /> Descargar plano
            </a>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>El plano se ve grande abajo: usa el zoom del visor, o <b>Abrir plano en pestaña</b> para verlo a pantalla completa.</li>
            <li><b>Despiece</b>: la primera vez el sistema lee el plano (segundos o unos minutos) y al terminar se abre el PDF solo. Las siguientes veces abre directo.</li>
            <li>Si en el PDF algo sale marcado <span class="text-sula">REVISAR</span>, dale <b>Corregir piezas</b>, arréglalo y vuelve a abrir el despiece.</li>
        </x-slot:ayuda>
    </x-encabezado>

    @if ($mensaje)<div class="aviso-ok mb-4">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mb-4">{{ $error }}</div>@endif

    {{-- La IA está trabajando: se pregunta el avance cada 3 s y, al terminar, se abre el PDF --}}
    @if ($d?->estado === 'LEYENDO')
        <div class="mb-4 flex flex-wrap items-center gap-3 rounded-lg border border-alerta/40 bg-alerta/5 px-4 py-3" wire:poll.3s="refrescar">
            <x-icono nombre="ia" class="size-5 animate-pulse text-alerta" />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">Generando el despiece…</p>
                <p class="text-xs text-tenue">{{ $d->progreso ?? 'Trabajando…' }} · Puedes seguir viendo el plano; el PDF se abre solo al terminar.</p>
            </div>
            @if ($d->actualizado_en->lt(now()->subMinutes(20)))
                <button class="boton" wire:click="cancelarLectura">Cancelar (atorado)</button>
            @endif
        </div>
    @elseif ($d?->estado === 'ERROR')
        <div class="aviso-error mb-4 flex flex-wrap items-center gap-3">
            <div class="min-w-0 flex-1">
                <p class="font-semibold">No se pudo generar el despiece</p>
                <p class="mt-1">{{ $d->error }}</p>
            </div>
            <button class="boton" wire:click="volverAGenerar">Intentar otra vez</button>
        </div>
    @endif

    {{-- Visor GRANDE del plano: el navegador trae su propio visor (zoom, páginas, imprimir).
         #view=FitH = que la hoja ocupe todo el ancho. --}}
    @unless ($corrigiendo)
        <div class="tarjeta overflow-hidden" wire:ignore>
            <iframe src="{{ route('produccion.pdf', $plano) }}#view=FitH" class="block h-[calc(100vh-15rem)] min-h-[32rem] w-full"
                    title="Plano {{ $plano->clave }}"></iframe>
        </div>
    @endunless

    {{-- Editor de piezas: escondido hasta que se pide "Corregir piezas" --}}
    @if ($corrigiendo && $d)
        <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
                <button class="boton" wire:click="$set('corrigiendo', false)"><x-icono nombre="atras" class="size-4" /> Volver al plano</button>
                @if ($pdfDespiece)
                    <button class="boton" wire:click="$set('verDespiece', true)"><x-icono nombre="pdf" class="size-4" /> Ver PDF del despiece</button>
                @endif
                <p class="ml-2 text-sm font-medium">Versiones:</p>
                @foreach ($this->versiones as $v)
                    <button wire:click="verVersion({{ $v->version }})"
                        @class(['chip py-1', 'border-sula text-texto' => $d->id === $v->id, 'border-borde text-tenue hover:text-texto' => $d->id !== $v->id])>
                        v{{ $v->version }} · {{ $v->estado }}
                    </button>
                @endforeach
            </div>

                {{-- Datos que la IA leyó del cuadro del plano --}}
                @php($dp = $d->datos['plano'] ?? [])
                @if (array_filter($dp) || ! empty($d->datos['leyenda']))
                    <div class="grid gap-px overflow-hidden rounded-lg border border-borde bg-borde text-sm sm:grid-cols-2">
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
                            <button class="boton" wire:click="leerConIa" wire:confirm="Se leerá el plano otra vez y se hará una versión nueva. ¿Seguir?">Volver a generar</button>
                        @endif
                    </div>
                </div>
                @if ($d->estado !== 'LEYENDO')
                    <label class="flex items-center gap-2 text-xs text-tenue">
                        <input type="checkbox" class="accent-sula" wire:model="conDibujos"> Al volver a leer, revisar también los dibujos
                    </label>
                @endif

                @include('livewire.produccion._despiece')

                {{-- Explosión por mueble --}}
                @if ($this->explosion)
                    <div>
                        <p class="etiqueta">Explosión: total por mueble</p>
                        <div class="tarjeta overflow-x-auto">
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
        </div>
    @endif
</section>

{{-- Ventana: PDF del despiece --}}
@if ($verDespiece && $pdfDespiece)
    <div class="fixed inset-0 z-30 bg-black/40" wire:click="$set('verDespiece', false)"></div>
    <div class="fixed inset-2 z-40 flex flex-col overflow-hidden rounded-lg border border-borde bg-panel shadow-2xl sm:inset-6">
        <div class="flex flex-wrap items-center gap-2 border-b border-borde px-4 py-3">
            <x-icono nombre="pdf" class="size-5 text-sula" />
            <div class="min-w-0 flex-1">
                <p class="font-semibold">Despiece {{ $plano->clave }} <span class="font-normal text-tenue">· v{{ $d->version }}</span></p>
                @if ($conteo['errores'])
                    <p class="text-xs text-sula">{{ $conteo['errores'] }} pieza(s) marcadas REVISAR: dale "Corregir piezas" para arreglarlas.</p>
                @endif
            </div>
            <a class="boton-primario" href="{{ $pdfDespiece }}&descargar=1"><x-icono nombre="descargar" class="size-4" /> Descargar PDF</a>
            <a class="boton" href="{{ $pdfDespiece }}" target="_blank" rel="noopener">Abrir en pestaña</a>
            <button class="boton" wire:click="corregir">Corregir piezas</button>
            <button class="boton" wire:click="volverAGenerar" wire:confirm="Se leerá el plano otra vez y se hará una versión nueva. ¿Seguir?">Volver a generar</button>
            <button class="rounded p-1.5 text-tenue hover:bg-panel-alto hover:text-texto" wire:click="$set('verDespiece', false)" aria-label="Cerrar">
                <x-icono nombre="cerrar" />
            </button>
        </div>
        {{-- Datos del pedido: al cambiarlos se vuelve a armar el PDF --}}
        <div class="flex flex-wrap items-end gap-3 border-b border-borde bg-panel-alto/60 px-4 py-2.5">
            <div>
                <label class="etiqueta" for="pdf-cantidad">Muebles a producir</label>
                <input id="pdf-cantidad" type="number" min="1" class="campo w-28 py-1.5 font-mono" wire:model.live.debounce.600ms="pdfCantidad">
            </div>
            <div>
                <label class="etiqueta" for="pdf-pedido">Pedido / OT</label>
                <input id="pdf-pedido" class="campo w-40 py-1.5 uppercase" placeholder="PED-3492" wire:model.live.debounce.600ms="pdfPedido">
            </div>
            <div>
                <label class="etiqueta" for="pdf-entrega">Fecha de entrega</label>
                <input id="pdf-entrega" type="date" class="campo w-44 py-1.5" wire:model.live="pdfEntrega">
            </div>
            <p class="pb-2 text-xs text-tenue">El PDF calcula cuánto producir de cada pieza, el material a pedir y arma una hoja por área.</p>
        </div>
        <iframe src="{{ $pdfDespiece }}#view=FitH" class="min-h-0 w-full flex-1 bg-panel-alto" title="Despiece {{ $plano->clave }}"></iframe>
    </div>
@endif

{{-- Ventana: cotizar --}}
@if ($cotizando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('cotizando', false)"></button>
    <div class="fixed inset-x-4 top-24 z-40 mx-auto max-w-md rounded-lg border border-borde bg-panel p-6 shadow-2xl">
        <p class="text-xs font-medium text-sula uppercase">Cotizar · {{ $plano->clave }}</p>
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
