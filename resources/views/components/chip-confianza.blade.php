{{-- Uso: <x-chip-confianza :valor="$material->confianza" /> --}}
@props(['valor' => null])

@php
    $estilos = ['ALTA' => 'border-ok/40 text-ok', 'MEDIA' => 'border-alerta/40 text-alerta', 'BAJA' => 'border-sula/50 text-sula'];
@endphp

@if ($valor)
    <span class="chip {{ $estilos[$valor] ?? 'border-borde text-tenue' }}">{{ $valor }}</span>
@else
    <span class="chip border-borde text-tenue">SIN PRECIO</span>
@endif
