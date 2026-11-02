{{--
    PDF del despiece de producción (lo dibuja dompdf).
    dompdf entiende HTML + CSS "clásico": tablas, colores, bordes, border-radius, imágenes.
    NO entiende flex, grid ni Tailwind: aquí todo se acomoda con tablas y el CSS va en este archivo.

    Hojas:
      1. Portada: datos del plano y del pedido, miniatura, QR, indicadores, ruta de producción.
      2. Despiece por subensamble (código, por mueble, a producir, ruta de cada pieza).
      3. Material a comprar (neto, con merma, presentaciones, kg).
      4. Una hoja por área (Láser, Doblez, Tubo…) con casillas para el operador.
      5. Hoja de Ensamble (por subensamble).

    Variables (las arma DespiecePdfController + ReporteDespiece):
      $plano, $info, $subs, $materiales, $areas, $totales, $leyenda, $qr, $url_plano, $miniatura, $generado, $usuario
--}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Despiece {{ $plano->clave }}</title>
<style>
    @page { margin: 15mm 11mm 15mm 11mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.2pt; color: #1d1d1b; margin: 0; }
    table { border-collapse: collapse; width: 100%; }
    .tenue { color: #6b6b66; }
    .rojo { color: #d7141a; }
    .derecha { text-align: right; }
    .centro { text-align: center; }
    .mono { font-family: 'DejaVu Sans Mono', monospace; }
    .b { font-weight: bold; }
    .salto { page-break-before: always; }
    .junto { page-break-inside: avoid; }

    /* ---------- Pie de página (todas las hojas) ---------- */
    .pie { position: fixed; bottom: -9mm; left: 0; right: 0; height: 7mm; font-size: 6.8pt; color: #6b6b66;
           border-top: 0.6pt solid #e2e2de; padding-top: 1.6mm; }

    /* ---------- Encabezado ---------- */
    .logo { width: 13mm; height: 13mm; background: #d7141a; border-radius: 2mm; }
    .logo td { color: #fff; font-size: 17pt; font-weight: bold; text-align: center; vertical-align: middle; height: 13mm; padding: 0; }
    .marca { font-size: 15pt; font-weight: bold; letter-spacing: 3pt; }
    .marca span { font-weight: normal; letter-spacing: 0; color: #6b6b66; font-size: 10pt; }
    .lema { font-size: 6.5pt; color: #6b6b66; letter-spacing: 1pt; }
    .titulo-doc { font-size: 7pt; letter-spacing: 1.6pt; color: #d7141a; font-weight: bold; }
    .clave { font-family: 'DejaVu Sans Mono', monospace; font-size: 20pt; font-weight: bold; }
    .franja { height: 1.2mm; background: #d7141a; margin: 2.5mm 0 3mm 0; }
    .qr { width: 22mm; height: 22mm; }
    .qr-texto { font-size: 5.6pt; color: #6b6b66; text-align: center; }

    /* ---------- Datos ---------- */
    .datos td { border: 0.6pt solid #e2e2de; padding: 1.5mm 2.4mm; vertical-align: top; }
    .etq { font-size: 6pt; color: #6b6b66; text-transform: uppercase; letter-spacing: 0.6pt; }
    .val { font-size: 8.6pt; font-weight: bold; margin-top: 0.5mm; }
    .val.grande { font-size: 13pt; color: #d7141a; }

    /* ---------- Portada: miniatura + indicadores ---------- */
    .miniatura { border: 0.6pt solid #e2e2de; border-radius: 1.6mm; padding: 1.5mm; text-align: center; background: #fafaf8; }
    .kpi { border: 0.6pt solid #e2e2de; border-radius: 1.6mm; padding: 1.5mm 2.6mm; background: #fafaf8; }
    .kpi .num { font-size: 13pt; font-weight: bold; }
    .kpi .etq { margin-top: 0.4mm; }
    .kpi.alerta { border-color: #f3b4b6; background: #fdecec; }
    .kpi.alerta .num, .kpi.alerta .etq { color: #a80f14; }
    .kpi.oscuro { background: #1d1d1b; border-color: #1d1d1b; }
    .kpi.oscuro .num { color: #fff; } .kpi.oscuro .etq { color: #c9c9c4; }
    .rejilla td { padding: 0.8mm; width: 33.3%; vertical-align: top; }

    /* Ruta de producción (portada) */
    .ruta-tabla td { padding: 0.8mm 2mm; border-bottom: 0.5pt solid #e2e2de; font-size: 7.6pt; }
    .paso { display: inline-block; background: #1d1d1b; color: #fff; font-size: 6.4pt; font-weight: bold;
            border-radius: 1mm; padding: 0.5mm 1.6mm; }

    /* ---------- Títulos de sección ---------- */
    .seccion { margin-top: 4mm; }
    .h2 { font-size: 11pt; font-weight: bold; margin: 0 0 1.5mm 0; }
    .h2 span { font-size: 7pt; font-weight: normal; color: #6b6b66; }

    /* ---------- Subensambles ---------- */
    .sub-titulo td { background: #1d1d1b; color: #fff; padding: 1.7mm 2.6mm; }
    .sub-titulo .num { background: #d7141a; width: 9mm; text-align: center; font-weight: bold; font-size: 9pt; }
    .sub-titulo .nombre { font-weight: bold; font-size: 9pt; letter-spacing: 0.4pt; }
    .sub-titulo .veces { text-align: right; font-size: 7.6pt; }
    .sub-titulo .veces b { font-size: 9.6pt; }

    .tabla th { background: #f0f0ed; color: #6b6b66; font-size: 6.2pt; font-weight: bold; text-transform: uppercase;
                letter-spacing: 0.4pt; padding: 1.4mm 1.6mm; border-bottom: 0.8pt solid #c9c9c4; text-align: left; }
    .tabla td { padding: 1.4mm 1.6mm; border-bottom: 0.5pt solid #e2e2de; vertical-align: top; }
    .tabla tr.par td { background: #fafaf8; }
    .tabla tr.revisar td { background: #fdecec; }
    .tabla td.borde { border-left: 1.3mm solid transparent; }
    .tabla tr.revisar td.borde { border-left-color: #d7141a; }
    .codigo { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.6pt; color: #6b6b66; }
    .clave-mat { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; font-size: 7.4pt; }
    .chico { font-size: 6.3pt; color: #6b6b66; }
    .producir { font-weight: bold; font-size: 9pt; }
    .chip { font-size: 5.6pt; font-weight: bold; color: #a80f14; border: 0.6pt solid #d7141a; border-radius: 1mm; padding: 0.1mm 0.9mm; }
    .aviso { font-size: 6.2pt; color: #a80f14; margin-top: 0.5mm; }
    .nota { font-size: 6.3pt; color: #4a4a46; margin-top: 0.5mm; }
    .ruta { font-size: 6.2pt; color: #1d1d1b; }

    /* ---------- Tablas oscuras (compras, ensamble) ---------- */
    .oscura th { background: #1d1d1b; color: #fff; font-size: 6.4pt; text-transform: uppercase; letter-spacing: 0.4pt;
                 padding: 1.7mm 2mm; text-align: left; }
    .oscura td { padding: 1.5mm 2mm; border-bottom: 0.5pt solid #e2e2de; vertical-align: top; }
    .oscura tr.par td { background: #fafaf8; }

    /* ---------- Hojas por área ---------- */
    .area-banda td { background: #d7141a; color: #fff; padding: 2.4mm 3mm; vertical-align: middle; }
    .area-banda .nombre { font-size: 15pt; font-weight: bold; }
    .area-banda .sub { font-size: 7.4pt; }
    .casilla { display: inline-block; width: 3.6mm; height: 3.6mm; border: 0.8pt solid #1d1d1b; border-radius: 0.6mm; }
    .linea-llenar { border-bottom: 0.6pt solid #9a9a95; height: 4mm; }

    /* ---------- Firmas ---------- */
    .firmas td { width: 33.3%; padding: 0 5mm; text-align: center; font-size: 7pt; color: #6b6b66; }
    .firmas .linea { border-top: 0.7pt solid #1d1d1b; padding-top: 1.4mm; color: #1d1d1b; font-weight: bold; }
    .grupo td { background: #f0f0ed; font-weight: bold; font-size: 7.4pt; padding: 1.2mm 1.6mm; border-bottom: 0.8pt solid #c9c9c4; }
    .listo { border-color: #15803d; color: #15803d; background: #f0f8f2; }
    .tabla tr { page-break-inside: avoid; }
    .descargo { margin-top: 4mm; font-size: 6.6pt; color: #6b6b66; border: 0.6pt dashed #c9c9c4; border-radius: 1.4mm; padding: 2mm 3mm; }
</style>
</head>
<body>

{{-- Pie: se repite en todas las hojas ("Página X de Y" lo escribe el controlador a la derecha) --}}
<div class="pie">
    <table><tr>
        <td>@unless ($info['aprobado'])<b class="rojo">SIN APROBAR</b> · @endunless SULA Core · Despiece <b class="mono">{{ $plano->clave }}</b> v{{ $info['version'] }}@if ($info['pedido']) · {{ $info['pedido'] }}@endif · {{ $totales['muebles'] }} mueble(s)</td>
        <td class="centro">Generado {{ $generado }} por {{ $usuario }}</td>
        <td class="derecha" style="width: 30mm;"></td>
    </tr></table>
</div>

{{-- =================================================================== --}}
{{-- 1. PORTADA                                                          --}}
{{-- =================================================================== --}}
<table>
    <tr>
        <td style="width: 15mm; vertical-align: middle;"><table class="logo"><tr><td>S</td></tr></table></td>
        <td style="padding-left: 2mm; vertical-align: middle;">
            <div class="marca">SULA <span>Core</span></div>
            <div class="lema">CALIDAD · CONFIANZA · INNOVACIÓN</div>
        </td>
        <td class="derecha" style="vertical-align: middle;">
            <div class="titulo-doc">DESPIECE DE PRODUCCIÓN</div>
            <div class="clave">{{ $plano->clave }}</div>
            <div class="tenue">{{ $plano->revision }}@if ($info['modelo']) · Modelo {{ $info['modelo'] }}@endif</div>
        </td>
        <td style="width: 25mm; padding-left: 3mm; vertical-align: middle;">
            <img class="qr" src="{{ $qr }}" alt="QR">
            <div class="qr-texto">Escanea para abrir el plano</div>
        </td>
    </tr>
</table>
<div class="franja"></div>

<table class="datos">
    <tr>
        <td colspan="2" style="width: 34%;"><div class="etq">Mueble</div><div class="val">{{ $info['mueble'] }}</div></td>
        <td style="width: 16%;"><div class="etq">Cliente</div><div class="val">{{ $info['cliente'] }}</div></td>
        <td style="width: 16%;"><div class="etq">Pedido / OT</div><div class="val">{{ $info['pedido'] ?? '—' }}</div></td>
        <td style="width: 16%;"><div class="etq">Fecha de entrega</div><div class="val">{{ $info['entrega'] ?? '—' }}</div></td>
        <td style="width: 18%;"><div class="etq">Muebles a producir</div><div class="val grande">{{ number_format($totales['muebles']) }}</div></td>
    </tr>
    <tr>
        <td colspan="2"><div class="etq">Acabado</div><div class="val">{{ $info['acabado'] }}</div></td>
        <td><div class="etq">Despiece</div><div class="val">v{{ $info['version'] }} · {{ $info['estado'] }}</div><div class="chico">{{ $info['origen'] }} · {{ $info['fecha'] }}</div></td>
        <td colspan="3"><div class="etq">Archivo del plano</div><div class="val" style="font-weight: normal; font-size: 7.6pt;">{{ $info['archivo'] }}</div></td>
    </tr>
</table>

<table style="margin-top: 2.5mm;">
    <tr>
        {{-- Miniatura de la primera hoja del plano --}}
        <td style="width: 46%; vertical-align: top; padding-right: 2mm;">
            <div class="miniatura">
                @if ($miniatura)
                    <img src="{{ $miniatura }}" alt="Plano" style="width: {{ $mini_ancho }}mm;">
                @else
                    <div style="padding: 30mm 0;" class="tenue">Vista del plano no disponible</div>
                @endif
            </div>
        </td>
        <td style="vertical-align: top;">
            {{-- Indicadores --}}
            <table class="rejilla">
                <tr>
                    <td><div class="kpi oscuro"><div class="num">{{ $totales['a_producir'] }}</div><div class="etq">Piezas a producir</div></div></td>
                    <td><div class="kpi"><div class="num">{{ $totales['piezas_mueble'] }}</div><div class="etq">Piezas por mueble</div></div></td>
                    <td><div class="kpi"><div class="num">{{ $totales['subensambles'] }}</div><div class="etq">Subensambles</div></div></td>
                </tr>
                <tr>
                    <td><div class="kpi"><div class="num">{{ $totales['m2_pintura'] }}</div><div class="etq">m² a pintar</div></div></td>
                    <td><div class="kpi"><div class="num">{{ $totales['kg_lamina'] }}</div><div class="etq">kg de lámina (neto)</div></div></td>
                    <td>
                        <div @class(['kpi', 'alerta' => $totales['por_revisar'] > 0])>
                            <div class="num">{{ $totales['por_revisar'] }}</div>
                            <div class="etq">{{ $totales['por_revisar'] > 0 ? 'Piezas por revisar' : 'Todo revisado' }}</div>
                        </div>
                    </td>
                </tr>
            </table>

            {{-- Ruta de producción: qué áreas trabajan y cuánto --}}
            <p class="h2" style="margin-top: 2mm; font-size: 9.5pt;">Ruta de producción <span>· áreas que intervienen</span></p>
            <table class="ruta-tabla">
                @foreach ($areas as $a)
                    <tr>
                        <td style="width: 8mm;"><span class="paso">{{ $a['codigo'] }}</span></td>
                        <td class="b">{{ $a['nombre'] }}</td>
                        <td class="derecha tenue">{{ $a['renglones'] }} pieza(s) distintas</td>
                        <td class="derecha b mono" style="width: 26mm;">{{ $a['total'] }} pzas</td>
                    </tr>
                @endforeach
                <tr>
                    <td><span class="paso" style="background: #d7141a;">ENS</span></td>
                    <td class="b">Ensamble</td>
                    <td class="derecha tenue">{{ $totales['subensambles'] }} subensamble(s)</td>
                    <td class="derecha b mono">{{ number_format($totales['muebles']) }} mueble(s)</td>
                </tr>
            </table>
        </td>
    </tr>
</table>

@if ($totales['por_revisar'] > 0)
    <div class="descargo" style="border-color: #d7141a; color: #a80f14;">
        <b>Atención:</b> {{ $totales['por_revisar'] }} pieza(s) marcadas <b>REVISAR</b> tienen datos que no se pudieron leer del plano.
        Verifícalas contra el plano antes de cortar.
    </div>
@elseif (! $info['aprobado'])
    <div class="descargo">Todas las piezas tienen datos completos. Falta <b>aprobar</b> este despiece antes de mandarlo a producción.</div>
@else
    <div class="descargo listo"><b>Listo para producción:</b> despiece aprobado y sin piezas por revisar.</div>
@endif

{{-- =================================================================== --}}
{{-- 2. DESPIECE POR SUBENSAMBLE                                         --}}
{{-- =================================================================== --}}
<div class="salto"></div>
<p class="h2">Despiece por subensamble <span>· cantidades por subensamble, por mueble y a producir ({{ number_format($totales['muebles']) }} mueble(s)) · medidas en mm</span></p>

@foreach ($subs as $s)
    <div class="seccion">
        <table class="sub-titulo">
            <tr>
                <td class="num">{{ $s['numero'] }}</td>
                <td class="nombre">{{ $s['nombre'] }}@if ($s['pagina']) <span style="font-weight: normal; color: #c9c9c4; font-size: 7pt;">· pág. {{ $s['pagina'] }} del plano</span>@endif</td>
                <td class="veces"><b>× {{ $s['cantidad'] }}</b> por mueble · <b>{{ $s['a_producir'] }}</b> a producir</td>
            </tr>
        </table>
        <table class="tabla">
            <thead>
                <tr>
                    <th style="width: 23mm;">Código</th>
                    <th>Pieza</th>
                    <th style="width: 52mm;">Material</th>
                    <th class="derecha" style="width: 12mm;">Cant.</th>
                    <th class="derecha" style="width: 14mm;">Por mueble</th>
                    <th class="derecha" style="width: 16mm;">A producir</th>
                    <th class="derecha" style="width: 15mm;">Largo</th>
                    <th class="derecha" style="width: 15mm;">Ancho</th>
                    <th style="width: 40mm;">Ruta</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($s['piezas'] as $j => $p)
                    <tr @class(['revisar' => $p['revisar'], 'par' => ! $p['revisar'] && $j % 2 === 1])>
                        <td class="borde codigo">{{ $p['codigo'] }}</td>
                        <td>
                            <span class="b">{{ $p['descripcion'] }}</span>@if ($p['revisar']) <span class="chip">REVISAR</span>@endif
                            @foreach ($p['avisos'] as $aviso)<div class="aviso">⚠ {{ $aviso }}</div>@endforeach
                            @if ($p['notas'])<div class="nota">{{ $p['notas'] }}</div>@endif
                            @if ($p['pintura'] !== '—')<div class="chico">Pintura: {{ $p['pintura'] }}</div>@endif
                        </td>
                        <td>
                            @if ($p['material_clave'])
                                <span class="clave-mat">{{ $p['material_clave'] }}</span>
                                <div class="chico">{{ $p['material_desc'] }}</div>
                            @else
                                <span class="rojo b">Sin material del catálogo</span>
                                @if ($p['material_texto'])<div class="chico">Plano: {{ $p['material_texto'] }}</div>@endif
                            @endif
                        </td>
                        <td class="derecha mono">{{ $p['cantidad'] }}</td>
                        <td class="derecha mono">{{ $p['por_mueble'] }}</td>
                        <td class="derecha mono producir">{{ $p['a_producir'] }}</td>
                        <td class="derecha mono">{{ $p['largo'] }}</td>
                        <td class="derecha mono">{{ $p['ancho'] }}</td>
                        <td class="ruta">{{ $p['ruta_texto'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endforeach

{{-- =================================================================== --}}
{{-- 3. MATERIAL A COMPRAR                                               --}}
{{-- =================================================================== --}}
@if ($materiales)
    <div class="salto"></div>
    <div class="seccion" style="margin-top: 0;">
        <p class="h2">Material para el pedido <span>· {{ number_format($totales['muebles']) }} mueble(s) · con {{ $totales['merma_pct'] }}% de merma · presentaciones aproximadas, sin acomodo (nesting)</span></p>
        <table class="oscura">
            <thead>
                <tr>
                    <th style="width: 15mm;">Clave</th>
                    <th>Material</th>
                    <th class="derecha" style="width: 20mm;">Piezas</th>
                    <th class="derecha" style="width: 26mm;">Neto</th>
                    <th class="derecha" style="width: 26mm;">Con merma</th>
                    <th style="width: 48mm;">Pedir</th>
                    <th class="derecha" style="width: 22mm;">Peso</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($materiales as $k => $m)
                    <tr @class(['par' => $k % 2 === 1])>
                        <td class="clave-mat">{{ $m['clave'] }}</td>
                        <td>{{ $m['descripcion'] }}</td>
                        <td class="derecha mono">{{ $m['piezas'] }}</td>
                        <td class="derecha mono">{{ $m['neto'] }}</td>
                        <td class="derecha mono">{{ $m['con_merma'] }}</td>
                        <td class="b">{{ $m['pedir'] }}</td>
                        <td class="derecha mono">{{ $m['peso'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@if ($leyenda)
    <div class="seccion">
        <p class="h2">Leyenda de materiales del plano</p>
        <table class="oscura">
            @foreach ($leyenda as $codigo => $texto)
                <tr @class(['par' => $loop->even])><td class="clave-mat" style="width: 15mm;">{{ $codigo }}</td><td>{{ $texto }}</td></tr>
            @endforeach
        </table>
    </div>
@endif



{{-- =================================================================== --}}
{{-- 4. UNA HOJA POR ÁREA (para entregar a cada estación)                --}}
{{-- =================================================================== --}}
@foreach ($areas as $a)
    <div class="salto"></div>
    <table class="area-banda">
        <tr>
            <td style="width: 16mm;" class="centro"><span style="font-size: 11pt; font-weight: bold; border: 1pt solid #fff; border-radius: 1mm; padding: 0.6mm 1.6mm;">{{ $a['codigo'] }}</span></td>
            <td>
                <div class="nombre">{{ $a['nombre'] }}</div>
                <div class="sub">{{ $plano->clave }} · {{ $info['mueble'] }}@if ($info['pedido']) · {{ $info['pedido'] }}@endif @if ($info['entrega']) · entrega {{ $info['entrega'] }}@endif</div>
            </td>
            <td class="derecha">
                <div style="font-size: 15pt; font-weight: bold;">{{ $a['total'] }}</div>
                <div class="sub">piezas en esta área · {{ $a['renglones'] }} distintas</div>
            </td>
            <td style="width: 20mm; background: #fff; padding: 1mm;"><img src="{{ $qr }}" style="width: 18mm; height: 18mm;" alt="QR"></td>
        </tr>
    </table>
    <table class="tabla" style="margin-top: 2mm;">
        <thead>
            <tr>
                <th style="width: 23mm;">Código</th>
                <th>Pieza</th>
                <th style="width: 26mm;">Subensamble</th>
                <th style="width: 16mm;">Material</th>
                <th class="derecha" style="width: 14mm;">Largo</th>
                <th class="derecha" style="width: 14mm;">Ancho</th>
                <th class="derecha" style="width: 16mm;">A producir</th>
                <th class="centro" style="width: 18mm;">Hechas</th>
                <th class="centro" style="width: 8mm;">OK</th>
                <th style="width: 18mm;">Fecha</th>
                <th style="width: 20mm;">Firma</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($a['grupos'] as $g)
                <tr class="grupo"><td colspan="6">{{ $g['clave'] }} <span style="font-weight: normal;">{{ $g['descripcion'] }}</span></td><td class="derecha mono">{{ $g['total'] }}</td><td colspan="4"></td></tr>
                @foreach ($g['filas'] as $j => $p)
                <tr @class(['revisar' => $p['revisar'], 'par' => ! $p['revisar'] && $j % 2 === 1])>
                    <td class="borde codigo">{{ $p['codigo'] }}</td>
                    <td>
                        <span class="b">{{ $p['descripcion'] }}</span>@if ($p['revisar']) <span class="chip">REVISAR</span>@endif
                        @if ($p['notas'])<div class="nota">{{ $p['notas'] }}</div>@endif
                        <div class="chico">Ruta: {{ $p['ruta_texto'] }}</div>
                    </td>
                    <td class="chico">{{ $p['subensamble'] }}</td>
                    <td><span class="clave-mat">{{ $p['material_clave'] ?? '—' }}</span></td>
                    <td class="derecha mono">{{ $p['largo'] }}</td>
                    <td class="derecha mono">{{ $p['ancho'] }}</td>
                    <td class="derecha mono producir">{{ $p['a_producir'] }}</td>
                    <td><div class="linea-llenar"></div></td>
                    <td class="centro"><span class="casilla"></span></td>
                    <td><div class="linea-llenar"></div></td>
                    <td><div class="linea-llenar"></div></td>
                </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
    <table class="firmas junto" style="margin-top: 8mm;">
        <tr>
            <td><div style="height: 9mm;"></div><div class="linea">Operador</div></td>
            <td><div style="height: 9mm;"></div><div class="linea">Supervisor de {{ $a['nombre'] }}</div></td>
            <td><div style="height: 9mm;"></div><div class="linea">Calidad</div></td>
        </tr>
    </table>
@endforeach

{{-- =================================================================== --}}
{{-- 5. HOJA DE ENSAMBLE (por subensamble)                               --}}
{{-- =================================================================== --}}
<div class="salto"></div>
<table class="area-banda">
    <tr>
        <td style="width: 16mm;" class="centro"><span style="font-size: 11pt; font-weight: bold; border: 1pt solid #fff; border-radius: 1mm; padding: 0.6mm 1.6mm;">ENS</span></td>
        <td>
            <div class="nombre">Ensamble</div>
            <div class="sub">{{ $plano->clave }} · {{ $info['mueble'] }}@if ($info['pedido']) · {{ $info['pedido'] }}@endif @if ($info['entrega']) · entrega {{ $info['entrega'] }}@endif</div>
        </td>
        <td class="derecha">
            <div style="font-size: 15pt; font-weight: bold;">{{ number_format($totales['muebles']) }}</div>
            <div class="sub">mueble(s) a ensamblar</div>
        </td>
        <td style="width: 20mm; background: #fff; padding: 1mm;"><img src="{{ $qr }}" style="width: 18mm; height: 18mm;" alt="QR"></td>
    </tr>
</table>
<table class="oscura" style="margin-top: 2mm;">
    <thead>
        <tr>
            <th style="width: 10mm;">#</th>
            <th>Subensamble</th>
            <th class="derecha" style="width: 22mm;">Por mueble</th>
            <th class="derecha" style="width: 22mm;">A ensamblar</th>
            <th class="derecha" style="width: 26mm;">Piezas distintas</th>
            <th class="centro" style="width: 20mm;">Hechos</th>
            <th class="centro" style="width: 10mm;">OK</th>
            <th style="width: 22mm;">Fecha</th>
            <th style="width: 24mm;">Firma</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($subs as $k => $s)
            <tr @class(['par' => $k % 2 === 1])>
                <td class="b mono">{{ $s['numero'] }}</td>
                <td class="b">{{ $s['nombre'] }}</td>
                <td class="derecha mono">{{ $s['cantidad'] }}</td>
                <td class="derecha mono producir">{{ $s['a_producir'] }}</td>
                <td class="derecha mono">{{ count($s['piezas']) }}</td>
                <td><div class="linea-llenar"></div></td>
                <td class="centro"><span class="casilla"></span></td>
                <td><div class="linea-llenar"></div></td>
                <td><div class="linea-llenar"></div></td>
            </tr>
        @endforeach
    </tbody>
</table>
<div class="descargo">
    Las cantidades "a producir" ya multiplican: pieza × subensamble × {{ number_format($totales['muebles']) }} mueble(s).
    Medidas en milímetros. La ruta de producción es una propuesta del sistema según el material y las notas del plano.
    @if ($totales['por_revisar'] > 0)<b class="rojo">Hay {{ $totales['por_revisar'] }} pieza(s) REVISAR.</b>@endif
</div>

<table class="firmas junto" style="margin-top: 6mm;">
    <tr>
        <td><div style="height: 9mm;"></div><div class="linea">Elaboró</div>Ingeniería</td>
        <td><div style="height: 9mm;"></div><div class="linea">Revisó</div>Producción</td>
        <td><div style="height: 9mm;"></div><div class="linea">Autorizó</div>Gerencia</td>
    </tr>
</table>

</body>
</html>
