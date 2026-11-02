<?php

namespace App\Livewire\Produccion;

use App\Models\Carpeta;
use App\Models\ConexionGoogle;
use App\Models\Configuracion;
use App\Models\IaLlamada;
use App\Models\Plano;
use App\Planos\AlmacenPdf;
use App\Planos\Fuentes\Fuente;
use App\Planos\NombreArchivo;
use App\Planos\Sincronizador;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Módulo Producción: explorador de planos tipo Drive.
 *   - Navegar carpetas (con ruta de migas) y buscar en todas.
 *   - Crear carpetas y subir PDFs: se guardan en Drive DE VERDAD (con la cuenta de
 *     Google conectada) y además se guarda una copia del PDF dentro del AS500.
 *   - Ver el estado del despiece de cada plano.
 * A propósito no hay borrar, renombrar ni mover.
 */
#[Title('Planos y despiece')]
class Explorador extends Component
{
    use WithFileUploads; // permite <input type="file" wire:model="archivos">

    // Carpeta abierta (?carpeta=12). null = raíz
    #[Url]
    public ?int $carpeta = null;

    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $filtro = '';   // '', SIN, BORRADOR, APROBADO

    // Crear carpeta
    public bool $creandoCarpeta = false;

    public string $nombreCarpeta = '';

    // Subir PDFs: Livewire los guarda temporalmente hasta que se da "Subir a Drive"
    public array $archivos = [];

    public array $resultados = [];   // [['nombre', 'ok' => bool, 'mensaje']]

    public ?string $mensaje = null;

    public ?string $error = null;

    /** Mensajes que manda el "Conectar Google" al regresar a esta pantalla. */
    public function mount(): void
    {
        $this->mensaje = session('mensaje');
        $this->error = session('error');
    }

    // ---------------- Datos ----------------

    /** La cuenta de Google conectada (para escribir en Drive), o null. */
    #[Computed]
    public function conexion(): ?ConexionGoogle
    {
        return ConexionGoogle::actual();
    }

    /** ¿Se puede crear carpetas y subir desde aquí? */
    #[Computed]
    public function puedeEscribir(): bool
    {
        return strtolower((string) config('sula.planos.fuente')) !== 'drive'
            || config('sula.planos.drive_escribir_con') === 'cuenta_servicio'
            || $this->conexion !== null;
    }

    #[Computed]
    public function actual(): ?Carpeta
    {
        return $this->carpeta ? Carpeta::where('activo', true)->find($this->carpeta) : null;
    }

    #[Computed]
    public function subcarpetas()
    {
        if ($this->buscando()) {
            // Al buscar también se muestran las carpetas que coinciden
            return Carpeta::where('activo', true)->where('ruta', 'ilike', '%'.trim($this->buscar).'%')
                ->withCount('planos')->orderBy('ruta')->limit(30)->get();
        }

        return Carpeta::where('activo', true)
            ->where('padre_id', $this->actual?->id)
            ->withCount(['planos', 'hijas'])
            ->orderBy('nombre')
            ->get();
    }

    #[Computed]
    public function planos()
    {
        return Plano::query()
            ->where('activo', true)
            ->with(['aprobado', 'ultimo'])
            ->when(! $this->buscando(), fn ($q) => $q->where('carpeta_id', $this->actual?->id))
            ->when($this->buscando(), function ($q) {
                // Cada palabra por separado: "vitrina 0072" encuentra "2-1-0072-0003 VITRINA…"
                foreach (preg_split('/\s+/', trim($this->buscar)) as $palabra) {
                    $t = '%'.$palabra.'%';
                    $q->where(fn ($w) => $w->where('clave', 'ilike', $t)->orWhere('nombre', 'ilike', $t)->orWhere('carpeta', 'ilike', $t));
                }
            })
            ->when($this->filtro === 'SIN', fn ($q) => $q->whereDoesntHave('despieces'))
            ->when($this->filtro === 'APROBADO', fn ($q) => $q->whereHas('aprobado'))
            ->when($this->filtro === 'BORRADOR', fn ($q) => $q->whereDoesntHave('aprobado')->whereHas('despieces'))
            ->orderBy('clave')
            ->orderByDesc('revision')
            ->limit(300)
            ->get();
    }

    #[Computed]
    public function resumen(): array
    {
        $activos = Plano::where('activo', true);

        return [
            'planos' => (clone $activos)->count(),
            'aprobados' => (clone $activos)->whereHas('aprobado')->count(),
            'tokens_mes' => (int) IaLlamada::where('creado_en', '>=', now()->startOfMonth())->sum(DB::raw('tokens_entrada + tokens_salida')),
            'ultima_sync' => Configuracion::valor('planos_ultima_sync'),
        ];
    }

    private function buscando(): bool
    {
        return trim($this->buscar) !== '';
    }

    // ---------------- Navegar ----------------

