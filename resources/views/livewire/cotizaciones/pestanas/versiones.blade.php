@use('App\Support\Formato', 'F')
{{-- Revisiones enviadas (fotos congeladas) y comparación contra lo que se ve ahora --}}
@php
    $etiquetaActual = 'R'.$c->revision.($calc['congelada'] ? '' : ' · en vivo');
@endphp

<div class="grid gap-6 lg:grid-cols-[18rem_1fr]">
    <section>
        <p class="etiqueta">Revisiones enviadas</p>
        <ol class="divide-y divide-borde border border-borde">
            @forelse ($c->versiones as $v)
                <li wire:key="ver-{{ $v->revision }}" @class(['cifras px-4 py-3', 'bg-panel' => $v->revision === $c->revision && $calc['congelada']])>
                    <p class="flex justify-between font-mono text-sm"><span class="font-semibold">R{{ $v->revision }}</span><span>{{ F::dinero($v->precio_venta) }}</span></p>
                    <p class="mt-1 text-xs text-tenue">{{ F::fecha($v->creado_en) }} · {{ $v->creado_por ?? '—' }}</p>
                </li>
            @empty
                <li class="px-4 py-6 text-sm text-tenue">Todavía no se ha enviado ninguna revisión.</li>
            @endforelse
        </ol>
    </section>

    <section>
        @if ($cmp)
            <div class="flex flex-wrap items-center gap-3">
                <p class="etiqueta mb-0">Comparar</p>
                <select class="campo w-auto py-1 font-mono text-sm" wire:model.live="comparar">
                    @foreach ($cmp['opciones'] as $rev)
                        <option value="{{ $rev }}" @selected($rev === $cmp['revision'])>R{{ $rev }}</option>
                    @endforeach
                </select>
                <span class="font-mono text-sm text-tenue">→ {{ $etiquetaActual }}</span>
            </div>

            <div class="mt-4 overflow-x-auto border border-borde">
                <table class="w-full text-sm">
                    <thead class="tabla-cabecera">
                        <tr>
                            <th class="px-4 py-3 text-left">Concepto</th>
                            <th class="px-4 py-3 text-right">R{{ $cmp['revision'] }}</th>
                            <th class="px-4 py-3 text-right">{{ $etiquetaActual }}</th>
                            <th class="px-4 py-3 text-right">Diferencia</th>
                        </tr>
                    </thead>
                    <tbody class="cifras divide-y divide-borde font-mono">
                        @foreach (['materiales' => 'Materiales', 'mano_obra' => 'Mano de obra', 'overhead' => 'Overhead', 'costo_total' => 'Costo total', 'precio_venta' => 'Precio por mueble', 'total_pedido' => 'Total pedido'] as $k => $nombre)
                            @php($d = (float) $cmp['actual'][$k] - (float) $cmp['resumen'][$k])
                            <tr @class(['bg-panel font-semibold' => $k === 'precio_venta'])>
                                <td class="px-4 py-2 font-sans">{{ $nombre }}</td>
                                <td class="px-4 py-2 text-right">{{ F::dinero($cmp['resumen'][$k]) }}</td>
                                <td class="px-4 py-2 text-right">{{ F::dinero($cmp['actual'][$k]) }}</td>
                                <td @class(['px-4 py-2 text-right', 'text-sula' => $d > 0.004, 'text-ok' => $d < -0.004, 'text-tenue' => abs($d) <= 0.004])>
                                    {{ abs($d) < 0.005 ? '—' : ($d > 0 ? '+' : '').F::dinero($d) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="etiqueta mt-6">Qué materiales cambiaron (costo por mueble)</p>
            @if ($cmp['materiales']->count())
                <div class="overflow-x-auto border border-borde">
                    <table class="w-full text-sm">
                        <tbody class="cifras divide-y divide-borde font-mono">
                            @foreach ($cmp['materiales'] as $f)
                                @php($d = $f['b'] - $f['a'])
                                <tr>
                                    <td class="px-4 py-2 text-xs font-semibold">{{ $f['clave'] }}</td>
                                    <td class="px-4 py-2 font-sans text-xs text-tenue"><span class="line-clamp-1">{{ $f['descripcion'] }}</span></td>
                                    <td class="px-4 py-2 text-right text-xs">{{ F::dinero($f['a']) }}</td>
                                    <td class="px-4 py-2 text-right text-xs">{{ F::dinero($f['b']) }}</td>
                                    <td @class(['px-4 py-2 text-right text-xs', 'text-sula' => $d > 0, 'text-ok' => $d < 0])>{{ $d > 0 ? '+' : '' }}{{ F::dinero($d) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-tenue">Ningún material cambió de costo.</p>
            @endif
        @else
            <p class="text-sm text-tenue">Cuando envíes la cotización y abras una nueva revisión, aquí podrás comparar qué cambió entre una y otra.</p>
        @endif
    </section>
</div>
