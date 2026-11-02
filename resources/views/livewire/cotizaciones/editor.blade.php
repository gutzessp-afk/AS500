@use('App\Support\Formato', 'F')
@php
    $c = $cotizacion;
    $calc = $this->calculo;
    $r = $calc['resultado'];
    $s = $r['resumen'] ?? null;
    $versionActual = $c->versiones->firstWhere('revision', $c->revision);
    $pestanas = ['despiece' => 'Despiece', 'compras' => 'Compras', 'resumen' => 'Resumen', 'simulador' => 'Simulador', 'versiones' => 'Versiones', 'datos' => 'Datos'];
@endphp

<div>
<section class="mx-auto max-w-7xl space-y-6">
    {{-- Encabezado y acciones --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-tenue">
                <a href="{{ route('cotizaciones') }}" wire:navigate class="hover:text-texto">Cotizaciones</a> ›
                <span class="font-mono">{{ $c->folio }}</span> · Revisión {{ $c->revision }}
            </p>
            <h1 class="mt-1 text-3xl font-semibold">{{ $c->descripcion }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-tenue">
                <span class="font-mono">{{ $c->plano_codigo }} · {{ $c->plano_rev }}</span>
                <span>· {{ $c->cliente?->nombre ?? 'Sin cliente' }}</span>
                <span>· {{ $c->cantidad }} mueble{{ $c->cantidad === 1 ? '' : 's' }}</span>
                @if ($c->dibujo)<span>· Dibujó {{ $c->dibujo }}</span>@endif
                <x-chip-estado :estado="$c->estado" :vencida="$c->estaVencida()" />
                @if ($r) <x-chip-riesgo :nivel="$r['riesgo']['nivel']" /> @endif
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($c->esBorrador())
                <button class="boton-primario" wire:click="enviar" @disabled(! $c->piezas->count())
                        wire:confirm="Se congelan los precios de hoy y ya no se podrá editar. ¿Enviar al cliente?">
                    Enviar al cliente
                </button>
            @elseif ($c->estado === 'ENVIADA')
                <button class="boton border-ok/50 text-ok" wire:click="decidir('APROBADA')">Aprobada</button>
                <button class="boton border-sula/50 text-sula" wire:click="decidir('RECHAZADA')">Rechazada</button>
            @endif
            @unless ($c->esBorrador())
                <button class="boton" wire:click="abrirRevision">Nueva revisión</button>
            @endunless
            <button class="boton" wire:click="duplicar">Duplicar</button>
        </div>
    </div>

    @if ($error) <div class="aviso-error">{{ $error }}</div> @endif

    @if ($calc['congelada'])
        <div class="aviso-alerta">
            Precios congelados el {{ F::fecha($versionActual?->creado_en) }}: aunque cambie el catálogo, esta revisión no se mueve.
            Para modificarla abre una <strong>nueva revisión</strong>.
            @if ($c->estaVencida()) <span class="text-sula">La vigencia de {{ $c->vigencia_dias }} días ya pasó.</span> @endif
        </div>
    @endif
    @if ($calc['error']) <div class="aviso-error">{{ $calc['error'] }}</div> @endif

    {{-- Números grandes --}}
    @if ($s)
        <div class="grid grid-cols-2 gap-px border border-borde bg-borde md:grid-cols-3 xl:grid-cols-6">
            @foreach (['Materiales' => $s['materiales'], 'Mano de obra' => $s['mano_obra'], 'Overhead' => $s['overhead'], 'Costo' => $s['costo_total']] as $k => $v)
                <div class="kpi">
                    <p class="text-xs text-tenue">{{ $k }}</p>
                    <p class="cifras mt-2 font-mono text-lg md:text-xl">{{ F::dinero($v) }}</p>
                </div>
            @endforeach
            <div class="kpi">
                <p class="text-xs text-tenue">Precio / mueble</p>
                <p class="cifras mt-2 font-mono text-xl font-semibold text-sula md:text-2xl">{{ F::dinero($s['precio_venta']) }}</p>
            </div>
            <div class="kpi">
                <p class="text-xs text-tenue">Pedido × {{ $s['cantidad'] }}</p>
                <p class="cifras mt-2 font-mono text-xl font-semibold md:text-2xl">{{ F::dinero($s['total_pedido']) }}</p>
            </div>
        </div>
    @endif

    {{-- Pestañas --}}
    <nav class="flex gap-1 overflow-x-auto border-b border-borde">
        @foreach ($pestanas as $id => $nombre)
            <button wire:click="$set('pestana', '{{ $id }}')"
                @class(['-mb-px border-b-2 px-4 py-2.5 font-mono text-xs tracking-widest whitespace-nowrap uppercase transition-colors',
                        'border-sula text-texto' => $pestana === $id, 'border-transparent text-tenue hover:text-texto' => $pestana !== $id])>
                {{ $nombre }}
                @if ($id === 'despiece')<span class="text-tenue">· {{ $c->piezas->count() }}</span>@endif
                @if ($id === 'versiones' && $c->versiones->count())<span class="text-tenue">· {{ $c->versiones->count() }}</span>@endif
            </button>
        @endforeach
    </nav>

    @include('livewire.cotizaciones.pestanas.'.$pestana)
</section>
</div>
