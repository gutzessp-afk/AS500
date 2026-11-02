{{-- Formulario de alta / edición de material. Variable: $esNuevo --}}
@php
    $tipos = [
        'LINEAL' => 'Lineal (tubo, cubrecanto) · por m',
        'AREA' => 'Área (melamina, vidrio) · por m²',
        'AREA_KG' => 'Área → kg (lámina por calibre) · por kg',
        'PESO' => 'Peso (pintura, microalambre) · por kg',
        'PIEZA' => 'Pieza (herrajes, consumibles) · por pza',
    ];
    $esLamina = ($form['tipo_calculo'] ?? '') === 'AREA_KG';
@endphp
<form wire:submit="guardarMaterial" class="space-y-5">
    <div class="grid grid-cols-2 gap-3">
        @if ($esNuevo)
            <div class="col-span-2 sm:col-span-1">
                <label class="etiqueta" for="clave">Clave</label>
                <input id="clave" class="campo font-mono uppercase" placeholder="M22" wire:model="form.clave">
                @error('form.clave') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
            </div>
        @endif
        <div @class(['col-span-2', 'sm:col-span-1' => $esNuevo])>
            <label class="etiqueta" for="categoria">Categoría</label>
            <select id="categoria" class="campo" wire:model="form.categoria">
                @foreach (\App\Models\Material::CATEGORIAS as $c)
                    <option value="{{ $c }}">{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="descripcion">Descripción</label>
            <input id="descripcion" class="campo" wire:model="form.descripcion">
            @error('form.descripcion') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="tipo">Cómo se cobra (tipo de cálculo)</label>
            <select id="tipo" class="campo" wire:model.live="form.tipo_calculo">
                @foreach ($tipos as $valor => $nombre)
                    <option value="{{ $valor }}">{{ $nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="presentacion">Presentación comercial</label>
            <input id="presentacion" class="campo" placeholder="Tramo 6 m, Hoja 1.22×2.44…" wire:model.live.blur="form.presentacion">
            @error('form.presentacion') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        @if ($esLamina)
            <div class="col-span-2 sm:col-span-1">
                <label class="etiqueta" for="calibre">Calibre</label>
                <select id="calibre" class="campo" wire:model="form.calibre">
                    <option value="">Elige…</option>
                    @foreach ($calibres as $c)
                        <option value="{{ $c->calibre }}">Cal {{ $c->calibre }} · {{ $c->kg_m2 }} kg/m² · hoja {{ $c->peso_hoja_4x8 }} kg</option>
                    @endforeach
                </select>
                @error('form.calibre') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
            </div>
        @else
            <div class="col-span-2 sm:col-span-1">
                <label class="etiqueta" for="contenido">Contenido por presentación</label>
                <input id="contenido" class="campo cifras font-mono" inputmode="decimal" wire:model="form.contenido">
                @error('form.contenido') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
            </div>
        @endif
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="modo">Al cotizar se cobra</label>
            <select id="modo" class="campo" wire:model="form.modo_carga">
                <option value="CONSUMIDO">Solo lo consumido (sobrante a inventario)</option>
                <option value="COMPLETA">La presentación completa</option>
            </select>
        </div>
        <div class="col-span-2 sm:col-span-1">
            <label class="etiqueta" for="proveedor">Proveedor</label>
            <input id="proveedor" class="campo" wire:model="form.proveedor">
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="liga">Liga del precio</label>
            <input id="liga" class="campo" placeholder="https://…" wire:model="form.liga">
            @error('form.liga') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
        </div>
        <div class="col-span-2">
            <label class="etiqueta" for="notas">Notas</label>
            <textarea id="notas" class="campo" rows="2" wire:model="form.notas"></textarea>
        </div>
        @unless ($esNuevo)
            <label class="col-span-2 flex items-center gap-2 text-sm">
                <input type="checkbox" class="accent-sula" wire:model="form.activo"> Activo (si lo desactivas ya no aparece para cotizar)
            </label>
        @endunless
    </div>

    @if ($esNuevo)
        <fieldset class="border-t border-borde pt-4">
            <legend class="etiqueta pr-2">Precio inicial</legend>
            @include('livewire.materiales._form-precio', ['tipo' => $form['tipo_calculo'], 'presentacion' => $form['presentacion']])
        </fieldset>
    @endif

    <div class="flex justify-end gap-2">
        <button type="button" class="boton" wire:click="{{ $esNuevo ? 'cerrar' : '$set(\'panel\', \'ver\')' }}">Cancelar</button>
        <button type="submit" class="boton-primario">
            <span wire:loading.remove wire:target="guardarMaterial">{{ $esNuevo ? 'Crear material' : 'Guardar cambios' }}</span>
            <span wire:loading wire:target="guardarMaterial">Guardando…</span>
        </button>
    </div>
</form>
