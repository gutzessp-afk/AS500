<div class="mx-auto max-w-6xl" wire:poll.30s>
    <x-encabezado titulo="Bienvenido a SULA Core"
        descripcion="El sistema de SULA para pasar del plano al precio: planos y despiece, cotizaciones y catálogo de materiales en un solo lugar." />

    @unless ($bd)
        <div class="aviso-error mb-6">No hay conexión con la base de datos. Revisa que Docker esté corriendo (<span class="font-mono">docker compose up -d</span>).</div>
    @endunless

    {{-- Las áreas del sistema, explicadas: cada tarjeta lleva a su pantalla --}}
    <p class="mb-3 text-sm font-medium text-tenue">¿Qué quieres hacer?</p>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([
            ['produccion', 'carpeta', 'Producción', 'Planos y despiece', 'Busca un plano por su clave, súbelo o crea carpetas en Drive, y saca su despiece (subensambles y piezas) con la IA.', $planos, 'planos'],
            ['cotizaciones', 'cotizacion', 'Ventas', 'Cotizaciones', 'Calcula el precio de un mueble a partir de su despiece: materiales, mano de obra, overhead y margen. Al enviarla, los precios se congelan.', $borradores, 'en borrador'],
            ['materiales', 'material', 'Catálogo', 'Materiales', 'La lista de materiales que usa SULA (lámina, tubo, melamina, herrajes) con su precio vigente y su historial.', $materiales, 'activos'],
            ['journal', 'historial', 'Sistema', 'Historial de cambios', 'Todo lo que se cambia queda registrado: quién, qué y cuándo. Nada se puede borrar del historial.', $eventosHoy, 'cambios hoy'],
        ] as [$ruta, $icono, $area, $nombre, $texto, $numero, $unidad])
            <a href="{{ route($ruta) }}" wire:navigate
               class="tarjeta group flex gap-4 p-5 transition hover:border-sula/40 hover:shadow-md">
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-sula-suave text-sula">
                    <x-icono :nombre="$icono" class="size-6" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="text-xs font-medium tracking-wide text-tenue uppercase">{{ $area }}</span>
                    <span class="mt-0.5 flex items-center gap-2 text-lg font-semibold group-hover:text-sula">
                        {{ $nombre }} <x-icono nombre="flecha" class="size-4 opacity-0 transition group-hover:opacity-100" />
                    </span>
                    <span class="mt-1 block text-sm text-tenue">{{ $texto }}</span>
                    <span class="cifras mt-3 block text-sm"><b class="text-xl">{{ number_format($numero) }}</b> <span class="text-tenue">{{ $unidad }}</span></span>
                </span>
            </a>
        @endforeach
    </div>

    {{-- El camino completo, para que se entienda cómo se conectan los módulos --}}
    <div class="tarjeta mt-6 p-5">
        <p class="text-sm font-medium">Cómo se conecta todo</p>
        <ol class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
            @foreach ([
                ['1', 'Plano', 'Está en Drive o lo subes en Producción.'],
                ['2', 'Despiece', 'La IA lo propone; tú lo revisas y apruebas.'],
                ['3', 'Cotización', 'Con un clic, con los precios del catálogo.'],
                ['4', 'Envío', 'Se congela el precio y queda su historial.'],
            ] as [$n, $paso, $detalle])
                <li class="flex gap-3">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-sula text-xs font-semibold text-white">{{ $n }}</span>
                    <span><b>{{ $paso }}</b><span class="block text-tenue">{{ $detalle }}</span></span>
                </li>
            @endforeach
        </ol>
    </div>

    <p class="mt-6 text-xs text-tenue">
        Base de datos: <b @class(['text-ok' => $bd, 'text-sula' => ! $bd])>{{ $bd ? 'conectada' : 'sin conexión' }}</b>
        · Versión {{ config('sula.version') }} · Entorno {{ app()->environment() }}
    </p>
</div>