    public function abrir(?int $id): void
    {
        $this->carpeta = $id;
        $this->buscar = '';
        $this->limpiarAvisos();
        unset($this->actual, $this->subcarpetas, $this->planos);
    }

    // ---------------- Sincronizar con Drive ----------------

    public function sincronizar(): void
    {
        $this->limpiarAvisos();
        try {
            $r = Sincronizador::sincronizar(Fuente::actual());
            $this->mensaje = "Listo: {$r['carpetas']} carpetas y {$r['total']} PDFs revisados · {$r['nuevos']} nuevos · {$r['actualizados']} actualizados · {$r['retirados']} retirados.";
        } catch (Throwable $e) {
            $this->error = 'No se pudo sincronizar: '.$e->getMessage();
        }
        unset($this->subcarpetas, $this->planos, $this->resumen);
    }

    // ---------------- Crear carpeta ----------------

    public function crearCarpeta(): void
    {
        $this->limpiarAvisos();
        $nombre = NombreArchivo::limpiarCarpeta($this->nombreCarpeta);
        if ($nombre === '' || mb_strlen($nombre) > 100) {
            $this->addError('nombreCarpeta', 'Escribe un nombre de 1 a 100 caracteres.');

            return;
        }

        try {
            $fuente = $this->fuenteParaEscribir();
            $padre = $this->actual;
            $nueva = $fuente->crearCarpeta($nombre, $padre?->archivo_id, $padre?->ruta ?? '');
            Sincronizador::registrarCarpeta($fuente->origen(), $nueva, $padre);
            $this->mensaje = "Carpeta «{$nombre}» creada en ".($fuente->origen() === 'DRIVE' ? 'Drive' : 'la carpeta local').'.';
            $this->creandoCarpeta = false;
            $this->nombreCarpeta = '';
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
        }
        unset($this->subcarpetas);
    }

    // ---------------- Subir PDFs ----------------

    /** Se ejecuta solo cuando terminan de cargarse los archivos elegidos: valida que sean PDF. */
    public function updatedArchivos(): void
    {
        $this->limpiarAvisos();
        $this->validate(
            ['archivos' => ['array', 'max:20'], 'archivos.*' => ['file', 'mimes:pdf', 'max:61440']],
            ['archivos.max' => 'Máximo 20 PDFs a la vez.', 'archivos.*.mimes' => 'Solo se aceptan PDFs.', 'archivos.*.max' => 'Cada PDF puede pesar hasta 60 MB.'],
        );
    }

    public function quitarArchivo(int $i): void
    {
        unset($this->archivos[$i]);
        $this->archivos = array_values($this->archivos);
    }

    public function subir(): void
    {
        ini_set('memory_limit', '512M');   // un PDF de 60 MB se copia varias veces en memoria
        $this->updatedArchivos();
        $this->resultados = [];

        try {
            $fuente = $this->fuenteParaEscribir();
        } catch (Throwable $e) {
            $this->error = $e->getMessage();

            return;
        }

        $carpeta = $this->actual;
        foreach ($this->archivos as $archivo) {
            $nombre = NombreArchivo::limpiarPdf($archivo->getClientOriginalName());
            try {
                $bytes = $archivo->get();
                if (! str_starts_with($bytes, '%PDF')) {
                    throw new \RuntimeException('El archivo no es un PDF válido.');
                }
                $subido = $fuente->subir($nombre, $bytes, $carpeta?->archivo_id, $carpeta?->ruta ?? '');
                $plano = Sincronizador::registrarPlano($fuente->origen(), $subido, $carpeta);
                AlmacenPdf::guardarCopia($plano, $bytes);   // copia también dentro del AS500
                $this->resultados[] = ['nombre' => $nombre, 'ok' => true, 'mensaje' => "Subido · clave {$plano->clave}"];
            } catch (Throwable $e) {
                $this->resultados[] = ['nombre' => $nombre, 'ok' => false, 'mensaje' => $e->getMessage()];
            }
            $archivo->delete(); // borra la copia temporal de Livewire
        }

        $this->archivos = [];
        unset($this->planos, $this->resumen);
    }

    /** Solo se escribe en la fuente configurada, y la carpeta abierta debe ser de esa fuente. */
    private function fuenteParaEscribir()
    {
        if (! $this->puedeEscribir) {
            throw new \RuntimeException('Para crear carpetas y subir PDFs primero dale "Conectar Google" con una cuenta que sea Editor de PLANOS.');
        }
        $fuente = Fuente::actual();
        if ($this->actual && $this->actual->origen !== $fuente->origen()) {
            throw new \RuntimeException('Esta carpeta es de otra fuente ('.$this->actual->origen.'). Cambia PLANOS_FUENTE o sincroniza.');
        }

        return $fuente;
    }

    private function limpiarAvisos(): void
    {
        $this->mensaje = $this->error = null;
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.produccion.explorador', [
            'camino' => $this->actual?->camino() ?? [],
            'fuente' => strtoupper((string) config('sula.planos.fuente')),
        ]);
    }
}
