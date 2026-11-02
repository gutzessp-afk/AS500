@use('App\Support\Formato', 'F')
{{-- Despiece del plano por UN mueble. En borrador es editable; se recalcula al guardar. --}}
@php
    $editable = $c->esBorrador();
    $calculadas = $r['piezas'] ?? [];
    $automaticas = array_filter($calculadas, fn ($p) => $p['automatica']);
@endphp

<div class="space-y-4">
    @if ($editable)
        <div class="flex flex-wrap items-center gap-2">
            <button class="boton" wire:click="agregarFila">+ Pieza</button>
            <button class="boton" wire:click="$set('pegando', true)">Pegar desde Excel</button>
            <div class="ml-auto flex items-center gap-3">
                <span wire:dirty wire:target="filas" class="font-mono text-xs text-alerta">● Cambios sin guardar: los costos se recalculan al guardar</span>
                <button class="boton" wire:click="descartarCambios" wire:dirty wire:target="filas">Descartar</button>
                <button class="boton-primario" wire:click="guardarPiezas">
                    <span wire:loading.remove wire:target="guardarPiezas">Guardar y calcular</span>
                    <span wire:loading wire:target="guardarPiezas">Calculando…</span>
                </button>
            </div>
        </div>
        @if ($errors->any())
            <div class="aviso-error">@foreach ($errors->all() as $e) <p>{{ $e }}</p> @endforeach</div>
        @endif
    @endif

    <div class="overflow-x-auto border border-borde">
        <table class="w-full min-w-[1100px] text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="w-10 px-3 py-3">#</th>
                    <th class="px-2 py-3">Subensamble</th>
                    <th class="px-2 py-3">Pieza</th>
                    <th class="px-2 py-3">Material</th>
                    <th class="px-2 py-3 text-right">Cant.</th>
                    <th class="px-2 py-3 text-right">Largo mm</th>
                    <th class="px-2 py-3 text-right">Ancho mm</th>
                    <th class="px-2 py-3 text-right" title="Perímetro pintable (tubo) o caras (lámina)">Pintura</th>
                    <th class="px-2 py-3 text-right">Consumo</th>
                    <th class="px-2 py-3 text-right">$ ref.</th>
                    @if ($editable)<th class="w-16 px-2 py-3"></th>@endif
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($filas as $i => $f)
                    @php
                        $calc = $calculadas[$i] ?? null;
                        $tipo = $mats[$f['material_id']]->tipo_calculo ?? null;
                    @endphp
                    <tr wire:key="fila-{{ $i }}" @class(['align-top', 'bg-sula/5' => $calc && $calc['avisos']])>
                        <td class="px-3 py-2 font-mono text-xs text-tenue">{{ $i + 1 }}</td>
                        @if ($editable)
                            <td class="px-1 py-1"><input class="campo px-2 py-1 text-xs uppercase" wire:model="filas.{{ $i }}.subensamble"></td>
                            <td class="px-1 py-1"><input class="campo min-w-48 px-2 py-1 text-xs" wire:model="filas.{{ $i }}.descripcion"></td>
                            <td class="px-1 py-1">
                                <select class="campo min-w-44 px-2 py-1 text-xs" wire:model.live="filas.{{ $i }}.material_id">
                                    @foreach ($mats as $m)
                                        <option value="{{ $m->id }}">{{ $m->clave }} · {{ $m->descripcion }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1"><input class="campo w-20 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.cantidad"></td>
                            <td class="px-1 py-1"><input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.largo_mm" @disabled(in_array($tipo, ['PIEZA', 'PESO']))></td>
                            <td class="px-1 py-1"><input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.ancho_mm" @disabled(! in_array($tipo, ['AREA', 'AREA_KG']))></td>
                            <td class="px-1 py-1">
                                @if ($tipo === 'LINEAL')
                                    <input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" placeholder="perím. mm" title="Perímetro pintable en mm" wire:model="filas.{{ $i }}.perimetro_pintable_mm">
                                @elseif (in_array($tipo, ['AREA', 'AREA_KG']))
                                    <input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" placeholder="caras" title="Caras pintadas (0, 1 o 2)" wire:model="filas.{{ $i }}.caras_pintables">
                                @else
                                    <span class="block px-2 py-1 text-right text-tenue">—</span>
                                @endif
                            </td>
                        @else
                            <td class="px-2 py-2 font-mono text-xs text-tenue">{{ $f['subensamble'] ?: '—' }}</td>
                            <td class="px-2 py-2">{{ $f['descripcion'] }}</td>
                            <td class="px-2 py-2 font-mono text-xs">{{ $mats[$f['material_id']]->clave ?? $calc['clave'] ?? '' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ F::num($f['cantidad']) }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ (float) $f['largo_mm'] ? F::num($f['largo_mm']) : '—' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ (float) $f['ancho_mm'] ? F::num($f['ancho_mm']) : '—' }}</td>
                            <td class="px-2 py-2 text-right font-mono text-tenue">
                                {{ (float) $f['perimetro_pintable_mm'] ? F::num($f['perimetro_pintable_mm']).' mm' : ((float) $f['caras_pintables'] ? F::num($f['caras_pintables']).' c' : '—') }}
                            </td>
                        @endif
                        <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">
                            @if ($calc) {{ F::num($calc['consumo_total']) }} <span class="text-tenue">{{ F::unidad($calc['unidad']) }}</span> @else <span class="text-tenue">…</span> @endif
                        </td>
                        <td class="px-2 py-2 text-right font-mono text-xs">
                            {{ $calc ? F::dinero($calc['costo']) : '…' }}
                            @foreach ($calc['avisos'] ?? [] as $a)
                                <p class="mt-1 text-left font-sans text-[11px] text-sula">⚠ {{ $a }}</p>
                            @endforeach
                        </td>
                        @if ($editable)
                            <td class="px-2 py-2 whitespace-nowrap">
                                <button class="px-1 text-tenue hover:text-texto" title="Duplicar" wire:click="duplicarFila({{ $i }})">⧉</button>
                                <button class="px-1 text-tenue hover:text-sula" title="Quitar" wire:click="quitarFila({{ $i }})">✕</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-12 text-center text-tenue">Sin piezas. Agrega la primera o pega el despiece desde Excel.</td></tr>
                @endforelse

                @foreach ($automaticas as $a)
                    <tr class="bg-panel/50 text-tenue">
                        <td class="px-3 py-2 font-mono text-xs">auto</td>
                        <td class="px-2 py-2 font-mono text-xs">{{ $a['subensamble'] }}</td>
                        <td class="px-2 py-2" colspan="6">{{ $a['descripcion'] }}</td>
                        <td class="px-2 py-2 text-right font-mono text-xs">{{ F::num($a['consumo_total']) }} {{ F::unidad($a['unidad']) }}</td>
                        <td class="px-2 py-2 text-right font-mono text-xs">{{ F::dinero($a['costo']) }}</td>
                        @if ($editable)<td></td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($s)
        <p class="font-mono text-xs text-tenue">
            {{ count($filas) }} piezas · área metálica pintable {{ F::num($s['area_pintable_m2']) }} m² ·
            "$ ref." = consumo × precio por unidad (el costo real se decide en Compras)
        </p>
    @endif
