{{-- Íconos de línea (SVG dibujados aquí mismo, sin librerías).
     Uso: <x-icono nombre="carpeta" class="size-5" />
     Toman el color del texto (stroke="currentColor"). --}}
@props(['nombre'])
@php
    $trazos = [
        'inicio' => '<path d="M3 11.5 12 4l9 7.5"/><path d="M5.5 10v9.5h5v-5h3v5h5V10"/>',
        'carpeta' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.6l2 2.2h8.4A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5Z"/>',
        'carpeta-nueva' => '<path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h4.6l2 2.2h8.4A1.5 1.5 0 0 1 21 8.7v9.8a1.5 1.5 0 0 1-1.5 1.5h-15A1.5 1.5 0 0 1 3 18.5Z"/><path d="M12 10.5v6M9 13.5h6"/>',
        'pdf' => '<path d="M6 3h8l4 4v14H6Z"/><path d="M14 3v4h4"/><path d="M8.5 13h7M8.5 16.5h5"/>',
        'subir' => '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3.5h16V16"/>',
        'cotizacion' => '<path d="M6 3h12v18l-3-2-3 2-3-2-3 2Z"/><path d="M9.5 8h5M9.5 11.5h5M9.5 15h3"/>',
        'material' => '<path d="m12 3 8 4.5v9L12 21l-8-4.5v-9Z"/><path d="m4 7.5 8 4.5 8-4.5M12 12v9"/>',
        'ajustes' => '<path d="M4 7h10M18 7h2M4 17h4M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
        'historial' => '<path d="M4 12a8 8 0 1 0 2.4-5.7"/><path d="M4 4v4h4"/><path d="M12 8v4.5l3 2"/>',
        'buscar' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.4-4.4"/>',
        'sincronizar' => '<path d="M20 11a8 8 0 0 0-14.5-4.5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.5 4.5L20 16"/><path d="M20 20v-4h-4"/>',
        'ia' => '<path d="M12 3.5 13.8 9 19.5 10.5 13.8 12 12 17.5 10.2 12 4.5 10.5 10.2 9Z"/><path d="M18.5 16v4M16.5 18h4"/>',
        'flecha' => '<path d="m9 6 6 6-6 6"/>',
        'atras' => '<path d="m15 6-6 6 6 6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'cerrar' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'descargar' => '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 19.5h16"/>',
        'ojo' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/>',
        'drive' => '<path d="M8.5 4h7l6 10.5-3.5 6h-12L2.5 14.5Z"/><path d="m8.5 4 6 10.5H2.5M15.5 4 9.5 14.5l2.5 6"/>',
        'ayuda' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.7"/><path d="M12 17h.01"/>',
    ];
@endphp
{{-- Tamaño por defecto size-5, salvo que se pida otro (size-4, size-6…) --}}
<svg {{ $attributes->class(['shrink-0', 'size-5' => ! str_contains((string) $attributes->get('class'), 'size-')]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $trazos[$nombre] ?? $trazos['pdf'] !!}</svg>
