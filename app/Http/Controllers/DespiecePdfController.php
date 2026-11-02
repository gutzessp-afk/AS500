<?php

namespace App\Http\Controllers;

use App\Models\MaterialVista;
use App\Models\Plano;
use App\Planos\AlmacenPdf;
use App\Planos\ExtractorPdf;
use App\Planos\NombreArchivo;
use App\Planos\ReporteDespiece;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Throwable;

/**
 * GET /produccion/planos/{plano}/despiece.pdf
 *     ?v=2                     versión del despiece (si no, la aprobada o la última)
 *     &cantidad=10             muebles a producir (pedido)
 *     &pedido=PED-3492         número de pedido u orden de trabajo
 *     &entrega=2026-11-20      fecha de entrega
 *     &descargar=1             descargar en lugar de mostrar
 *
 * El PDF se arma al momento (no se guarda): si alguien corrige una pieza, el siguiente ya sale bien.
 */
class DespiecePdfController
{
    public function __invoke(Request $request, Plano $plano)
    {
        $d = $request->integer('v')
            ? $plano->despieces()->where('version', $request->integer('v'))->first()
            : ($plano->aprobado ?? $plano->despieces()->whereIn('estado', ['BORRADOR', 'APROBADO', 'REEMPLAZADO'])->first());
        abort_if(! $d, 404, 'Este plano todavía no tiene despiece.');
        $d->load(['subensambles.piezas']);

        $cantidad = max(1, min(100000, $request->integer('cantidad', 1)));
        $pedido = mb_substr(trim((string) $request->query('pedido', '')), 0, 40);
        $entrega = $this->fecha($request->query('entrega'));

        // ---- Datos como arreglos simples (ReporteDespiece se prueba sin base de datos) ----
        $subs = $d->subensambles->map(fn ($s) => [
            'nombre' => $s->nombre,
            'cantidad' => (string) $s->cantidad,
            'pagina' => $s->pagina,
            'piezas' => $s->piezas->map(fn ($p) => $p->only(['descripcion', 'material_texto', 'material_id', 'cantidad',
                'largo_mm', 'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'notas', 'avisos']))->all(),
        ])->all();
        $catalogo = MaterialVista::whereIn('id', $d->piezas()->pluck('despiece_piezas.material_id')->filter())
            ->get()
            ->mapWithKeys(fn ($m) => [$m->id => [
                'clave' => $m->clave, 'descripcion' => $m->descripcion, 'tipo' => $m->tipo_calculo,
                'categoria' => $m->categoria, 'presentacion' => $m->presentacion,
                'contenido' => (string) $m->contenido, 'kg_m2' => (string) ($m->kg_m2 ?? '0'),
            ]])->all();

        $reporte = ReporteDespiece::armar($subs, $catalogo, [
            'cantidad' => $cantidad,
            'merma' => (string) config('sula.planos.merma_pdf', '0.10'),
            'prefijo' => ReporteDespiece::prefijo($plano->clave, $plano->id),
        ]);

        $datosPlano = $d->datos['plano'] ?? [];
        $estado = ['BORRADOR' => 'Sin aprobar', 'APROBADO' => 'Aprobado', 'REEMPLAZADO' => 'Versión anterior'][$d->estado] ?? $d->estado;
        // Con APP_URL (no con la dirección de esta visita): el QR debe abrir desde cualquier celular
        $urlPlano = rtrim((string) config('app.url'), '/').route('produccion.plano', $plano, false);

        $vars = $reporte + [
            'plano' => $plano,
            'info' => [
                'mueble' => $datosPlano['descripcion'] ?? $plano->nombre,
                'cliente' => $datosPlano['cliente'] ?? ($plano->carpetaPadre?->camino()[0]->nombre ?? '—'),
                'acabado' => $datosPlano['acabado'] ?? '—',
                'modelo' => $datosPlano['modelo'] ?? null,
                'archivo' => trim(($plano->carpeta ? $plano->carpeta.' / ' : '').$plano->nombre),
                'version' => $d->version,
                'estado' => $estado.($d->aprobado_por ? ' por '.$d->aprobado_por : ''),
                'aprobado' => $d->estado === 'APROBADO',
                'fecha' => ($d->aprobado_en ?? $d->actualizado_en)->locale('es')->isoFormat('D MMM YYYY'),
                'origen' => match ($d->origen) {
                    'IA' => 'Leído con IA'.($d->con_dibujos ? ' (texto y dibujos)' : ' (texto del plano)'),
                    'MANUAL' => 'Capturado a mano',
                    default => 'Copia de otra versión',
                },
                'pedido' => $pedido ?: null,
                'entrega' => $entrega,
            ],
            'leyenda' => $d->datos['leyenda'] ?? [],
            'qr' => $this->qr($urlPlano),
            'url_plano' => $urlPlano,
            'miniatura' => $miniatura = $this->miniatura($plano),
            'mini_ancho' => $this->anchoMiniatura($miniatura),
            'generado' => now()->locale('es')->isoFormat('D MMM YYYY, HH:mm'),
            'usuario' => config('sula.usuario'),
        ];

        // Carta horizontal: las tablas caben a lo ancho
        $pdf = Pdf::loadView('pdf.despiece', $vars)->setPaper('letter', 'landscape');
        $pdf->render();

        // "Página X de Y": se escribe al final, cuando dompdf ya sabe cuántas hojas son
        $lienzo = $pdf->getDomPDF()->getCanvas();
        $fuente = $pdf->getDomPDF()->getFontMetrics()->getFont('DejaVu Sans');
        $mm = 72 / 25.4;
        $lienzo->page_text($lienzo->get_width() - 31 * $mm - 40, $lienzo->get_height() - 15 * $mm + 6,
            'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 6.8, [0.42, 0.42, 0.4]);

        // limpiarPdf: un pedido como PED/34 rompería el nombre del archivo
        $nombre = NombreArchivo::limpiarPdf(str_replace(['/', '\\', '%'], '-', 'Despiece '.$plano->clave.' v'.$d->version.($pedido ? ' '.$pedido : '')));

        return $request->boolean('descargar') ? $pdf->download($nombre) : $pdf->stream($nombre);
    }

    /** QR (SVG en base64) que abre el plano en el sistema. Sin GD ni Imagick. */
    private function qr(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 0), new SvgImageBackEnd())))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /** Imagen chica de la primera hoja del plano. Si algo falla, el PDF sale sin ella. */
    private function miniatura(Plano $plano): ?string
    {
        try {
            return 'data:image/jpeg;base64,'.(new ExtractorPdf(AlmacenPdf::ruta($plano)))->imagenBase64(1, 900);
        } catch (Throwable) {
            return null;
        }
    }

    /** Ancho en mm para que la miniatura quepa en la portada: dompdf ignora max-height. */
    private function anchoMiniatura(?string $miniatura): float
    {
        [$ancho, $alto] = $miniatura ? (getimagesizefromstring(base64_decode(explode(',', $miniatura)[1])) ?: [4, 3]) : [4, 3];

        return min(104, 88 * $ancho / $alto);
    }

    private function fecha(mixed $v): ?string
    {
        try {
            return $v ? \Illuminate\Support\Carbon::parse((string) $v)->locale('es')->isoFormat('D MMM YYYY') : null;
        } catch (Throwable) {
            return null;
        }
    }
}