</div>

{{-- Ventana: pegar desde Excel --}}
@if ($pegando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('pegando', false)"></button>
    <div class="fixed inset-x-4 top-12 z-40 mx-auto max-h-[85vh] max-w-3xl overflow-y-auto border border-borde bg-panel p-6 shadow-2xl">
        <p class="font-mono text-xs tracking-widest text-tenue">DESPIECE · IMPORTAR</p>
        <h2 class="mt-1 text-lg font-semibold">Pegar desde Excel</h2>
        <p class="mt-2 text-sm text-tenue">
            Copia las columnas en este orden y pégalas aquí. El material puede ser la clave (<span class="font-mono">M05</span>)
            o como aparece en el plano (<span class="font-mono">MELAMINA ROBLE (SMA) 15MM</span>).
        </p>
        <div class="mt-3 overflow-x-auto border border-borde bg-fondo px-3 py-2 font-mono text-[11px] whitespace-nowrap text-tenue">
            Descripción ⇥ Material ⇥ Cantidad ⇥ Largo mm ⇥ Ancho mm ⇥ Pintura* ⇥ Subensamble
        </div>
        <p class="mt-1 text-xs text-tenue">*Pintura = perímetro pintable en mm (tubo) o número de caras (lámina). Las últimas columnas son opcionales.</p>

        <textarea class="campo mt-4 font-mono text-xs" rows="10" wire:model="textoPegado"></textarea>

        @if ($importacion)
            <div class="mt-4 grid grid-cols-2 gap-px border border-borde bg-borde">
                <div class="bg-fondo px-4 py-3">
                    <p class="text-xs text-tenue">PIEZAS RECONOCIDAS</p>
                    <p class="cifras mt-1 font-mono text-2xl font-semibold text-ok">{{ count($importacion['piezas']) }}</p>
                </div>
                <div class="bg-fondo px-4 py-3">
                    <p class="text-xs text-tenue">LÍNEAS CON ERROR</p>
                    <p @class(['cifras mt-1 font-mono text-2xl font-semibold', 'text-sula' => count($importacion['errores'])])>{{ count($importacion['errores']) }}</p>
                </div>
            </div>
            <ul class="mt-3 space-y-1 text-xs">
                @foreach ($importacion['errores'] as $e)
                    <li class="border-l-2 border-sula pl-2"><span class="font-mono text-tenue">Línea {{ $e['linea'] }}:</span> {{ $e['mensaje'] }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button class="boton" wire:click="$set('pegando', false)">Cancelar</button>
            @if ($importacion && count($importacion['piezas']))
                <button class="boton" wire:click="aplicarPegado(true)">Reemplazar todo</button>
                <button class="boton-primario" wire:click="aplicarPegado(false)">Agregar {{ count($importacion['piezas']) }} piezas</button>
            @else
                <button class="boton-primario" wire:click="revisarPegado">Revisar</button>
            @endif
        </div>
    </div>
@endif
