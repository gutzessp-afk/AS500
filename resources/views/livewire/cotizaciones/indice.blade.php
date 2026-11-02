@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-7xl">
    <x-encabezado area="Ventas" titulo="Cotizaciones"
        descripcion="Una cotización por plano. Mientras está en borrador se calcula con los precios de hoy; al enviarla, los precios se congelan.">
        <x-slot:acciones>
            <button class="boton-primario" wire:click="nueva">+ Nueva cotización</button>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>La forma más rápida: en <b>Producción</b>, abre el plano, aprueba su despiece y dale <b>Cotizar este plano</b>.</li>
            <li>También puedes crearla aquí y pegar el despiece desde Excel.</li>
            <li><b>Enviar</b> congela los precios. Para cambiarla después se abre una nueva revisión (R1, R2…).</li>
        </x-slot:ayuda>
    </x-encabezado>

    @php($k = $this->kpis)
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        <div class="kpi">
            <p class="text-xs text-tenue">En borrador</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $k['borradores'] }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Enviadas · {{ $k['n_enviadas'] }}</p>
            <p class="cifras mt-2 font-mono text-2xl font-semibold text-alerta">{{ F::dinero($k['enviadas']) }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Aprobadas · {{ $k['n_aprobadas'] }}</p>
            <p class="cifras mt-2 font-mono text-2xl font-semibold text-ok">{{ F::dinero($k['aprobadas']) }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Tasa de aprobación</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $k['tasa'] === null ? '—' : $k['tasa'].'%' }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-xs" placeholder="Folio, plano, mueble o cliente…" wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (['', 'BORRADOR', 'ENVIADA', 'APROBADA', 'RECHAZADA'] as $e)
                <button wire:click="$set('estado', '{{ $e }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $estado === $e, 'border-borde text-tenue hover:text-texto' => $estado !== $e])>
                    {{ $e ?: 'Todas' }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Folio</th>
                    <th class="px-4 py-3">Plano</th>
                    <th class="px-4 py-3">Mueble</th>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3 text-right">Cant.</th>
                    <th class="px-4 py-3 text-right">Precio unitario</th>
                    <th class="px-4 py-3 text-right">Total pedido</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Riesgo</th>
                    <th class="px-4 py-3">Actualizada</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($this->lista as $c)
                    <tr wire:key="cot-{{ $c->id }}" class="hover:bg-panel">
                        <td class="px-4 py-3 font-mono font-semibold whitespace-nowrap">
                            <a href="{{ route('cotizaciones.editor', $c) }}" wire:navigate class="hover:text-sula">{{ $c->folio }}</a>
                            @if ($c->revision > 0)<span class="text-tenue"> R{{ $c->revision }}</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $c->plano_codigo }} <span class="text-tenue">{{ $c->plano_rev }}</span></td>
                        <td class="min-w-48 px-4 py-3">
                            <a href="{{ route('cotizaciones.editor', $c) }}" wire:navigate class="line-clamp-1 hover:text-sula">{{ $c->descripcion }}</a>
                        </td>
                        <td class="px-4 py-3 text-tenue">{{ $c->cliente?->nombre ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ $c->cantidad }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::dinero($c->precio_lista) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold">{{ F::dinero($c->total_lista) }}</td>
                        <td class="px-4 py-3"><x-chip-estado :estado="$c->estado" :vencida="$c->estaVencida()" /></td>
                        <td class="px-4 py-3">
                            @if ($c->riesgo_lista) <x-chip-riesgo :nivel="$c->riesgo_lista" />
                            @else <span class="text-xs text-tenue">congelada</span> @endif
                        </td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-tenue">{{ F::fecha($c->actualizado_en) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-tenue">Todavía no hay cotizaciones. Crea la primera con “+ Nueva cotización”.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($creando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('creando', false)"></button>
    <aside class="fixed inset-y-0 right-0 z-40 w-full max-w-xl overflow-y-auto border-l border-borde bg-panel px-6 py-6">
        <p class="font-mono text-xs tracking-widest text-tenue">CST · NUEVA</p>
        <h2 class="mt-1 mb-6 text-lg font-semibold">Nueva cotización</h2>
        @include('livewire.cotizaciones._encabezado', [
            'campo' => 'form', 'accion' => 'crear', 'textoBoton' => 'Crear y capturar despiece',
            'cancelar' => "\$set('creando', false)",
        ])
    </aside>
@endif
</div>
