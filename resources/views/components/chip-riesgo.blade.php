{{-- Uso: <x-chip-riesgo nivel="AMARILLO" /> --}}
@props(['nivel' => null])

@php
    $estilos = [
        'VERDE' => ['bg-ok', 'Confiable'],
        'AMARILLO' => ['bg-alerta', 'Revisar'],
        'ROJO' => ['bg-sula', 'En riesgo'],
    ];
@endphp

@if ($nivel && isset($estilos[$nivel]))
    <span class="inline-flex items-center gap-1.5 font-mono text-[11px] text-tenue">
        <span class="h-2 w-2 rounded-full {{ $estilos[$nivel][0] }}"></span>{{ $estilos[$nivel][1] }}
    </span>
@endif
