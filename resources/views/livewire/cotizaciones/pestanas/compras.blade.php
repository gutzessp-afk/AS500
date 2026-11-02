@use('App\Support\Formato', 'F')
{{-- Hoja de compras: de consumo a presentaciones y costo. En borrador se ajusta plan de corte, merma y modo. --}}
@php
    $editable = $c->esBorrador();
    $compras = $r['compras'] ?? [];
    $conAjuste = $c->ajustes->pluck('material_id')->all();
@endphp

@if (! $compras)
    <p class="text-tenue">Captura el despiece para ver qué hay que comprar.</p>
@else
<div class="overflow-x-auto border border-borde">
    <table class="w-full min-w-[1200px] text-left text-sm">
        <thead class="tabla-cabecera">
            <tr>
                <th class="px-3 py-3">Material</th>
                <th class="px-2 py-3 text-right">Neto / mueble</th>
                <th class="px-2 py-3 text-right" title="Presentaciones por mueble ya resueltas por el plan de corte. Manda sobre la merma.">Plan corte</th>
                <th class="px-2 py-3 text-right">Merma %</th>
                <th class="px-2 py-3 text-right">Bruto / mueble</th>
                <th class="px-2 py-3 text-right">Aprov.</th>
                <th class="px-2 py-3 text-right">Comprar (×{{ $c->cantidad }})</th>
                <th class="px-2 py-3">Se cobra</th>
                <th class="px-2 py-3 text-right">$ unidad</th>
                <th class="px-2 py-3 text-right">$ / mueble</th>
                <th class="px-2 py-3 text-right">$ pedido</th>
                <th class="px-2 py-3">Confianza</th>
            </tr>
        </thead>
        <tbody class="cifras divide-y divide-borde">
            @foreach ($compras as $x)
                @php($aprov = (float) $x['aprovechamiento'])
                <tr wire:key="compra-{{ $x['material_id'] }}">
                    <td class="px-3 py-2">
                        <p class="font-mono text-xs font-semibold">
                            {{ $x['clave'] }}
                            @if (in_array($x['material_id'], $conAjuste)) <span class="ml-1 text-alerta" title="Tiene ajuste en esta cotización">●</span> @endif
                        </p>
                        <p class="line-clamp-1 max-w-64 text-xs text-tenue">{{ $x['descripcion'] }}</p>
                    </td>
                    <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">{{ F::num($x['consumo_neto']) }} {{ F::unidad($x['unidad']) }}</td>
                    <td class="px-1 py-1 text-right">
                        @if ($editable)
                            <input class="campo w-20 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" placeholder="—"
                                   value="{{ $x['plan_corte'] }}" wire:change="guardarAjuste({{ $x['material_id'] }}, 'plan', $event.target.value)">
                        @else
                            <span class="font-mono text-xs">{{ $x['plan_corte'] ?? '—' }}</span>
                        @endif
                    </td>
                    <td class="px-1 py-1 text-right">
                        @if ($editable)
                            <input class="campo w-16 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" @disabled($x['plan_corte'])
                                   value="{{ F::num((float) $x['merma_pct'] * 100) }}" wire:change="guardarAjuste({{ $x['material_id'] }}, 'merma', $event.target.value)">
                        @else
                            <span class="font-mono text-xs">{{ F::pct($x['merma_pct']) }}</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">{{ F::num($x['consumo_bruto']) }} {{ F::unidad($x['unidad']) }}</td>
                    <td @class(['px-2 py-2 text-right font-mono text-xs', 'text-alerta' => $aprov < 0.7, 'text-tenue' => $aprov >= 0.7])>{{ round($aprov * 100) }}%</td>
                    <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">
                        <span class="font-semibold">{{ $x['presentaciones_comprar'] }}</span> <span class="text-tenue">× {{ $x['presentacion'] }}</span>
                        @if ((float) $x['sobrante'] > 0) <p class="text-[10px] text-tenue">sobra {{ F::num(round((float) $x['sobrante'], 2)) }} {{ F::unidad($x['unidad']) }}</p> @endif
                    </td>
                    <td class="px-1 py-1">
                        @if ($editable)
                            <select class="campo min-w-32 px-2 py-1 text-xs" wire:change="guardarAjuste({{ $x['material_id'] }}, 'modo', $event.target.value)">
                                <option value="CONSUMIDO" @selected($x['modo_carga'] === 'CONSUMIDO')>Consumido</option>
                                <option value="COMPLETA" @selected($x['modo_carga'] === 'COMPLETA')>Completa</option>
                            </select>
                        @else
                            <span class="font-mono text-xs">{{ $x['modo_carga'] === 'COMPLETA' ? 'Completa' : 'Consumido' }}</span>
                        @endif
                    </td>
                    <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">{{ F::dineroFino($x['precio_unitario']) }}</td>
                    <td class="px-2 py-2 text-right font-mono font-semibold">{{ F::dinero($x['costo_por_mueble']) }}</td>
                    <td class="px-2 py-2 text-right font-mono text-xs">{{ F::dinero($x['costo_pedido']) }}</td>
                    <td class="px-2 py-2 whitespace-nowrap">
                        <x-chip-confianza :valor="$x['confianza'] ?: null" />
                        @if ($x['vencido']) <span class="ml-1 font-mono text-[10px] text-sula">VENCIDO</span> @endif
                        @if ($editable && in_array($x['material_id'], $conAjuste))
                            <button class="ml-1 text-xs text-tenue hover:text-sula" title="Quitar ajuste" wire:click="quitarAjuste({{ $x['material_id'] }})">↺</button>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot class="cifras border-t-2 border-borde bg-panel font-mono">
            <tr>
                <td class="px-3 py-3 text-xs tracking-widest text-tenue uppercase" colspan="9">Total materiales</td>
                <td class="px-2 py-3 text-right font-semibold">{{ F::dinero($s['materiales']) }}</td>
                <td class="px-2 py-3 text-right text-xs">{{ F::dinero((float) $s['materiales'] * $c->cantidad) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
<p class="mt-2 font-mono text-xs text-tenue">
    Consumido = se cobra solo lo usado (el sobrante va a inventario) · Completa = se cobran las presentaciones enteras
    repartidas entre los {{ $c->cantidad }} mueble(s) · Aprovechamiento bajo 70% en ámbar.
</p>
@endif
