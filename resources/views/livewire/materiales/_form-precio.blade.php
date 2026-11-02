{{-- Campos de un precio. Variables: $tipo (tipo de cálculo), $presentacion --}}
@php
    $unidades = ['LINEAL' => 'm', 'AREA' => 'm²', 'AREA_KG' => 'kg', 'PESO' => 'kg', 'PIEZA' => 'pza'];
    $n = is_numeric($precio['precio_publicado'] ?? null) ? (float) $precio['precio_publicado'] : null;
@endphp
<div class="grid grid-cols-2 gap-3">
    <div class="col-span-2 sm:col-span-1">
        <label class="etiqueta" for="precio">Precio publicado</label>
        <input id="precio" class="campo cifras font-mono" inputmode="decimal" placeholder="0.00"
               wire:model.live.debounce.400ms="precio.precio_publicado">
        @error('precio.precio_publicado') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
    </div>
    <div class="col-span-2 sm:col-span-1">
        <label class="etiqueta" for="precio-por">El precio es por</label>
        <select id="precio-por" class="campo" wire:model="precio.precio_por">
            <option value="PRESENTACION">{{ $presentacion ?: 'Presentación' }}</option>
            <option value="UNIDAD">Unidad ({{ $unidades[$tipo] ?? '' }})</option>
        </select>
    </div>
    <label class="col-span-2 flex items-center gap-2 text-sm sm:col-span-1">
        <input type="checkbox" class="accent-sula" wire:model.live="precio.incluye_iva"> El precio ya incluye IVA
    </label>
    <div class="col-span-2 sm:col-span-1">
        <label class="etiqueta" for="confianza">Confianza</label>
        <select id="confianza" class="campo" wire:model="precio.confianza">
            <option value="ALTA">Alta: compra real o precio SULA</option>
            <option value="MEDIA">Media: distribuidor con liga</option>
            <option value="BAJA">Baja: estimado, hay que cotizar</option>
        </select>
    </div>
    <div class="col-span-2">
        <label class="etiqueta" for="fuente">Fuente</label>
        <input id="fuente" class="campo" placeholder="Proveedor, cotización, imagen enviada…" wire:model="precio.fuente">
    </div>
    @if ($n !== null && ($precio['incluye_iva'] ?? false))
        <p class="cifras col-span-2 font-mono text-xs text-tenue">Sin IVA: ${{ number_format($n / 1.16, 4) }}</p>
    @endif
</div>
