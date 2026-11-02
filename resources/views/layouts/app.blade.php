<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}SULA Core</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php
    // Menú: área → [ruta, nombre, ícono, para qué sirve]
    // Nombres en palabras normales: que cualquiera en SULA sepa a dónde ir.
    $menu = [
        null => [
            ['inicio', 'Inicio', 'inicio', 'Resumen del sistema'],
        ],
        'Producción' => [
            ['produccion', 'Planos y despiece', 'carpeta', 'PDFs de Drive, carpetas y despiece con IA'],
        ],
        'Ventas' => [
            ['cotizaciones', 'Cotizaciones', 'cotizacion', 'Precio de un mueble a partir de su plano'],
        ],
        'Catálogo' => [
            ['materiales', 'Materiales', 'material', 'Materiales y sus precios'],
            ['parametros', 'Precios y márgenes', 'ajustes', 'Mano de obra, overhead y margen'],
        ],
        'Sistema' => [
            ['journal', 'Historial de cambios', 'historial', 'Quién cambió qué y cuándo'],
        ],
    ];
@endphp

{{-- x-data: estado del menú en celular (abierto/cerrado), lo maneja Alpine (viene con Livewire) --}}
<div class="flex min-h-screen" x-data="{ menu: false }">
    {{-- Fondo oscuro detrás del menú en celular --}}
    <div class="fixed inset-0 z-30 bg-black/30 md:hidden" x-show="menu" x-transition.opacity x-on:click="menu = false" x-cloak></div>

    {{-- Menú lateral --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-borde bg-panel transition-transform md:sticky md:top-0 md:h-screen md:translate-x-0"
           x-bind:class="{ '-translate-x-full': ! menu, 'translate-x-0': menu }">
        <div class="flex items-center gap-3 border-b border-borde px-5 py-4">
            <span class="grid size-9 place-items-center rounded-md bg-sula text-sm font-bold tracking-wider text-white">S</span>
            <div class="leading-tight">
                <p class="font-semibold tracking-[0.18em]">SULA <span class="font-normal tracking-normal text-tenue">Core</span></p>
                <p class="text-[11px] text-tenue">Calidad · Confianza · Innovación</p>
            </div>
            <button class="ml-auto rounded p-1 text-tenue hover:bg-panel-alto md:hidden" x-on:click="menu = false" aria-label="Cerrar menú">
                <x-icono nombre="cerrar" />
            </button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
            @foreach ($menu as $area => $items)
                <div>
                    @if ($area)
                        <p class="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-tenue uppercase">{{ $area }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach ($items as [$ruta, $nombre, $icono, $para])
                            @php $activo = request()->routeIs($ruta) || request()->routeIs($ruta.'.*'); @endphp
                            <li>
                                <a href="{{ route($ruta) }}" wire:navigate
                                   @class([
                                       'group flex items-start gap-3 rounded-md px-3 py-2 transition-colors',
                                       'bg-sula-suave text-sula-oscuro' => $activo,
                                       'text-texto hover:bg-panel-alto' => ! $activo,
                                   ])>
                                    <x-icono :nombre="$icono" :class="$activo ? 'mt-0.5 text-sula' : 'mt-0.5 text-tenue group-hover:text-texto'" />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $nombre }}</span>
                                        <span @class(['block text-xs', 'text-sula-oscuro/70' => $activo, 'text-tenue' => ! $activo])>{{ $para }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="flex items-center justify-between border-t border-borde px-5 py-3 text-xs text-tenue">
            <span>Usuario: <span class="font-medium text-texto">{{ config('sula.usuario') }}</span></span>
            <span class="font-mono">v{{ config('sula.version') }}</span>
        </div>
    </aside>

    {{-- Contenido --}}
    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Barra superior solo en celular --}}
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-borde bg-panel px-4 py-3 md:hidden">
            <button class="rounded p-1 hover:bg-panel-alto" x-on:click="menu = true" aria-label="Abrir menú">
                <x-icono nombre="menu" />
            </button>
            <span class="grid size-7 place-items-center rounded bg-sula text-xs font-bold text-white">S</span>
            <span class="font-semibold tracking-[0.18em]">SULA</span>
        </header>
        <main class="flex-1 px-4 py-6 md:px-8 lg:px-10 lg:py-8">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
