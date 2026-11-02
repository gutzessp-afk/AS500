@use('App\Support\Formato', 'F')
{{-- Simulador "¿y si…?": nada se guarda. Cada movimiento recalcula con el mismo motor. --}}
@php
    $delta = fn ($a, $b) => (float) $b - (float) $a;
@endphp

@if (! $c->piezas->count())
    <p class="text-tenue">Captura el despiece para simular.</p>
@else
<div class="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
    <section class="space-y-5 border border-borde bg-panel p-5">
        <div class="flex items-center justify-between">
            <p class="etiqueta mb-0">¿Y si…?</p>
            <button class="boton px-3 py-1" wire:click="reiniciarSimulador">Reiniciar</button>
        </div>

        @foreach ([['simMargen', 'Margen sobre precio', 0, 60], ['simMo', 'Mano de obra', 0, 60], ['simOh', 'Overhead', 0, 40]] as [$prop, $nombre, $min, $max])
            <label class="block">
                <span class="flex justify-between text-sm"><span>{{ $nombre }}</span><span class="cifras font-mono">{{ $$prop }}%</span></span>
                <input type="range" class="mt-2 w-full accent-sula" min="{{ $min }}" max="{{ $max }}" step="0.5" wire:model.live.debounce.150ms="{{ $prop }}">
            </label>
        @endforeach

        <label class="block">
            <span class="text-sm">Cantidad de muebles</span>
            <input type="number" min="1" class="campo mt-2 w-32 font-mono" wire:model.live.debounce.300ms="simCantidad">
        </label>

        <div class="border-t border-borde pt-4">
            <p class="etiqueta">Si cambia el precio de…</p>
            @foreach ([['simMetal', 'Acero / metal'], ['simMadera', 'Madera / melamina'], ['simHerrajes', 'Herrajes']] as [$prop, $nombre])
                @php($v = $$prop)
                <label class="mt-3 block">
                    <span class="flex justify-between text-sm">
                        <span>{{ $nombre }}</span>
                        <span @class(['cifras font-mono', 'text-sula' => $v > 0, 'text-ok' => $v < 0])>{{ $v > 0 ? '+' : '' }}{{ $v }}%</span>
                    </span>
                    <input type="range" class="mt-2 w-full accent-sula" min="-30" max="30" step="1" wire:model.live.debounce.150ms="{{ $prop }}">
                </label>
            @endforeach
        </div>
    </section>

    <section class="space-y-4">
        @if ($sim)
            @php
                [$b, $x] = [$sim['base']['resumen'], $sim['sim']['resumen']];
                $dp = $delta($b['precio_venta'], $x['precio_venta']);
                $rel = (float) $b['precio_venta'] ? $dp / (float) $b['precio_venta'] * 100 : 0;
            @endphp
            <div class="grid gap-px border border-borde bg-borde sm:grid-cols-2">
                <div class="kpi sm:col-span-2">
                    <p class="text-xs text-tenue">PRECIO POR MUEBLE SIN IVA</p>
                    <p class="cifras mt-2 font-mono text-4xl font-semibold">{{ F::dinero($x['precio_venta']) }}</p>
                    <p @class(['cifras mt-1 font-mono text-sm', 'text-sula' => $dp > 0, 'text-ok' => $dp < 0, 'text-tenue' => $dp == 0])>
                        {{ $dp >= 0 ? '+' : '' }}{{ F::dinero($dp) }} ({{ $rel >= 0 ? '+' : '' }}{{ number_format($rel, 1) }}%) vs. {{ F::dinero($b['precio_venta']) }}
                    </p>
                </div>
                <div class="kpi">
                    <p class="text-xs text-tenue">TOTAL DEL PEDIDO × {{ $x['cantidad'] }}</p>
                    <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($x['total_pedido']) }}</p>
                </div>
                <div class="kpi">
                    <p class="text-xs text-tenue">UTILIDAD POR MUEBLE</p>
                    <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($x['utilidad']) }}</p>
                    <p class="cifras mt-1 font-mono text-xs text-tenue">{{ $delta($b['utilidad'], $x['utilidad']) >= 0 ? '+' : '' }}{{ F::dinero($delta($b['utilidad'], $x['utilidad'])) }}</p>
                </div>
                <div class="kpi"><p class="text-xs text-tenue">MATERIALES</p><p class="cifras mt-2 font-mono text-xl">{{ F::dinero($x['materiales']) }}</p></div>
                <div class="kpi"><p class="text-xs text-tenue">COSTO TOTAL</p><p class="cifras mt-2 font-mono text-xl">{{ F::dinero($x['costo_total']) }}</p></div>
            </div>

            <div class="border border-borde bg-panel p-5">
                <label class="etiqueta" for="precio-cliente">¿El cliente pide un precio? Escríbelo y ve tu margen real</label>
                <div class="flex flex-wrap items-center gap-4">
                    <input id="precio-cliente" class="campo w-48 font-mono" inputmode="decimal" placeholder="11000" wire:model.live.debounce.300ms="precioCliente">
                    @if (is_numeric($precioCliente) && (float) $precioCliente > 0)
                        @php
                            $ganancia = (float) $precioCliente - (float) $x['costo_total'];
                            $margenReal = $ganancia / (float) $precioCliente * 100;
                        @endphp
                        <p @class(['cifras font-mono text-lg font-semibold', 'text-sula' => $margenReal < 15, 'text-alerta' => $margenReal >= 15 && $margenReal < 25, 'text-ok' => $margenReal >= 25])>
                            Margen {{ number_format($margenReal, 1) }}%
                        </p>
                        <p class="text-xs text-tenue">{{ $ganancia < 0 ? 'Pierdes dinero en cada mueble.' : 'Ganas '.F::dinero($ganancia).' por mueble.' }}</p>
                    @endif
                </div>
            </div>
        @else
            <p class="text-tenue">Revisa los valores del simulador (el margen debe ser menor a 100%).</p>
        @endif
    </section>
</div>
@endif
