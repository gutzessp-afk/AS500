@use('App\Support\Formato', 'F')
{{-- Árbol del despiece: subensamble (× cantidad por mueble) → piezas (cantidad por subensamble).
     Variables: $subs, $mats (catálogo), $editable --}}
<div class="space-y-4">
    @foreach ($subs as $i => $s)
        <div wire:key="sub-{{ $i }}" class="tarjeta overflow-hidden">
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
