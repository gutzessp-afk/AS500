<section class="mx-auto max-w-6xl">
    <x-encabezado area="Sistema" titulo="Historial de cambios"
        descripcion="Cada alta, cambio o baja queda registrada aquí con quién la hizo y cuándo. Este registro no se puede editar ni borrar.">
        <x-slot:acciones>
            <select wire:model.live="tabla" class="campo w-auto text-sm">
                <option value="">Todo</option>
                @foreach ($tablas as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </x-slot:acciones>
    </x-encabezado>

    <div class="mt-6 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Tabla</th>
                    <th class="px-4 py-3">Operación</th>
                    <th class="px-4 py-3">Registro</th>
                    <th class="px-4 py-3">Usuario</th>
                    <th class="px-4 py-3">Cambio</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde font-mono text-xs">
                @forelse ($eventos as $e)
                    <tr class="hover:bg-panel" wire:key="evento-{{ $e->id }}">
                        <td class="px-4 py-3 text-tenue">{{ $e->id }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $e->ocurrido_en->format('d/m/y H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $e->tabla }}</td>
                        <td @class(['px-4 py-3 font-semibold',
                            'text-ok' => $e->operacion === 'INSERT',
                            'text-alerta' => $e->operacion === 'UPDATE',
                            'text-sula' => $e->operacion === 'DELETE'])>{{ $e->operacion }}</td>
                        <td class="px-4 py-3">{{ $e->registro_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-tenue">{{ $e->usuario ?? 'sistema' }}</td>
                        <td class="max-w-md px-4 py-3">
                            @forelse ($e->cambios() as $c)
                                <div class="truncate" title="{{ $c }}">{{ $c }}</div>
                            @empty
                                <span class="text-tenue">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-tenue">Sin eventos todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
