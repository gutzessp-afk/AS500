@use('App\Support\Formato', 'F')

<div class="mx-auto max-w-7xl"
     {{-- Arrastrar y soltar PDFs en cualquier parte de la pantalla --}}
     x-data="{ arrastrando: false }"
     x-on:dragover.prevent="arrastrando = true"
     x-on:dragleave.self="arrastrando = false"
     x-on:drop.prevent="arrastrando = false; @if ($this->puedeEscribir) $wire.uploadMultiple('archivos', $event.dataTransfer.files) @endif">

    <x-encabezado area="Producción" titulo="Planos y despiece"
        descripcion="Todos los planos de la carpeta PLANOS de Drive. Busca por clave, abre un plano para verlo y sacar su despiece (subensambles y piezas) con la IA.">
        <x-slot:acciones>
            <button class="boton" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar"
                    title="Trae de {{ $fuente === 'DRIVE' ? 'Drive' : 'la carpeta' }} lo que se haya agregado o cambiado">
                <x-icono nombre="sincronizar" class="size-4" wire:loading.class="animate-spin" wire:target="sincronizar" />
                <span wire:loading.remove wire:target="sincronizar">Actualizar desde {{ $fuente === 'DRIVE' ? 'Drive' : 'carpeta' }}</span>
                <span wire:loading wire:target="sincronizar">Leyendo…</span>
            </button>
            @if ($this->puedeEscribir)
                <button class="boton" wire:click="$set('creandoCarpeta', true)">
                    <x-icono nombre="carpeta-nueva" class="size-4" /> Nueva carpeta
                </button>
                <label class="boton-primario cursor-pointer">
                    <x-icono nombre="subir" class="size-4" /> Subir PDFs
                    <input type="file" class="hidden" accept="application/pdf,.pdf" multiple wire:model="archivos">
                </label>
            @else
                <a class="boton-primario" href="{{ route('google.conectar') }}">
                    <x-icono nombre="drive" class="size-4" /> Conectar Google para subir
                </a>
            @endif
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>Entra a una carpeta con un clic. Arriba ves en qué carpeta estás; regresa con la ruta.</li>
            <li>Escribe una clave o parte del nombre en el buscador para buscar en <b>todas</b> las carpetas.</li>
            <li><b>Subir PDFs</b> o arrástralos a la pantalla: se guardan en la carpeta que tienes abierta, en Drive, y una copia dentro del AS500.</li>
            <li>Para subir y crear carpetas, la primera vez dale <b>Conectar Google</b> con una cuenta que sea Editor de PLANOS.</li>
            <li><b>Nueva carpeta</b> también se crea en Drive. Desde aquí no se borra nada.</li>
            <li>Abre un plano y dale <b>Despiece</b>: te entrega el PDF de producción. Si algo sale en REVISAR, corrígelo y apruébalo.</li>
        </x-slot:ayuda>
    </x-encabezado>

    {{-- Avisos --}}
    @if ($mensaje)<div class="aviso-ok mb-4">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mb-4">{{ $error }}</div>@endif
    @error('archivos')<div class="aviso-error mb-4">{{ $message }}</div>@enderror
    @error('archivos.*')<div class="aviso-error mb-4">{{ $message }}</div>@enderror

    {{-- Resumen --}}
    @php($r = $this->resumen)
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Planos', number_format($r['planos']), 'text-texto'],
            ['Con despiece aprobado', number_format($r['aprobados']), 'text-ok'],
            ['Uso de IA este mes', number_format($r['tokens_mes']).' tokens', 'text-texto'],
            ['Última actualización', $r['ultima_sync'] ? \Illuminate\Support\Carbon::parse($r['ultima_sync'])->locale('es')->diffForHumans() : 'Nunca', 'text-texto'],
        ] as [$nombre, $valor, $color])
            <div class="tarjeta px-4 py-3">
                <p class="text-xs text-tenue">{{ $nombre }}</p>
                <p class="cifras mt-1 text-xl font-semibold {{ $color }}">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    {{-- Cuenta de Google con la que se escribe en Drive --}}
    @if ($fuente === 'DRIVE' && config('sula.planos.drive_escribir_con') === 'cuenta_conectada')
        <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <x-icono nombre="drive" class="size-5 text-tenue" />
            @if ($this->conexion)
                <span>Se sube a Drive como <b>{{ $this->conexion->email }}</b>
                    <span class="text-tenue">· conectado {{ $this->conexion->conectado_en->locale('es')->diffForHumans() }}</span></span>
                <form method="POST" action="{{ route('google.desconectar') }}" class="ml-auto"
                      onsubmit="return confirm('¿Desconectar la cuenta de Google? Ya no se podrá subir hasta volver a conectar.')">
                    @csrf
                    <button class="text-xs text-tenue underline hover:text-sula">Desconectar</button>
                </form>
            @else
                <span class="text-tenue">Para <b class="text-texto">crear carpetas y subir PDFs</b> conecta una cuenta de Google que sea Editor de la carpeta PLANOS. Ver planos y sacar despiece funciona sin esto.</span>
                <a class="boton ml-auto" href="{{ route('google.conectar') }}">Conectar Google</a>
            @endif
        </div>
    @endif

    {{-- Crear carpeta --}}
    @if ($creandoCarpeta)
        <form wire:submit="crearCarpeta" class="tarjeta mb-4 flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-64 flex-1">
                <label class="etiqueta" for="nombre-carpeta">
                    Nombre de la carpeta nueva, dentro de <b>{{ $this->actual?->ruta ?? 'la raíz' }}</b>
                </label>
                <input id="nombre-carpeta" class="campo" placeholder="Ej. VENTO, KFC 2026, Vitrinas" autofocus wire:model="nombreCarpeta">
                @error('nombreCarpeta')<p class="mt-1 text-xs text-sula">{{ $message }}</p>@enderror
            </div>
            <button type="button" class="boton" wire:click="$set('creandoCarpeta', false)">Cancelar</button>
            <button class="boton-primario" wire:loading.attr="disabled" wire:target="crearCarpeta">Crear en Drive</button>
        </form>
    @endif

    {{-- PDFs listos para subir --}}
    <div wire:loading.flex wire:target="archivos" class="tarjeta mb-4 items-center gap-3 p-4 text-sm text-tenue">
        <x-icono nombre="subir" class="size-4 animate-bounce" /> Cargando archivos…
    </div>
    @if ($archivos)
        <div class="tarjeta mb-4 p-4">
            <p class="text-sm font-medium">
                {{ count($archivos) }} PDF(s) listos para subir a <b>{{ $this->actual?->ruta ?? 'la raíz' }}</b>
            </p>
            <ul class="mt-3 divide-y divide-borde rounded-md border border-borde text-sm">
                @foreach ($archivos as $i => $a)
                    <li class="flex items-center gap-3 px-3 py-2" wire:key="sube-{{ $i }}">
                        <x-icono nombre="pdf" class="size-4 text-sula" />
                        <span class="min-w-0 flex-1 truncate">{{ $a->getClientOriginalName() }}</span>
                        <span class="cifras text-xs text-tenue">{{ number_format($a->getSize() / 1048576, 1) }} MB</span>
                        <button class="text-tenue hover:text-sula" wire:click="quitarArchivo({{ $i }})" title="Quitar"><x-icono nombre="cerrar" class="size-4" /></button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex justify-end gap-2">
                <button class="boton" wire:click="$set('archivos', [])">Cancelar</button>
                <button class="boton-primario" wire:click="subir" wire:loading.attr="disabled" wire:target="subir">
                    <span wire:loading.remove wire:target="subir">Subir a {{ $fuente === 'DRIVE' ? 'Drive' : 'la carpeta' }}</span>
                    <span wire:loading wire:target="subir">Subiendo…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Resultado de la última subida --}}
    @if ($resultados)
        <div class="tarjeta mb-4 p-4 text-sm">
            <div class="flex items-center justify-between">
                <p class="font-medium">Resultado de la subida</p>
                <button class="text-tenue hover:text-texto" wire:click="$set('resultados', [])"><x-icono nombre="cerrar" class="size-4" /></button>
            </div>
            <ul class="mt-2 space-y-1">
                @foreach ($resultados as $res)
                    <li class="flex gap-2">
                        <span @class(['font-medium', 'text-ok' => $res['ok'], 'text-sula' => ! $res['ok']])>{{ $res['ok'] ? '✓' : '✕' }}</span>
                        <span class="truncate">{{ $res['nombre'] }}</span>
                        <span class="text-tenue">— {{ $res['mensaje'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Barra: ruta + buscador + filtros --}}
    <div class="tarjeta relative overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-borde px-4 py-3">
            {{-- Ruta de migas: Producción › VENTO › VITRINAS --}}
            <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm">
                <button wire:click="abrir(null)" @class(['flex items-center gap-1.5 rounded px-1.5 py-0.5 hover:bg-panel-alto', 'font-semibold' => ! $carpeta])>
                    <x-icono nombre="drive" class="size-4 text-tenue" /> Todos los planos
                </button>
                @foreach ($camino as $paso)
                    <x-icono nombre="flecha" class="size-3.5 text-tenue" />
                    <button wire:click="abrir({{ $paso->id }})" @class(['rounded px-1.5 py-0.5 hover:bg-panel-alto', 'font-semibold' => $loop->last])>{{ $paso->nombre }}</button>
                @endforeach
            </nav>
            <div class="relative w-full sm:w-72">
                <x-icono nombre="buscar" class="pointer-events-none absolute top-2.5 left-3 size-4 text-tenue" />
                <input class="campo" style="padding-left: 2.25rem;" placeholder="Buscar clave o nombre en todas las carpetas" wire:model.live.debounce.300ms="buscar">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b border-borde bg-panel-alto/50 px-4 py-2">
            <span class="text-xs text-tenue">Despiece:</span>
            @foreach (['' => 'Todos', 'SIN' => 'Sin despiece', 'BORRADOR' => 'En revisión', 'APROBADO' => 'Aprobados'] as $valor => $nombre)
                <button wire:click="$set('filtro', '{{ $valor }}')"
                    @class(['chip', 'border-sula bg-sula-suave text-sula-oscuro' => $filtro === $valor, 'border-borde text-tenue hover:text-texto' => $filtro !== $valor])>
                    {{ $nombre }}
                </button>
            @endforeach
            @if (trim($buscar) !== '')
                <span class="ml-auto text-xs text-tenue">Buscando en todas las carpetas</span>
            @endif
        </div>

        {{-- Aviso al arrastrar --}}
        <div x-show="arrastrando" x-cloak
             class="absolute inset-0 z-10 grid place-items-center border-2 border-dashed border-sula bg-sula-suave/90 text-sula-oscuro">
            <div class="text-center">
                <x-icono nombre="subir" class="mx-auto size-8" />
                <p class="mt-2 font-medium">Suelta los PDFs para subirlos a {{ $this->actual?->ruta ?? 'la raíz' }}</p>
            </div>
        </div>

        {{-- Carpetas --}}
        @if ($this->subcarpetas->isNotEmpty())
            <div class="grid gap-2 border-b border-borde p-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->subcarpetas as $c)
                    <button wire:click="abrir({{ $c->id }})" wire:key="carpeta-{{ $c->id }}"
                            class="flex items-center gap-3 rounded-md border border-borde px-3 py-2.5 text-left transition hover:border-sula/40 hover:bg-sula-suave/40">
                        <x-icono nombre="carpeta" class="size-6 text-alerta" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{ trim($buscar) !== '' ? $c->ruta : $c->nombre }}</span>
                            <span class="block text-xs text-tenue">
                                {{ $c->planos_count }} {{ $c->planos_count === 1 ? 'plano' : 'planos' }}
                                @if (($c->hijas_count ?? 0) > 0) · {{ $c->hijas_count }} carpetas @endif
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Planos --}}
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="tabla-cabecera">
                    <tr>
                        <th class="px-4 py-2.5">Clave</th>
                        <th class="px-4 py-2.5">Archivo</th>
                        @if (trim($buscar) !== '')<th class="px-4 py-2.5">Carpeta</th>@endif
                        <th class="px-4 py-2.5">Rev.</th>
                        <th class="px-4 py-2.5">Despiece</th>
                        <th class="px-4 py-2.5">Modificado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borde">
                    @forelse ($this->planos as $p)
                        <tr wire:key="plano-{{ $p->id }}" class="group hover:bg-panel-alto/60">
                            <td class="px-4 py-3 font-mono text-sm font-semibold whitespace-nowrap">
                                <a href="{{ route('produccion.plano', $p) }}" wire:navigate class="group-hover:text-sula">{{ $p->clave }}</a>
                            </td>
                            <td class="min-w-64 px-4 py-3">
                                <a href="{{ route('produccion.plano', $p) }}" wire:navigate class="flex items-center gap-2">
                                    <x-icono nombre="pdf" class="size-4 text-sula" />
                                    <span class="line-clamp-1">{{ $p->nombre }}</span>
                                    @if ($p->subido_en_sistema)<span class="chip border-info/30 text-info">subido aquí</span>@endif
                                </a>
                            </td>
                            @if (trim($buscar) !== '')<td class="px-4 py-3 text-xs text-tenue">{{ $p->carpeta ?? 'Raíz' }}</td>@endif
                            <td class="px-4 py-3 font-mono text-xs">{{ $p->revision }}</td>
                            <td class="px-4 py-3"><x-chip-despiece :despiece="$p->aprobado ?? $p->ultimo" /></td>
                            <td class="px-4 py-3 text-xs whitespace-nowrap text-tenue">
                                {{ $p->modificado_en_origen ? F::fecha($p->modificado_en_origen) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-14 text-center text-sm text-tenue">
                                @if (trim($buscar) !== '')
                                    Ningún plano coincide con «{{ $buscar }}».
                                @elseif ($r['planos'] === 0)
                                    Todavía no hay planos. Dale a <b>Actualizar desde {{ $fuente === 'DRIVE' ? 'Drive' : 'carpeta' }}</b> o sube tus PDFs.
                                @else
                                    Esta carpeta no tiene PDFs. Arrastra aquí los que quieras subir.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($this->planos->count() === 300)
        <p class="mt-2 text-xs text-tenue">Se muestran los primeros 300: escribe más de la clave para afinar.</p>
    @endif
</div>
