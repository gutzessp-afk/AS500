{{-- Datos generales de la cotización (solo en BORRADOR) --}}
@if ($c->esBorrador())
    <div class="max-w-2xl">
        @include('livewire.cotizaciones._encabezado', ['campo' => 'datos', 'accion' => 'guardarDatos', 'textoBoton' => 'Guardar datos'])
    </div>
@else
    <p class="text-tenue">La cotización está {{ $c->estado }}. Abre una nueva revisión para cambiar sus datos.</p>
@endif
