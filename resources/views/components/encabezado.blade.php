{{-- Encabezado de cada pantalla: dónde estás, qué es y qué puedes hacer.
     Uso:
     <x-encabezado area="Producción" titulo="Planos y despiece" descripcion="…">
         <x-slot:acciones> <button class="boton-primario">…</button> </x-slot:acciones>
         <x-slot:ayuda> <li>Paso 1…</li> <li>Paso 2…</li> </x-slot:ayuda>
     </x-encabezado> --}}
@props(['area' => null, 'titulo', 'descripcion' => null])

<header class="mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($area)
                <p class="text-xs font-medium tracking-wide text-sula uppercase">{{ $area }}</p>
            @endif
            <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $titulo }}</h1>
            @if ($descripcion)
                <p class="mt-1.5 max-w-2xl text-sm text-tenue">{{ $descripcion }}</p>
            @endif
        </div>
        @isset($acciones)
            <div class="flex flex-wrap items-center gap-2">{{ $acciones }}</div>
        @endisset
    </div>

    {{-- "¿Cómo se usa?": plegable, para la gente que entra por primera vez --}}
    @isset($ayuda)
        <details class="group mt-4 max-w-3xl rounded-lg border border-borde bg-panel px-4 py-2.5 text-sm">
            <summary class="flex cursor-pointer list-none items-center gap-2 font-medium text-tenue select-none hover:text-texto">
                <x-icono nombre="ayuda" class="size-4" /> ¿Cómo se usa esta pantalla?
                <x-icono nombre="flecha" class="ml-auto size-4 transition group-open:rotate-90" />
            </summary>
            <ol class="mt-3 mb-1 list-decimal space-y-1.5 pl-5 text-tenue marker:text-sula">{{ $ayuda }}</ol>
        </details>
    @endisset
</header>
