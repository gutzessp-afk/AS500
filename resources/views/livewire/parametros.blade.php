@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-5xl">
    <x-encabezado area="Catálogo" titulo="Precios y márgenes"
        descripcion="Los porcentajes con los que se calcula el precio: mano de obra, overhead y margen. Cada cliente usa los de SULA, a menos que tenga los suyos.">
        <x-slot:ayuda>
            <li>Mano de obra y overhead se suman al costo de materiales.</li>
            <li>Precio = costo ÷ (1 − margen). Con 30% de margen, un costo de $70 se vende en $100.</li>
            <li>Si un cliente negocia otros números, agrégale sus propios parámetros.</li>
        </x-slot:ayuda>
    </x-encabezado>

    <div class="mt-6 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Aplica a</th>
                    <th class="px-4 py-3 text-right">M.O.</th>
                    <th class="px-4 py-3 text-right">Overhead</th>
                    <th class="px-4 py-3 text-right">Margen</th>
                    <th class="px-4 py-3 text-right">Pintura</th>
                    <th class="px-4 py-3 text-right">Vigencia</th>
                    <th class="px-4 py-3">Actualizado</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @foreach ($this->parametros as $p)
                    <tr wire:key="par-{{ $p->id }}" @class(['bg-panel' => ! $p->cliente_id])>
                        <td class="px-4 py-3">
                            @if ($p->cliente_id) <span class="font-mono">{{ $p->cliente->nombre }}</span>
                            @else <span class="font-semibold">SULA · por defecto</span> @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::pct($p->mano_obra_pct) }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ F::pct($p->overhead_pct) }} <span class="text-tenue">{{ $p->overhead_incluye_mo ? '(+M.O.)' : '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold">{{ F::pct($p->margen_pct) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::num($p->rendimiento_pintura_m2kg) }} m²/kg</td>
                        <td class="px-4 py-3 text-right font-mono">{{ $p->vigencia_precios_dias }} d</td>
                        <td class="px-4 py-3 text-xs text-tenue">{{ F::fecha($p->actualizado_en) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button class="boton px-3 py-1" wire:click="abrir({{ $p->cliente_id ?? 'null' }})">Editar</button>
                            @if ($p->cliente_id)
                                <button class="boton px-3 py-1" wire:click="quitar({{ $p->cliente_id }})">Quitar</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-8">
        <p class="etiqueta">Clientes que usan los parámetros de SULA</p>
        <div class="flex flex-wrap gap-2">
            @forelse ($this->clientesSinPropios as $c)
                <button class="chip border-borde py-1.5 text-texto hover:border-sula" wire:click="abrir({{ $c->id }})" title="Darle parámetros propios">
                    {{ $c->nombre }} <span class="text-tenue">＋</span>
                </button>
            @empty
                <span class="text-sm text-tenue">Todos los clientes tienen parámetros propios.</span>
            @endforelse
        </div>
        <form wire:submit="agregarCliente" class="mt-4 flex max-w-sm gap-2">
            <input class="campo uppercase" placeholder="Nuevo cliente" wire:model="nuevoCliente">
            <button class="boton shrink-0" type="submit">Agregar</button>
        </form>
        @error('nuevoCliente') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
    </div>
</section>

@if ($editando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('editando', null)"></button>
    <aside class="fixed inset-y-0 right-0 z-40 w-full max-w-md overflow-y-auto border-l border-borde bg-panel px-6 py-6">
        <p class="font-mono text-xs tracking-widest text-tenue">PAR · EDITAR</p>
        <h2 class="mt-1 mb-6 text-lg font-semibold">{{ $editando['titulo'] }}</h2>

        <form wire:submit="guardar" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                @foreach ([['mo', 'Mano de obra %'], ['overhead', 'Overhead %'], ['margen', 'Margen sobre precio %'], ['pintura', 'Pintura m²/kg'], ['vigencia', 'Vigencia precios (días)']] as [$campo, $nombre])
                    <div>
                        <label class="etiqueta" for="{{ $campo }}">{{ $nombre }}</label>
                        <input id="{{ $campo }}" class="campo cifras font-mono" inputmode="decimal" wire:model.live.debounce.300ms="editando.{{ $campo }}">
                        @error("editando.$campo") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <label class="col-span-2 flex items-center gap-2 text-sm">
                    <input type="checkbox" class="accent-sula" wire:model.live="editando.overhead_incluye_mo"> El overhead se calcula sobre materiales + M.O.
                </label>
                <div class="col-span-2">
                    <label class="etiqueta" for="notas">Notas</label>
                    <textarea id="notas" class="campo" rows="2" wire:model="editando.notas"></textarea>
                </div>
            </div>

            @if ($ej = $this->ejemplo)
                <div class="cifras border border-borde bg-fondo p-4 font-mono text-xs">
                    <p class="etiqueta">Ejemplo con $1,000 de materiales</p>
                    <div class="grid grid-cols-2 gap-y-1">
                        <span class="text-tenue">Mano de obra</span><span class="text-right">{{ F::dinero($ej['mo']) }}</span>
                        <span class="text-tenue">Overhead</span><span class="text-right">{{ F::dinero($ej['oh']) }}</span>
                        <span class="text-tenue">Costo total</span><span class="text-right">{{ F::dinero($ej['costo']) }}</span>
                        <span class="font-semibold">Precio de venta</span>
                        <span class="text-right text-base font-semibold text-sula">{{ F::dinero($ej['precio']) }}</span>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <button type="button" class="boton" wire:click="$set('editando', null)">Cancelar</button>
                <button type="submit" class="boton-primario">Guardar</button>
            </div>
        </form>
    </aside>
@endif
</div>
