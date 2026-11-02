<?php

namespace App\Planos;

use App\Cotizaciones\Flujo;
use App\Jobs\LeerPlano;
use App\Models\Cotizacion;
use App\Models\Despiece;
use App\Models\MaterialAlias;
use App\Models\Plano;
use Illuminate\Support\Facades\DB;

/**
 * Reglas del despiece de un plano:
 *   leerConIa → (LEYENDO) → BORRADOR → guardar… → aprobar → APROBADO → cotizar
 *   nuevoManual / copiar → BORRADOR
 */
class FlujoDespiece
{
    /** Crea una versión nueva en LEYENDO y manda el trabajo a la cola. */
    public static function leerConIa(Plano $plano, bool $conDibujos): Despiece
    {
        if ($plano->despieces()->where('estado', 'LEYENDO')->exists()) {
            throw new ErrorDespiece('Este plano ya se está leyendo. Espera a que termine.');
        }
        if (! config('sula.groq.api_key')) {
            throw new ErrorDespiece('Falta GROQ_API_KEY en el .env.');
        }

        $d = self::nuevaVersion($plano, ['estado' => 'LEYENDO', 'origen' => 'IA', 'con_dibujos' => $conDibujos, 'progreso' => 'En la fila…']);
        LeerPlano::dispatch($d->id);

        return $d;
    }

    /** Despiece vacío para capturar a mano. */
    public static function nuevoManual(Plano $plano): Despiece
    {
        return DB::transaction(function () use ($plano) {
            $d = self::nuevaVersion($plano, ['estado' => 'BORRADOR', 'origen' => 'MANUAL']);
            $d->subensambles()->create(['orden' => 1, 'nombre' => Normalizador::SIN_SUBENSAMBLE, 'cantidad' => 1]);

            return $d;
        });
    }

