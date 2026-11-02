{{-- Datos del plano, cliente, cantidad y acabado. Variables: $campo ('form' o 'datos'), $accion, $textoBoton --}}
<form wire:submit="{{ $accion }}" class="space-y-4">
    <div class="grid grid-cols-2 gap-3">
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="plano">Código del plano</label>
            <input id="plano" class="campo font-mono uppercase" placeholder="S/M, 2-1-0040-0465…" wire:model="{{ $campo }}.plano_codigo">
            @error("$campo.plano_codigo") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="rev">Revisión del plano</label>
            <input id="rev" class="campo font-mono uppercase" placeholder="REV-00" wire:model="{{ $campo }}.plano_rev">
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="desc">Descripción del mueble</label>
            <input id="desc" class="campo" placeholder="Módulo exhibidor" wire:model="{{ $campo }}.descripcion">
            @error("$campo.descripcion") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="cliente">Cliente</label>
            <select id="cliente" class="campo" wire:model="{{ $campo }}.cliente_id">
                <option value="">Sin cliente (usa parámetros de SULA)</option>
                @foreach ($clientes as $c)
                    <option value="{{ $c->id }}">{{ $c->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="dibujo">Dibujó</label>
            <input id="dibujo" class="campo" wire:model="{{ $campo }}.dibujo">
        </div>
        <div>
            <label class="etiqueta" for="cantidad">Cantidad de muebles</label>
            <input id="cantidad" type="number" min="1" class="campo cifras font-mono" wire:model="{{ $campo }}.cantidad">
            @error("$campo.cantidad") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="etiqueta" for="frente">Frente (m)</label>
            <input id="frente" class="campo cifras font-mono" inputmode="decimal" placeholder="2.00" wire:model="{{ $campo }}.frente_m">
            @error("$campo.frente_m") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="acabado">Acabado (pintura)</label>
            <select id="acabado" class="campo" wire:model="{{ $campo }}.pintura_material_id">
                <option value="">Sin pintura</option>
                @foreach ($acabados as $a)
                    <option value="{{ $a->id }}">{{ $a->clave }} · {{ $a->descripcion }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="vigencia">Vigencia de la cotización (días)</label>
            <input id="vigencia" type="number" min="1" class="campo cifras font-mono" wire:model="{{ $campo }}.vigencia_dias">
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="notas">Notas</label>
            <textarea id="notas" class="campo" rows="2" wire:model="{{ $campo }}.notas"></textarea>
        </div>
    </div>
    <div class="flex justify-end gap-2">
        @isset($cancelar)
            <button type="button" class="boton" wire:click="{{ $cancelar }}">Cancelar</button>
        @endisset
        <button type="submit" class="boton-primario">{{ $textoBoton }}</button>
    </div>
</form>
