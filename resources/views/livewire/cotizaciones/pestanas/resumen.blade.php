@use('App\Support\Formato', 'F')
{{-- De dónde sale el precio: composición, categorías, riesgo, precios con IVA y parámetros --}}
@if ($r)
@php
    $precio = max((float) $s['precio_venta'], 0.01);
    $composicion = [
        ['Materiales', (float) $s['materiales'], 'bg-texto'],
        ['Mano de obra', (float) $s['mano_obra'], 'bg-tenue'],
        ['Overhead', (float) $s['overhead'], 'bg-borde'],
        ['Utilidad', (float) $s['utilidad'], 'bg-sula'],
    ];
    $maxCat = max(array_map(fn ($x) => (float) $x['costo'], $r['categorias']) ?: [1]);
    $origen = ['SULA' => 'Parámetros de SULA (por defecto)', 'CLIENTE' => 'Parámetros propios del cliente', 'CONGELADO' => 'Parámetros congelados al enviar'][$calc['origen']] ?? '';
    $niveles = [
        'VERDE' => ['border-ok/40 text-ok', 'Precio confiable'],
        'AMARILLO' => ['border-alerta/40 text-alerta', 'Revisa antes de enviar'],
        'ROJO' => ['border-sula/50 text-sula', 'Precio en riesgo'],
    ];
    [$claseNivel, $tituloNivel] = $niveles[$r['riesgo']['nivel']];
    $p = $r['parametros'];
@endphp
<div class="grid gap-6 lg:grid-cols-[1.4fr_1fr]">
    <div class="space-y-6">
        <section class="border border-borde bg-panel p-5">
            <p class="etiqueta">Composición del precio de venta (por mueble)</p>
            <div class="mt-3 flex h-8 w-full overflow-hidden">
                @foreach ($composicion as [$nombre, $valor, $clase])
                    <div class="{{ $clase }}" style="width: {{ $valor / $precio * 100 }}%" title="{{ $nombre }}: {{ F::dinero($valor) }}"></div>
                @endforeach
            </div>
            <div class="cifras mt-4 grid grid-cols-2 gap-x-6 gap-y-2 font-mono text-sm sm:grid-cols-4">
                @foreach ($composicion as [$nombre, $valor, $clase])
                    <div>
                        <p class="flex items-center gap-2 text-[10px] tracking-widest text-tenue uppercase"><span class="h-2 w-2 {{ $clase }}"></span>{{ $nombre }}</p>
                        <p class="mt-1">{{ F::dinero($valor) }}</p>
                        <p class="text-xs text-tenue">{{ round($valor / $precio * 100, 1) }}%</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="border border-borde bg-panel p-5">
            <p class="etiqueta">Materiales por categoría</p>
            <div class="mt-3 space-y-3">
                @foreach ($r['categorias'] as $cat)
                    <div class="cifras grid grid-cols-[7rem_1fr_6rem_3.5rem] items-center gap-3 font-mono text-xs">
                        <span class="text-tenue">{{ $cat['categoria'] }}</span>
                        <div class="h-3 bg-fondo"><div class="h-3 bg-texto/80" style="width: {{ (float) $cat['costo'] / $maxCat * 100 }}%"></div></div>
                        <span class="text-right">{{ F::dinero($cat['costo']) }}</span>
                        <span class="text-right text-tenue">{{ F::pct($cat['pct_materiales']) }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="border p-5 {{ $claseNivel }}">
            <p class="font-mono text-[10px] tracking-widest uppercase">Riesgo · {{ $r['riesgo']['nivel'] }}</p>
            <p class="mt-1 text-lg font-semibold text-texto">{{ $tituloNivel }}</p>
            <div class="cifras mt-3 grid grid-cols-3 gap-4 font-mono text-sm text-texto">
                <div><p class="text-[10px] tracking-widest text-tenue">CONFIANZA BAJA</p><p class="mt-1">{{ F::dinero($r['riesgo']['monto_confianza_baja']) }}</p></div>
                <div><p class="text-[10px] tracking-widest text-tenue">PRECIOS VENCIDOS</p><p class="mt-1">{{ F::dinero($r['riesgo']['monto_vencido']) }}</p></div>
                <div><p class="text-[10px] tracking-widest text-tenue">% DE MATERIALES</p><p class="mt-1">{{ F::pct($r['riesgo']['pct_materiales']) }}</p></div>
            </div>
            @if ($r['avisos'] || $r['riesgo']['motivos'])
                <ul class="mt-4 space-y-1 text-sm">
                    @foreach (array_merge($r['avisos'], $r['riesgo']['motivos']) as $m)
                        <li class="border-l-2 border-current pl-2 text-tenue">{{ $m }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <div class="space-y-6">
        <section class="border border-borde bg-panel p-5">
            <p class="etiqueta">Precio</p>
            <dl class="cifras mt-2 space-y-2 font-mono text-sm">
                <div class="flex justify-between"><dt class="text-tenue">Costo total</dt><dd>{{ F::dinero($s['costo_total']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Utilidad</dt><dd>{{ F::dinero($s['utilidad']) }}</dd></div>
                <div class="flex justify-between border-t border-borde pt-2 text-base font-semibold"><dt>Precio sin IVA</dt><dd class="text-sula">{{ F::dinero($s['precio_venta']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">IVA ({{ F::pct($p['tasa_iva']) }})</dt><dd>{{ F::dinero($s['iva']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Precio con IVA</dt><dd>{{ F::dinero($s['precio_con_iva']) }}</dd></div>
                @if ((float) $s['precio_metro_lineal'])
                    <div class="flex justify-between"><dt class="text-tenue">Por metro lineal</dt><dd>{{ F::dinero($s['precio_metro_lineal']) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-borde pt-2 font-semibold"><dt>Pedido × {{ $s['cantidad'] }} sin IVA</dt><dd>{{ F::dinero($s['total_pedido']) }}</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Pedido con IVA</dt><dd>{{ F::dinero($s['total_pedido_con_iva']) }}</dd></div>
            </dl>
        </section>

        <section class="border border-borde bg-panel p-5">
            <p class="etiqueta">Cómo se calculó</p>
            <p class="text-sm">{{ $origen }}</p>
            <dl class="cifras mt-3 space-y-1 font-mono text-xs">
                <div class="flex justify-between"><dt class="text-tenue">Mano de obra</dt><dd>{{ F::pct($p['mano_obra_pct']) }} de materiales</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Overhead</dt><dd>{{ F::pct($p['overhead_pct']) }} de {{ $p['overhead_incluye_mo'] ? 'mat. + M.O.' : 'materiales' }}</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Margen</dt><dd>{{ F::pct($p['margen_pct']) }} sobre precio</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Pintura</dt><dd>{{ F::num($p['rendimiento_pintura_m2kg']) }} m²/kg</dd></div>
                <div class="flex justify-between"><dt class="text-tenue">Área pintable</dt><dd>{{ F::num($s['area_pintable_m2']) }} m²</dd></div>
            </dl>
            <p class="mt-3 text-xs text-tenue">Precio = costo ÷ (1 − margen). Todo sin IVA salvo donde se indica.</p>
        </section>
    </div>
</div>
@endif
