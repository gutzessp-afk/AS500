<?php

namespace App\Planos\Fuentes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Planos desde Google Drive.
 *
 * Dos "llaves" para entrar:
 *   - LEER (listar, descargar): la cuenta de servicio del sistema (sula-core-drive),
 *     que debe tener la carpeta PLANOS compartida.
 *   - ESCRIBIR (crear carpeta, subir PDF): la cuenta de Google CONECTADA con "Conectar Google"
 *     (una persona con permiso de Editor en PLANOS). Por qué: PLANOS vive en "Mi unidad"
 *     de una persona y ahí una cuenta de servicio NO puede guardar archivos (no tiene
 *     espacio propio: Google responde "storageQuotaExceeded").
 *     Si algún día PLANOS se mueve a una unidad compartida, se puede escribir con la
 *     cuenta de servicio: PLANOS_DRIVE_ESCRIBIR_CON=cuenta_servicio.
 *
 * No usamos la librería oficial de Google (pesa mucho). Hacemos lo mismo
 * con el cliente HTTP de Laravel:
 *   1. Firmamos un JWT con la llave privada del JSON de la Service Account.
 *   2. Lo cambiamos por un token de acceso (dura 1 hora; lo guardamos en caché).
 *   3. Con ese token listamos, descargamos, creamos carpetas y subimos PDFs.
 */
