{{-- Uso: <x-chip-estado :estado="$c->estado" :vencida="$c->estaVencida()" /> --}}
@props(['estado', 'vencida' => false])

@php
    $estilos = [
        'BORRADOR' => 'border-borde text-tenue',
        'ENVIADA' => 'border-alerta/50 text-alerta',
        'APROBADA' => 'border-ok/50 text-ok',
        'RECHAZADA' => 'border-sula/50 text-sula',
    ];
@endphp

<span class="chip {{ $vencida ? 'border-sula/50 text-sula' : ($estilos[$estado] ?? '') }}">
    {{ $vencida ? 'VENCIDA' : $estado }}
</span>