    /** Copia un despiece (por ejemplo el aprobado) a una versión nueva en BORRADOR. */
    public static function copiar(Despiece $origen): Despiece
    {
        return DB::transaction(function () use ($origen) {
            $d = self::nuevaVersion($origen->plano, ['estado' => 'BORRADOR', 'origen' => 'COPIA', 'datos' => $origen->datos]);
            foreach ($origen->subensambles()->with('piezas')->get() as $s) {
                $nuevo = $d->subensambles()->create($s->only(['orden', 'nombre', 'cantidad', 'pagina', 'notas']));
                foreach ($s->piezas as $p) {
                    $nuevo->piezas()->create($p->only(['orden', 'descripcion', 'material_texto', 'material_id', 'cantidad',
                        'largo_mm', 'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'pagina', 'notas', 'avisos']));
                }
            }

            return $d;
        });
    }

    /**
     * Guarda lo que el usuario editó. Vuelve a revisar cada pieza (avisos) con el catálogo.
     * @param  array  $subensambles  como los arma la pantalla: [['nombre','cantidad','piezas'=>[…, 'pintura']]]
     */
    public static function guardar(Despiece $d, array $subensambles): void
    {
        if (! $d->esEditable()) {
            throw new ErrorDespiece('Solo se edita un despiece en BORRADOR. Usa "Nueva versión" para cambiar uno aprobado.');
        }

        $limpio = [];
        foreach ($subensambles as $s) {
            $piezas = [];
            foreach ($s['piezas'] ?? [] as $p) {
                if (trim((string) ($p['descripcion'] ?? '')) === '') {
                    continue; // renglón vacío
                }
                $piezas[] = [
                    'descripcion' => trim($p['descripcion']),
                    'material_texto' => Normalizador::llaveMaterial($p['material_texto'] ?? ''),
                    'material_id' => ($p['material_id'] ?? '') !== '' ? (int) $p['material_id'] : null,
                    'cantidad' => Normalizador::numero((string) ($p['cantidad'] ?? '')),
                    'largo_mm' => Normalizador::numero((string) ($p['largo_mm'] ?? '')),
                    'ancho_mm' => Normalizador::numero((string) ($p['ancho_mm'] ?? '')),
                    'pintura' => Normalizador::numero((string) ($p['pintura'] ?? '')) ?? '0',
                    'pagina' => is_numeric($p['pagina'] ?? null) ? (int) $p['pagina'] : null,
                    'notas' => trim((string) ($p['notas'] ?? '')),
                ];
            }
            $limpio[] = [
                'nombre' => mb_strtoupper(trim((string) ($s['nombre'] ?? ''))) ?: Normalizador::SIN_SUBENSAMBLE,
                'cantidad' => Normalizador::numero((string) ($s['cantidad'] ?? '')),
                'pagina' => is_numeric($s['pagina'] ?? null) ? (int) $s['pagina'] : null,
                'notas' => trim((string) ($s['notas'] ?? '')),
                'piezas' => $piezas,
            ];
        }

        [$diccionario, $materiales] = LectorPlano::catalogo();
        $doc = Normalizador::resolver(['leyenda' => $d->datos['leyenda'] ?? [], 'subensambles' => $limpio], $diccionario, $materiales);

        DB::transaction(function () use ($d, $doc) {
            LectorPlano::guardarEstructura($d, $doc['subensambles']);
            $d->touch();
        });
    }

    /** Cuántas piezas siguen con una cantidad que la IA no leyó (se puso 1 de relleno). */
    public static function cantidadesPorConfirmar(array $subensambles): int
    {
        return collect($subensambles)->flatMap(fn ($s) => $s['piezas'] ?? [])
            ->filter(fn ($p) => collect($p['avisos'] ?? [])->contains(
                fn ($a) => str_starts_with($a['texto'], Normalizador::AVISO_CANTIDAD)
            ))->count();
    }

    /**
     * Aprueba el despiece. Requisitos: sin avisos de error.
     * Además "aprende": cada material del plano que se resolvió a mano queda como alias.
     */
    public static function aprobar(Despiece $d): void
    {
        if (! $d->esEditable()) {
            throw new ErrorDespiece('Solo se aprueba un despiece en BORRADOR.');
        }
        $piezas = $d->piezas()->get();
        if ($piezas->isEmpty()) {
            throw new ErrorDespiece('El despiece no tiene piezas.');
        }
        $conError = $piezas->filter(fn ($p) => collect($p->avisos)->contains('nivel', 'error'))->count();
        if ($conError) {
            throw new ErrorDespiece("Hay {$conError} pieza(s) con avisos en rojo. Corrígelas y guarda antes de aprobar.");
        }

        DB::transaction(function () use ($d, $piezas) {
            foreach ($piezas as $p) {
                $alias = Normalizador::llaveMaterial($p->material_texto ?? '');
                if ($alias !== '' && ! Normalizador::esCodigoLeyenda($alias) && $p->material_id && ! MaterialAlias::find($alias)) {
                    MaterialAlias::create(['alias' => $alias, 'material_id' => $p->material_id]);
                }
            }
            Despiece::where('plano_id', $d->plano_id)->where('estado', 'APROBADO')->update(['estado' => 'REEMPLAZADO']);
            $d->update(['estado' => 'APROBADO', 'aprobado_en' => now(), 'aprobado_por' => config('sula.usuario')]);
        });
    }

    /** Crea una cotización (BORRADOR) con el despiece aprobado ya "explotado" por mueble. */
    public static function cotizar(Despiece $d, ?int $clienteId, int $cantidad): Cotizacion
    {
        if ($d->estado !== 'APROBADO') {
            throw new ErrorDespiece('Solo se cotiza un despiece APROBADO.');
        }
        if ($cantidad < 1) {
            throw new ErrorDespiece('La cantidad de muebles debe ser al menos 1.');
        }

        $subensambles = $d->subensambles()->with('piezas')->get()->map(fn ($s) => [
            'nombre' => $s->nombre,
            'cantidad' => (string) $s->cantidad,
            'piezas' => $s->piezas->map(fn ($p) => $p->only(['descripcion', 'material_id', 'cantidad', 'largo_mm',
                'ancho_mm', 'perimetro_pintable_mm', 'caras_pintables', 'notas']))->all(),
        ])->all();

        return DB::transaction(function () use ($d, $clienteId, $cantidad, $subensambles) {
            $plano = $d->plano;
            $c = Cotizacion::create([
                'estado' => 'BORRADOR', // explícito: el default de la BD no llega al objeto y guardarPiezas lo revisa
                'plano_codigo' => $plano->clave,
                'descripcion' => $d->datos['plano']['descripcion'] ?? $plano->nombre,
                'plano_rev' => $plano->revision,
                'cliente_id' => $clienteId,
                'cantidad' => $cantidad,
                'notas' => "Desde el despiece v{$d->version} del plano {$plano->nombre}",
            ]);
            Flujo::guardarPiezas($c, Normalizador::explotar($subensambles));

            return $c;
        });
    }

    private static function nuevaVersion(Plano $plano, array $datos): Despiece
    {
        $version = (int) Despiece::where('plano_id', $plano->id)->max('version') + 1;

        return $plano->despieces()->create($datos + ['version' => $version]);
    }
}