class FuenteDrive implements FuentePlanos
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const SUBIDA = 'https://www.googleapis.com/upload/drive/v3/files';

    private const CARPETA = 'application/vnd.google-apps.folder';

    // Permiso completo de Drive: el de solo lectura ya no alcanza porque ahora se sube.
    // (Desde el sistema NO se borra nada: no hay código que borre.)
    private const PERMISO = 'https://www.googleapis.com/auth/drive';

    private const CAMPOS_ARCHIVO = 'id, name, mimeType, size, md5Checksum, modifiedTime, parents';

    public function __construct(private string $rutaCredenciales, private string $carpetaRaiz, private string $escribirCon = 'cuenta_conectada')
    {
        if ($this->carpetaRaiz === '') {
            throw new RuntimeException('Falta PLANOS_DRIVE_CARPETA en el .env (id de la carpeta raíz de planos).');
        }
    }

    public function origen(): string
    {
        return 'DRIVE';
    }

    public function explorar(): array
    {
        $carpetas = [];
        $archivos = [];
        // Recorremos "a lo ancho" (por niveles): así el padre siempre sale antes que sus hijas
        $pendientes = [[$this->carpetaRaiz, null, '']];   // [id, id del padre en el sistema, ruta]
        $vistas = [];

        while ($pendientes) {
            [$carpetaId, $padreId, $ruta] = array_shift($pendientes);
            if (isset($vistas[$carpetaId])) {
                continue; // un acceso directo podría crear un ciclo
            }
            $vistas[$carpetaId] = true;

            $pagina = null;
            do {
                $r = $this->http()->get(self::API.'/files', array_filter([
                    'q' => "'{$carpetaId}' in parents and trashed = false",
                    'fields' => 'nextPageToken, files('.self::CAMPOS_ARCHIVO.')',
                    'pageSize' => 1000,
                    'pageToken' => $pagina,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                    'corpora' => 'allDrives',
                ]));
                $this->revisar($r, 'listar la carpeta');
                $r = $r->json();

                foreach ($r['files'] ?? [] as $f) {
                    $rutaHija = $ruta === '' ? $f['name'] : "$ruta/{$f['name']}";
                    if ($f['mimeType'] === self::CARPETA) {
                        // La raíz no se guarda como carpeta: sus hijas tienen padre null
                        $carpetas[] = new CarpetaOrigen($f['id'], $f['name'], $padreId, $rutaHija);
                        $pendientes[] = [$f['id'], $f['id'], $rutaHija];
                    } elseif ($f['mimeType'] === 'application/pdf') {
                        $archivos[] = $this->archivo($f, $padreId, $ruta);
                    }
                }
                $pagina = $r['nextPageToken'] ?? null;
            } while ($pagina);
        }

        return ['carpetas' => $carpetas, 'archivos' => $archivos];
    }

    public function descargar(string $id): string
    {
        $r = $this->http()->timeout(120)
            ->get(self::API.'/files/'.rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true']);
        $this->revisar($r, 'descargar el PDF');

        return $r->body();
    }

    public function crearCarpeta(string $nombre, ?string $padreId, string $rutaPadre = ''): CarpetaOrigen
    {
        $padre = $padreId ?? $this->carpetaRaiz;
        if ($this->existe($nombre, $padre, carpeta: true)) {
            throw new RuntimeException("Ya existe una carpeta llamada «{$nombre}» aquí.");
        }

        $r = $this->http(escribir: true)
            ->withQueryParameters(['supportsAllDrives' => 'true', 'fields' => 'id, name'])
            ->post(self::API.'/files', ['name' => $nombre, 'mimeType' => self::CARPETA, 'parents' => [$padre]]);
        $this->revisar($r, 'crear la carpeta');

        return new CarpetaOrigen($r->json('id'), $r->json('name'), $padreId, $rutaPadre === '' ? $nombre : "$rutaPadre/$nombre");
    }

    /**
     * Subida "reanudable" de Google: funciona igual para 1 MB que para 60 MB.
     *   1) POST con los datos del archivo (nombre, carpeta) → Google responde una URL de subida.
     *   2) PUT de los bytes del PDF a esa URL → Google responde el archivo creado.
     */
    public function subir(string $nombre, string $bytes, ?string $carpetaId, string $rutaCarpeta = ''): ArchivoPlano
    {
        $padre = $carpetaId ?? $this->carpetaRaiz;
        if ($this->existe($nombre, $padre, carpeta: false)) {
            throw new RuntimeException("Ya existe «{$nombre}» en esta carpeta. Si es una revisión nueva, ponle REV-01, REV-02… en el nombre.");
        }

        $inicio = $this->http(escribir: true)
            ->withHeaders(['X-Upload-Content-Type' => 'application/pdf', 'X-Upload-Content-Length' => (string) strlen($bytes)])
            ->withQueryParameters(['uploadType' => 'resumable', 'supportsAllDrives' => 'true'])
            ->post(self::SUBIDA, ['name' => $nombre, 'mimeType' => 'application/pdf', 'parents' => [$padre]]);
        $this->revisar($inicio, 'iniciar la subida');

        $url = $inicio->header('Location');
        if (! $url) {
            throw new RuntimeException('Google no regresó la dirección de subida.');
        }

        $r = Http::withToken($this->token(escribir: true))->timeout(300)
            ->withBody($bytes, 'application/pdf')
            ->put($url.'&fields='.rawurlencode(self::CAMPOS_ARCHIVO));
        $this->revisar($r, 'subir el PDF');

        return $this->archivo($r->json(), $carpetaId, $rutaCarpeta);
    }

    // ------------------------------------------------------------------

    private function archivo(array $f, ?string $carpetaId, string $ruta): ArchivoPlano
    {
        return new ArchivoPlano(
            id: $f['id'],
            nombre: $f['name'],
            carpetaId: $carpetaId,
            carpeta: $ruta === '' ? null : $ruta,
            tamano: isset($f['size']) ? (int) $f['size'] : null,
            huella: $f['md5Checksum'] ?? $f['modifiedTime'] ?? null,
            modificado: $f['modifiedTime'] ?? null,
        );
    }

    /** ¿Ya hay algo con ese nombre en la carpeta? (Drive sí permite nombres repetidos; nosotros no.) */
    private function existe(string $nombre, string $padre, bool $carpeta): bool
    {
        $tipo = $carpeta ? "mimeType = '".self::CARPETA."'" : "mimeType != '".self::CARPETA."'";
        $r = $this->http()->get(self::API.'/files', [
            'q' => "name = '".str_replace(['\\', "'"], ['\\\\', "\\'"], $nombre)."' and '{$padre}' in parents and trashed = false and $tipo",
            'fields' => 'files(id)',
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'corpora' => 'allDrives',
        ]);
        $this->revisar($r, 'revisar si ya existe');

        return ! empty($r->json('files'));
    }

    /** Convierte los errores de Google en mensajes que se entienden. */
    private function revisar($r, string $accion): void
    {
        if ($r->successful()) {
            return;
        }
        $razon = $r->json('error.errors.0.reason') ?? '';
        $mensaje = $r->json('error.message') ?? $r->body();

        throw new RuntimeException(match (true) {
            $razon === 'storageQuotaExceeded' => 'La cuenta de servicio no puede guardar en "Mi unidad". Usa PLANOS_DRIVE_ESCRIBIR_CON=cuenta_conectada y dale "Conectar Google".',
            $r->status() === 404 => "Drive no encuentra la carpeta o el archivo al $accion. ¿PLANOS está compartida con la cuenta del sistema (Lector o Editor)?",
            $r->status() === 403 => "Sin permiso para $accion. La cuenta debe ser Editor de la carpeta PLANOS. ($mensaje)",
            default => "Drive respondió {$r->status()} al $accion: $mensaje",
        });
    }

    /** Cliente HTTP ya autenticado (para leer o para escribir). */
    private function http(bool $escribir = false)
    {
        return Http::withToken($this->token($escribir))->acceptJson()->timeout(60);
    }

    /** Qué llave usar: para escribir, la cuenta conectada (salvo que el .env diga otra cosa). */
    private function token(bool $escribir = false): string
    {
        if ($escribir && $this->escribirCon === 'cuenta_conectada') {
            return GoogleOAuth::tokenDeAcceso();
        }

        return $this->tokenCuentaServicio();
    }

    /** Token de la cuenta de servicio. Se pide una vez y se reutiliza 55 minutos. */
    private function tokenCuentaServicio(): string
    {
        // La llave del caché incluye el permiso: si cambia, se pide un token nuevo
        return Cache::remember('drive_token_'.md5(self::PERMISO), now()->addMinutes(55), function () {
            $cred = $this->credenciales();
            $ahora = time();

            // JWT = base64url(encabezado) . base64url(datos) . base64url(firma)
            $encabezado = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $datos = $this->b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => self::PERMISO,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $ahora,
                'exp' => $ahora + 3600,
            ]));
            $firma = '';
            if (! openssl_sign("$encabezado.$datos", $firma, $cred['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('No se pudo firmar con la llave de la Service Account (¿JSON correcto?).');
            }

            $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => "$encabezado.$datos.".$this->b64($firma),
            ]);
            if ($r->failed()) {
                throw new RuntimeException('Google rechazó la Service Account: '.($r->json('error_description') ?? $r->body()));
            }

            return $r->json('access_token');
        });
    }

    private function credenciales(): array
    {
        $ruta = str_starts_with($this->rutaCredenciales, '/') ? $this->rutaCredenciales : base_path($this->rutaCredenciales);
        if (! is_file($ruta)) {
            throw new RuntimeException("No encuentro el JSON de la Service Account en $ruta");
        }
        $cred = json_decode(file_get_contents($ruta), true);
        if (empty($cred['client_email']) || empty($cred['private_key'])) {
            throw new RuntimeException('El JSON de la Service Account no trae client_email y private_key.');
        }

        return $cred;
    }

    /** base64 "para URL": sin = y con - _ en lugar de + / */
    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}
