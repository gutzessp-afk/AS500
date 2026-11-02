{{-- Estado del despiece de un plano. Uso: <x-chip-despiece :despiece="$d" /> --}}
@props(['despiece' => null])
@php
    [$texto, $clase] = match ($despiece?->estado) {
        null => ['Sin despiece', 'border-borde text-tenue'],
        'LEYENDO' => ['Leyendo…', 'border-alerta text-alerta animate-pulse'],
        'BORRADOR' => ['En revisión', 'border-alerta text-alerta'],
        'APROBADO' => ['Aprobado', 'border-ok text-ok'],
        'ERROR' => ['Error de IA', 'border-sula text-sula'],
        default => [$despiece->estado, 'border-borde text-tenue'],
    };
@endphp
<span {{ $attributes->class(['chip', $clase]) }}>
    {{ $texto }}@if ($despiece) <span class="opacity-60">v{{ $despiece->version }}</span>@endif
</span>
