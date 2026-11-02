# SPEC — SULA Core (AS500) · Módulo Producción + rediseño claro

**Buscar planos por clave en todo el Drive · ver el plano · despiece con IA (Groq) · crear carpetas y subir PDFs a Drive (y copia en el AS500)**

**Fecha:** 2026-11-02 · **Autor:** Gutz + Claude · **Versión que deja:** 0.5.0 · **Este spec reemplaza a cualquier versión anterior de "Producción y diseño".**
**Parte de:** el proyecto `~/dev/sula-core-laravel` con los Módulos 0–3 ya construidos (el Módulo 3 "Planos y despiece" ya corre).

**Este es UN SOLO spec y es un archivo.** No se pega en el chat: se guarda en `docs/` y Claude Code lo lee del disco.

**Qué está verificado y qué no:**
- ✅ **27 pruebas unitarias en verde**: 9 del motor de costeo (el exhibidor sigue dando **$12,317.68**), 12 del despiece con IA y 6 nuevas (limpieza de nombres, y crear carpeta / subir / no sobrescribir / no salirse de la carpeta con la fuente local).
- ✅ **2 migraciones nuevas** probadas en PostgreSQL (subir, bajar, subir). Carpetas y subidas quedan en el historial de cambios; la conexión de Google **no** (el token no debe quedar copiado en el historial).
- ✅ Sintaxis de todos los archivos (`php -l`).
- ⚠️ **No se pudo probar aquí**: las pantallas (Livewire/Tailwind), la conexión real con tu Drive (leer y "Conectar Google") y Groq. La sección 7 trae los criterios para probarlo en tu máquina; si algo truena, se corrige ahí.

---

## 0. INSTRUCCIONES PARA CLAUDE CODE

- Lee **este archivo completo** antes de empezar. Luego **muéstrame la lista de archivos** que vas a crear, reemplazar y borrar (sección 3) y explícame en una línea qué hace cada uno (estoy aprendiendo). Espera mi OK.
- Los archivos marcados **REEMPLAZA** se sobrescriben completos con el contenido de este spec. Los que no aparecen aquí **no se tocan** (motor de costeo, cotizaciones, IA/Groq, normalizador, lector de planos, job, migraciones 1–4).
- **No ejecutes `sudo`** (dime el comando y yo lo corro) y **no hagas `git push`**.
- `datos-privados/` (JSON de Drive, precios, planos de prueba) y `.env` **nunca** se suben a git. El repo es **privado**. Nunca pegues llaves ni el JSON en el chat, el código o los commits.
- Si un comando falla, detente, muéstrame el error y propón la solución. Si una pantalla falla, corrige ese archivo y dime qué cambiaste.
- El proyecto viejo en Go (`~/dev/sula-core`) y el contenedor `sula-core-db` **no se tocan**.

---

## 1. QUÉ CAMBIA

### 1.1 Diseño claro "tipo oficina" (para que cualquiera en SULA lo entienda)
- **Tema claro**: fondo gris muy claro, tarjetas blancas, rojo SULA solo para lo importante (botón principal, alertas, lo que está seleccionado).
- **Menú en palabras normales**, con ícono y una línea que dice para qué sirve. Se van los códigos (INI, JRN, PLN, CST…):

| Área | Pantalla | Para qué sirve |
|---|---|---|
| — | Inicio | Resumen del sistema |
| **Producción** | Planos y despiece | PDFs de Drive, carpetas y despiece con IA |
| **Ventas** | Cotizaciones | Precio de un mueble a partir de su plano |
| **Catálogo** | Materiales | Materiales y sus precios |
| **Catálogo** | Precios y márgenes *(antes "Parámetros")* | Mano de obra, overhead y margen |
| **Sistema** | Historial de cambios *(antes "Journal")* | Quién cambió qué y cuándo |

- **Cada pantalla explica qué es** (`<x-encabezado>`) y trae un desplegable **"¿Cómo se usa esta pantalla?"** con los pasos.
- **Inicio** se vuelve una guía: tarjetas de cada área con qué hace y cuántos registros tiene, más el camino completo **Plano → Despiece → Cotización → Envío**.
- **En celular**, el menú se abre con un botón (☰).
- Como todos los colores salen de los tokens de `app.css`, **todas** las pantallas viejas se ven claras sin reescribirlas. A Materiales, Precios y márgenes, Historial y Cotizaciones solo se les cambia el encabezado.

### 1.2 Módulo Producción (reemplaza "Planos y despiece" del Módulo 3)
- **Explorador tipo Drive**: carpetas con ruta de migas (Todos los planos › VENTO › VITRINAS), buscador en **todas** las carpetas y estado del despiece de cada PDF.
- **Buscador** arriba: escribes la clave (`0072`) o parte del nombre (`vitrina`) y busca en **todas** las carpetas de PLANOS.
- **Subir PDFs** (botón o **arrastrar y soltar**, hasta 20 a la vez, 60 MB cada uno) a la carpeta abierta → se guardan **en Drive, dentro de PLANOS**, y además **una copia dentro del AS500** (`storage/app/private/planos`).
- **Nueva carpeta** → se crea **en Drive**, dentro de PLANOS, y aparece en el sistema al instante.
- **No se borra, renombra ni mueve nada** desde el sistema (decisión de SULA).
- Si ya existe un PDF o carpeta con ese nombre en esa carpeta, **no se sobrescribe**: se avisa (para una revisión nueva, el nombre lleva REV-01, REV-02…).
- Abrir un PDF → la misma pantalla del Módulo 3 (visor + despiece con Groq + aprobar + cotizar), con el diseño nuevo y la ruta de carpetas arriba.

### 1.3 Con qué cuenta entra el sistema a Drive (importante)
La carpeta **PLANOS** vive en el Drive **de Oskar** ("Mi unidad"), compartida con quien la usa. Por eso el sistema usa **dos llaves**:

| Para… | Usa | Por qué |
|---|---|---|
| **Leer** (listar carpetas y PDFs, ver, despiece) | la **cuenta de servicio** `sula-core-drive@…` (archivo JSON) | Oskar ya le compartió PLANOS. Funciona siempre, sin que nadie inicie sesión |
| **Escribir** (crear carpeta, subir PDF) | la **cuenta de Google conectada** con el botón **"Conectar Google"** (una persona que sea Editor de PLANOS, ej. `gutzessp@gmail.com`) | Una cuenta de servicio **no puede guardar archivos en "Mi unidad" de otra persona**: no tiene espacio propio y Google responde *"Service Accounts do not have storage quota"*. Una persona sí: lo que sube cuenta en su espacio, igual que si lo subiera a mano |

- "Conectar Google" se hace **una sola vez**. Se guarda una llave de larga duración (refresh token) **cifrada** con la `APP_KEY` de Laravel; nunca aparece en pantalla ni en el historial.
- Sin cuenta conectada, el sistema **funciona igual para ver y sacar despiece**; los botones de subir y crear carpeta se cambian por "Conectar Google para subir".
- Si algún día SULA mueve PLANOS a una **unidad compartida**, se puede escribir con la cuenta de servicio y olvidarse del botón: `PLANOS_DRIVE_ESCRIBIR_CON=cuenta_servicio`.

### 1.4 Cómo funciona por dentro
```
                 ┌──────────── carpeta PLANOS (Drive de Oskar) ─────────────────────┐
                 │  PROMODA/  KFC/  LIVERPOOL/  …   *.pdf                            │
                 └───────▲──────────────────────────┬────────────────────────────────┘
       crear carpeta /   │                          │  "Actualizar desde Drive"
       subir PDF         │                          │  (php artisan sula:planos)
                         │                          ▼
  Explorador ──► FuenteDrive ──► Sincronizador ──► tablas carpetas + planos (espejo, para navegar rápido)
          leer: cuenta de servicio (JWT)            │
          escribir: cuenta conectada (OAuth)        │
                                                    ▼
                                    Plano ──► Leer con IA (Groq, cola) ──► Aprobar ──► Cotizar
```
- La tabla **`carpetas`** es un **espejo** de Drive: navegar no le pregunta nada a Drive (es instantáneo). Lo que se crea o sube desde el sistema se escribe primero en Drive y luego se registra en el espejo, sin volver a leer todo.
- Lo que alguien agregue **directo en Drive** aparece al darle **"Actualizar desde Drive"**.
- La sincronización ya **no toca** los registros que no cambiaron (antes actualizaba la fecha de cada plano); así el historial de cambios solo muestra cambios reales. La fecha de la última sincronización vive en `configuracion` (`planos_ultima_sync`).

---

## 2. LO QUE HAGO YO ANTES (no Claude Code)

**Ya hecho ✅**
- Cuenta de servicio `sula-core-drive@gen-lang-client-0400856265.iam.gserviceaccount.com`; su JSON está en `datos-privados/drive-service-account.json`.
- Oskar compartió **PLANOS** como **Editor** con esa cuenta y con `gutzessp@gmail.com`.
- Key de Groq en el `.env` y Zero Data Retention activado.

**1. Cliente OAuth para "Conectar Google"** (mismo proyecto de Google Cloud)
1. https://console.cloud.google.com/auth/overview?project=gen-lang-client-0400856265 → **Comenzar**: nombre `SULA Core`, correo de asistencia el tuyo, público **Externo**, contacto tu correo → **Crear**.
2. **Público** → **Usuarios de prueba** → agrega `gutzessp@gmail.com` (y quien más vaya a conectar). Luego **Publicar app** (pasar a "En producción"): si se queda en "Prueba", Google desconecta la cuenta **cada 7 días**. Al conectar saldrá *"Google no verificó esta app"* → **Avanzado → Ir a SULA Core** (normal: es de uso interno).
3. **Clientes → + Crear cliente** → **Aplicación web** → nombre `SULA Core local` → **URI de redireccionamiento autorizados**: `http://localhost:8000/google/callback` → **Crear**.
4. Copia el **ID de cliente** y el **Secreto** al `.env` (sección 3.1). **No los pegues en ningún chat.**

**2. PHP: permitir subir PDFs grandes** (trae 2 MB por defecto)
```bash
sudo sed -i 's/^upload_max_filesize = .*/upload_max_filesize = 64M/; s/^post_max_size = .*/post_max_size = 64M/' /etc/php/php.ini
php -i | grep -E "upload_max_filesize|post_max_size"     # deben decir 64M
```

**3. Poppler** (si no está): `sudo pacman -S --needed poppler`

---

## 3. CONFIGURACIÓN Y LISTA DE ARCHIVOS

### 3.1 `.env`: así deben quedar estas secciones

```dotenv
# ---------- Planos ----------
PLANOS_FUENTE=drive
PLANOS_DRIVE_CREDENCIALES=datos-privados/drive-service-account.json
PLANOS_DRIVE_CARPETA=1ThH0qcrINnLc67x03qaCsmoyNIvHZ1Ya
# Con qué cuenta se crean carpetas y se suben PDFs (cuenta_conectada = botón "Conectar Google")
PLANOS_DRIVE_ESCRIBIR_CON=cuenta_conectada
PLANOS_CARPETA_LOCAL=
PLANOS_PATRON_CLAVE=
PLANOS_CARACTERES_POR_LOTE=5000
PLANOS_IMAGENES_POR_LOTE=1

# ---------- Conectar Google (OAuth) ----------
GOOGLE_OAUTH_CLIENT_ID=
GOOGLE_OAUTH_CLIENT_SECRET=
GOOGLE_OAUTH_REDIRECT=http://localhost:8000/google/callback

# ---------- Groq ----------
GROQ_API_KEY=gsk_...   (la que ya tienes)
GROQ_MODELO_TEXTO=llama-3.3-70b-versatile
GROQ_MODELO_VISION=qwen/qwen3.8-27b
GROQ_MAX_TOKENS=3000
GROQ_MAX_TOKENS_VISION=2000
```
- `PLANOS_DRIVE_CREDENCIALES` **no es una llave**: es la ruta del archivo JSON.
- `GOOGLE_OAUTH_REDIRECT` debe ser **idéntico** al URI que pusiste en Google Cloud (si usas otro puerto, cámbialo en los dos lados).
- La `APP_KEY` del `.env` cifra la conexión de Google: **no la cambies** después de conectar (si cambia, solo hay que volver a darle "Conectar Google").

### 3.2 Livewire: subir archivos de hasta 60 MB

Livewire guarda los archivos en temporal antes de "Subir a Drive" y por defecto solo acepta 12 MB:
```bash
php artisan livewire:publish --config
```
(Si en Livewire 4 el comando se llama distinto, usa el que indique `php artisan list livewire`.) En `config/livewire.php`, dentro de `'temporary_file_upload'`, deja:
```php
'rules' => ['required', 'file', 'max:61440'],   // 60 MB
'max_upload_time' => 10,                          // minutos
```

### 3.3 Archivos

| # | Archivo | Acción | Qué es |
|---|---|---|---|
| 1 | `database/migrations/2026_11_02_100005_crear_carpetas.php` | **NUEVO** | Tabla `carpetas` + `planos.carpeta_id` y `planos.subido_en_sistema` |
| 2 | `app/Models/Carpeta.php` | **NUEVO** | Modelo de carpeta (padre, hijas, ruta de migas) |
| 3 | `app/Models/Plano.php` | **REEMPLAZA** | + relación con su carpeta |
| 4 | `app/Planos/Fuentes/CarpetaOrigen.php` | **NUEVO** | Datos de una carpeta en Drive/local |
| 5 | `app/Planos/Fuentes/ArchivoPlano.php` | **REEMPLAZA** | + id de su carpeta |
| 6 | `app/Planos/Fuentes/FuentePlanos.php` | **REEMPLAZA** | Contrato: explorar, descargar, crear carpeta, subir |
| 7 | `app/Planos/Fuentes/FuenteDrive.php` | **REEMPLAZA** | Leer con la cuenta de servicio y escribir con la cuenta conectada |
| 8 | `app/Planos/Fuentes/FuenteLocal.php` | **REEMPLAZA** | Lo mismo en una carpeta local |
| 9 | `app/Planos/NombreArchivo.php` | **REEMPLAZA** | + limpiar nombres de carpeta y de PDF |
| 10 | `app/Planos/Sincronizador.php` | **REEMPLAZA** | Espejo de carpetas y planos; registrar lo creado aquí |
| 11 | `app/Console/Commands/SincronizarPlanos.php` | **REEMPLAZA** | Muestra también las carpetas |
| 11a | `database/migrations/2026_11_02_100006_crear_conexion_google.php` | **NUEVO** | Tabla de la cuenta conectada (token cifrado, sin auditar) |
| 11b | `app/Models/ConexionGoogle.php` | **NUEVO** | Modelo; el token se cifra solo (`encrypted`) |
| 11c | `app/Planos/Fuentes/GoogleOAuth.php` | **NUEVO** | "Conectar Google": pedir permiso, canjear, renovar y quitar |
| 11d | `app/Http/Controllers/GoogleController.php` | **NUEVO** | Rutas `/google/conectar`, `/google/callback`, `/google/desconectar` |
| 11e | `app/Planos/Fuentes/Fuente.php` | **REEMPLAZA** | Pasa a Drive con qué cuenta escribir |
| 11f | `app/Planos/AlmacenPdf.php` | **REEMPLAZA** | + `guardarCopia()`: el PDF subido se guarda también dentro del AS500 |
| 12 | `app/Livewire/Produccion/Explorador.php` + `resources/views/livewire/produccion/explorador.blade.php` | **NUEVO** | Explorador: carpetas, buscar, subir, crear carpeta |
| 13 | `app/Livewire/Produccion/Plano.php` + `produccion/plano.blade.php` + `produccion/_despiece.blade.php` | **NUEVO** | El visor + despiece del Módulo 3, movido a Producción con diseño nuevo |
| 14 | `app/Livewire/Planos/` y `resources/views/livewire/planos/` | **BORRAR** | Los reemplazan los dos anteriores |
| 15 | `resources/views/components/icono.blade.php` | **NUEVO** | Íconos SVG (sin librerías) |
| 16 | `resources/views/components/encabezado.blade.php` | **NUEVO** | Encabezado con "¿Cómo se usa?" |
| 17 | `resources/css/app.css` | **REEMPLAZA** | Tema claro |
| 18 | `resources/views/layouts/app.blade.php` | **REEMPLAZA** | Menú nuevo |
| 19 | `app/Livewire/Inicio.php` + `livewire/inicio.blade.php` | **REEMPLAZA** | Inicio como guía |
| 20 | `livewire/materiales/indice.blade.php`, `parametros.blade.php`, `journal.blade.php`, `cotizaciones/indice.blade.php`, `cotizaciones/editor.blade.php`, `cotizaciones/pestanas/despiece.blade.php`, `cotizaciones/pestanas/simulador.blade.php` | **REEMPLAZA** | Solo encabezados y etiquetas (la lógica no cambia) |
| 21 | `routes/web.php` | **REEMPLAZA** | `/produccion`, `/google/*`; `/planos` redirige |
| 22 | `config/sula.php` | **REEMPLAZA** | Versión 0.5.0 + escribir con + Google OAuth |
| 23 | `tests/Unit/NombreArchivoTest.php` | **REEMPLAZA** | 6 pruebas |
| 24 | `tests/Unit/FuenteLocalTest.php` | **NUEVO** | 4 pruebas |

**No se toca:** `app/Costeo`, `app/Cotizaciones`, `app/Ia`, `app/Jobs`, `app/Planos/{Normalizador,LectorPlano,Prompts,FlujoDespiece,ExtractorPdf,ErrorDespiece}.php`, `app/Http/Controllers/PdfPlanoController.php`, los modelos de despiece, `chip-*.blade.php`, migraciones 1–4, `docker-compose.yml`.

---

## 4. BASE DE DATOS Y MODELOS

### `database/migrations/2026_11_02_100005_crear_carpetas.php`

**NUEVO.**

- `carpetas.padre_id` apunta a otra carpeta de la misma tabla (**árbol**). `NULL` = está en la raíz (la carpeta raíz del `.env` no se guarda).
- `ruta` ("VENTO/VITRINAS") se guarda ya armada para mostrar y buscar sin recorrer el árbol.
- `creada_en_sistema` / `subido_en_sistema`: marcan lo que se hizo desde el AS500 (se ve la etiqueta "subido aquí").
- `auditar('carpetas')` y `auditar('planos')`: crear carpeta o subir PDF queda en el **Historial de cambios**.
- `down()` borra el trigger `auditoria` de `planos` y las columnas: se puede deshacer limpio.

````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Módulo Producción: carpetas de Drive (o de la carpeta local) dentro del sistema.
 * Se guardan para poder navegar rápido sin preguntarle a Drive en cada clic.
 * La carpeta raíz (PLANOS_DRIVE_CARPETA) NO se guarda: padre_id NULL = está en la raíz.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE carpetas (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    origen           TEXT   NOT NULL CHECK (origen IN ('DRIVE', 'LOCAL')),
    archivo_id       TEXT   NOT NULL,                                  -- id de Drive o ruta relativa
    nombre           TEXT   NOT NULL CHECK (btrim(nombre) <> ''),
    padre_id         BIGINT REFERENCES carpetas (id) ON DELETE CASCADE, -- NULL = en la raíz
    ruta             TEXT   NOT NULL,                                  -- "VENTO/VITRINAS" (para mostrar y buscar)
    activo           BOOLEAN NOT NULL DEFAULT true,                    -- false = ya no está en el origen
    creada_en_sistema BOOLEAN NOT NULL DEFAULT false,                  -- la creó alguien desde el AS500
    sincronizado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    creado_en        TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (origen, archivo_id)
);
CREATE INDEX idx_carpetas_padre ON carpetas (padre_id);
SELECT auditar('carpetas');

-- Cada plano sabe en qué carpeta vive (NULL = raíz)
ALTER TABLE planos ADD COLUMN carpeta_id BIGINT REFERENCES carpetas (id) ON DELETE SET NULL;
ALTER TABLE planos ADD COLUMN subido_en_sistema BOOLEAN NOT NULL DEFAULT false;
CREATE INDEX idx_planos_carpeta ON planos (carpeta_id);

-- Las subidas desde el sistema quedan en el journal
SELECT auditar('planos');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS auditoria ON planos;
ALTER TABLE planos DROP COLUMN IF EXISTS subido_en_sistema;
ALTER TABLE planos DROP COLUMN IF EXISTS carpeta_id;
DROP TABLE IF EXISTS carpetas;
SQL);
    }
};
````

### `app/Models/Carpeta.php`

**NUEVO.**

- `padre()` / `hijas()`: la carpeta de arriba y las de adentro (relación de la tabla consigo misma).
- `camino()`: sube de padre en padre hasta la raíz → `[VENTO, VITRINAS]` para la ruta de migas. El tope de 50 vueltas evita un ciclo infinito si los datos vinieran mal.

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Una carpeta de planos (espejo de Drive o de la carpeta local). padre_id NULL = en la raíz. */
class Carpeta extends Model
{
    protected $table = 'carpetas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'creada_en_sistema' => 'boolean', 'sincronizado_en' => 'datetime'];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(Carpeta::class, 'padre_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(Carpeta::class, 'padre_id')->where('activo', true);
    }

    public function planos(): HasMany
    {
        return $this->hasMany(Plano::class)->where('activo', true);
    }

    /** De la raíz hasta esta carpeta: [VENTO, VITRINAS] (para la "ruta de migas"). */
    public function camino(): array
    {
        $camino = [];
        $c = $this;
        $vueltas = 0;
        while ($c && $vueltas++ < 50) {
            array_unshift($camino, $c);
            $c = $c->padre;
        }

        return $camino;
    }
}
````

### `app/Models/Plano.php`

**REEMPLAZA.**

Se agrega `carpetaPadre()` (se llama así porque `carpeta` ya es la columna de texto con la ruta).

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Un PDF de plano en Drive (o en la carpeta local). El PDF no vive en la base de datos. */
class Plano extends Model
{
    protected $table = 'planos';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['activo' => 'boolean', 'subido_en_sistema' => 'boolean', 'modificado_en_origen' => 'datetime', 'sincronizado_en' => 'datetime'];
    }

    public function carpetaPadre(): BelongsTo
    {
        return $this->belongsTo(Carpeta::class, 'carpeta_id');
    }

    public function despieces(): HasMany
    {
        return $this->hasMany(Despiece::class)->orderByDesc('version');
    }

    /** El despiece aprobado (si hay). */
    public function aprobado(): HasOne
    {
        return $this->hasOne(Despiece::class)->where('estado', 'APROBADO');
    }

    /** El despiece más reciente, sea cual sea su estado. */
    public function ultimo(): HasOne
    {
        return $this->hasOne(Despiece::class)->latestOfMany('version');
    }
}
````

## 5. DRIVE: LEER Y ESCRIBIR

### `app/Planos/Fuentes/CarpetaOrigen.php`

**NUEVO.**

Objeto de solo lectura (`readonly`) con lo que Drive o la carpeta local dicen de una carpeta.

````php
<?php

namespace App\Planos\Fuentes;

/** Una carpeta en la fuente (Drive o local). */
final readonly class CarpetaOrigen
{
    public function __construct(
        public string $id,        // id de Drive o ruta relativa
        public string $nombre,    // "VITRINAS"
        public ?string $padreId,  // id de la carpeta padre (null = raíz)
        public string $ruta,      // "VENTO/VITRINAS"
    ) {}
}
````

### `app/Planos/Fuentes/ArchivoPlano.php`

**REEMPLAZA.**

Se agrega `carpetaId`: el id **en Drive** de la carpeta donde vive el PDF (null = raíz).

````php
<?php

namespace App\Planos\Fuentes;

/** Lo que una fuente (Drive o carpeta local) dice de un PDF. */
final readonly class ArchivoPlano
{
    public function __construct(
        public string $id,          // id de Drive o ruta relativa
        public string $nombre,      // "2-1-0072-0003 VITRINA REV-01.pdf"
        public ?string $carpetaId,  // id de la carpeta donde vive (null = raíz)
        public ?string $carpeta,    // ruta legible: "VENTO/VITRINAS"
        public ?int $tamano,
        public ?string $huella,     // md5 (Drive) o fecha+tamaño (local)
        public ?string $modificado, // fecha ISO 8601
    ) {}
}
````

### `app/Planos/Fuentes/FuentePlanos.php`

**REEMPLAZA.**

- `listar()` se vuelve `explorar()`: ahora regresa **carpetas y archivos**, con el padre siempre antes que sus hijas (así el sincronizador puede ligar cada carpeta a su padre en una sola pasada).
- `crearCarpeta()` y `subir()`: lo nuevo. **No hay** borrar, renombrar ni mover: ni siquiera existen en el contrato.

````php
<?php

namespace App\Planos\Fuentes;

/**
 * "Contrato" que cumple cualquier lugar de donde salen los planos.
 * Hoy hay dos: Google Drive (carpeta PLANOS) y una carpeta local.
 * El resto del sistema no sabe ni le importa de cuál viene el PDF.
 *
 * A propósito NO hay borrar, renombrar ni mover: desde el sistema
 * solo se sube y se crean carpetas (decisión de SULA).
 */
interface FuentePlanos
{
    /** 'DRIVE' o 'LOCAL' (se guarda en planos.origen) */
    public function origen(): string;

    /**
     * Todo lo que hay, recorriendo subcarpetas. Las carpetas vienen
     * ordenadas de arriba hacia abajo (el padre siempre antes que sus hijas).
     * @return array{carpetas: CarpetaOrigen[], archivos: ArchivoPlano[]}
     */
    public function explorar(): array;

    /** Los bytes del PDF. */
    public function descargar(string $id): string;

    /** Crea una carpeta dentro de otra (null = en la raíz). */
    public function crearCarpeta(string $nombre, ?string $padreId, string $rutaPadre = ''): CarpetaOrigen;

    /** Sube un PDF a una carpeta (null = en la raíz). */
    public function subir(string $nombre, string $bytes, ?string $carpetaId, string $rutaCarpeta = ''): ArchivoPlano;
}
````

### `app/Planos/Fuentes/FuenteDrive.php`

**REEMPLAZA.**

- **Dos llaves**: `token(escribir: false)` = cuenta de servicio (leer); `token(escribir: true)` = cuenta conectada (`GoogleOAuth`), salvo que `PLANOS_DRIVE_ESCRIBIR_CON=cuenta_servicio`.
- **Permiso `drive`** (antes `drive.readonly`). La llave del caché del token incluye el permiso, así que el token viejo de solo lectura se ignora solo.
- `corpora=allDrives` + `supportsAllDrives` + `includeItemsFromAllDrives`: necesarios para que Google busque dentro de **unidades compartidas**.
- `crearCarpeta()`: un `POST /files` con `mimeType` de carpeta y su padre.
- `subir()`: **subida reanudable** de Google en 2 pasos: (1) se mandan nombre y carpeta → Google regresa una URL de subida; (2) se mandan los bytes del PDF a esa URL. Sirve igual para 1 MB que para 60 MB.
- `existe()`: Drive **sí permite** dos archivos con el mismo nombre en la misma carpeta; nosotros no, para no confundir revisiones. Las comillas del nombre se "escapan" para que no rompan la consulta.
- `revisar()`: traduce errores de Google a mensajes claros (sin espacio en Mi unidad, 404 = PLANOS no está compartida con la cuenta, 403 = falta permiso de Editor).

````php
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
````

### `app/Planos/Fuentes/FuenteLocal.php`

**REEMPLAZA.**

- `SELF_FIRST` recorre primero la carpeta y luego su contenido; además se ordenan por profundidad (padre antes que hija).
- `dentro()`: **seguridad**. Rechaza rutas con `..` y verifica con `realpath` que el archivo quede dentro de la carpeta raíz. Nadie puede pedir `../../.env`.
- `crearCarpeta()` / `subir()`: nunca sobrescriben; si ya existe, avisan.

````php
<?php

namespace App\Planos\Fuentes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Planos desde una carpeta de tu máquina (PLANOS_CARPETA_LOCAL).
 * Sirve para probar sin Drive. Los "ids" son rutas relativas: "VENTO/VITRINAS/plano.pdf".
 */
class FuenteLocal implements FuentePlanos
{
    public function __construct(private string $raiz)
    {
        $this->raiz = rtrim($raiz, '/');
    }

    public function origen(): string
    {
        return 'LOCAL';
    }

    public function explorar(): array
    {
        if (! is_dir($this->raiz)) {
            throw new RuntimeException("La carpeta de planos no existe: {$this->raiz}");
        }

        $carpetas = [];
        $archivos = [];
        // SELF_FIRST: primero la carpeta y luego lo que tiene adentro (el padre antes que los hijos)
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->raiz, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $f) {
            $relativa = ltrim(substr($f->getPathname(), strlen($this->raiz)), '/');
            $padre = dirname($relativa);
            $padre = $padre === '.' ? null : $padre;

            if ($f->isDir()) {
                $carpetas[] = new CarpetaOrigen($relativa, $f->getFilename(), $padre, $relativa);
            } elseif (strtolower($f->getExtension()) === 'pdf') {
                $archivos[] = new ArchivoPlano(
                    id: $relativa,
                    nombre: $f->getFilename(),
                    carpetaId: $padre,
                    carpeta: $padre,
                    tamano: $f->getSize(),
                    huella: $f->getMTime().'-'.$f->getSize(),
                    modificado: date(DATE_ATOM, $f->getMTime()),
                );
            }
        }
        // Ordena carpetas por profundidad: el padre siempre antes que sus hijas
        usort($carpetas, fn ($a, $b) => substr_count($a->id, '/') <=> substr_count($b->id, '/') ?: strcmp($a->id, $b->id));

        return ['carpetas' => $carpetas, 'archivos' => $archivos];
    }

    public function descargar(string $id): string
    {
        return file_get_contents($this->dentro($id, debeExistir: true));
    }

    public function crearCarpeta(string $nombre, ?string $padreId, string $rutaPadre = ''): CarpetaOrigen
    {
        $relativa = ltrim(($padreId ? $padreId.'/' : '').$nombre, '/');
        $ruta = $this->dentro($relativa, debeExistir: false);
        if (file_exists($ruta)) {
            throw new RuntimeException("Ya existe una carpeta llamada «{$nombre}» aquí.");
        }
        mkdir($ruta, 0775, true);

        return new CarpetaOrigen($relativa, $nombre, $padreId, $relativa);
    }

    public function subir(string $nombre, string $bytes, ?string $carpetaId, string $rutaCarpeta = ''): ArchivoPlano
    {
        $relativa = ltrim(($carpetaId ? $carpetaId.'/' : '').$nombre, '/');
        $ruta = $this->dentro($relativa, debeExistir: false);
        if (file_exists($ruta)) {
            throw new RuntimeException("Ya existe «{$nombre}» en esta carpeta.");
        }
        file_put_contents($ruta, $bytes);
        clearstatcache(true, $ruta);

        return new ArchivoPlano($relativa, $nombre, $carpetaId, $carpetaId, filesize($ruta),
            filemtime($ruta).'-'.filesize($ruta), date(DATE_ATOM, filemtime($ruta)));
    }

    /** Ruta absoluta segura: nunca deja salir de la carpeta raíz con "../". */
    private function dentro(string $relativa, bool $debeExistir): string
    {
        if (preg_match('#(^|/)\.\.(/|$)#', $relativa) || str_contains($relativa, "\0")) {
            throw new RuntimeException('Ruta no permitida.');
        }
        $raiz = realpath($this->raiz);
        if ($raiz === false) {
            throw new RuntimeException("La carpeta de planos no existe: {$this->raiz}");
        }
        $ruta = $raiz.'/'.$relativa;
        if ($debeExistir) {
            $real = realpath($ruta);
            if ($real === false || ! str_starts_with($real, $raiz.DIRECTORY_SEPARATOR)) {
                throw new RuntimeException("El plano no existe en la carpeta local: $relativa");
            }

            return $real;
        }

        return $ruta;
    }
}
````

### `app/Planos/NombreArchivo.php`

**REEMPLAZA.**

- `limpiarCarpeta()`: quita `/ \ : * ? " < > |` y caracteres invisibles (Drive los aceptaría, pero rompen rutas y descargas en Windows/Linux).
- `limpiarPdf()`: del nombre que trae el archivo subido (a veces con la ruta completa de Windows) deja solo el nombre y le asegura `.pdf`.

````php
<?php

namespace App\Planos;

/**
 * Saca la clave y la revisión del NOMBRE del PDF.
 *   "2-1-0072-0003 VITRINA PARA REFACCIONES REV-01.pdf" → clave 2-1-0072-0003, REV-01
 *   "SULA - MODULO EXHIBIDOR PL - FT.pdf"               → clave "SULA - MODULO EXHIBIDOR PL - FT", REV-00
 * El patrón de la clave se puede cambiar en el .env (PLANOS_PATRON_CLAVE).
 */
final class NombreArchivo
{
    public const PATRON_CLAVE = '/\d{1,2}-\d{1,2}-\d{3,5}-\d{3,5}/';

    public static function clave(string $nombre, string $patron = self::PATRON_CLAVE): string
    {
        if (@preg_match($patron, $nombre, $m) && isset($m[0])) {
            return $m[0];
        }

        // Sin clave en el nombre: usamos el nombre limpio (sin .pdf ni "REV-xx")
        $limpio = preg_replace('/\.pdf$/i', '', $nombre);
        $limpio = preg_replace('/[\s_-]*\bREV[\s._-]*\d{1,3}\b/i', '', $limpio);
        $limpio = preg_replace('/[\s_]+/', ' ', $limpio);

        return mb_strtoupper(trim($limpio, " -_."));
    }

    public static function revision(string $nombre): string
    {
        if (preg_match('/\bREV[\s._-]*(\d{1,3})\b/i', $nombre, $m)) {
            return 'REV-'.str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }

        return 'REV-00';
    }

    /** Nombre de carpeta válido en Drive y en Linux: sin / \ : * ? " < > | y sin espacios de sobra. */
    public static function limpiarCarpeta(string $nombre): string
    {
        $n = preg_replace('#[/\\\\:*?"<>|\x00-\x1F]+#u', ' ', $nombre);

        return trim(preg_replace('/\s+/u', ' ', $n), ' .');
    }

    /** Nombre de PDF subido: sin ruta, sin caracteres raros y siempre terminado en .pdf */
    public static function limpiarPdf(string $nombre): string
    {
        $n = self::limpiarCarpeta(basename(str_replace('\\', '/', $nombre)));
        $n = preg_replace('/\.pdf$/i', '', $n);

        return ($n === '' ? 'plano' : $n).'.pdf';
    }
}
````

### `app/Planos/Sincronizador.php`

**REEMPLAZA.**

- Paso 1, **carpetas**: como vienen padre-antes-que-hija, se va armando `$mapa` (id en Drive → id en la base) y cada hija encuentra a su padre.
- Paso 2, **planos**: cada uno queda ligado a su carpeta con ese mapa.
- `cambio()`: compara solo lo importante (nombre, clave, carpeta, huella…). Si nada cambió, **no se escribe**, y el historial de cambios no se llena de ruido.
- `registrarCarpeta()` / `registrarPlano()`: lo que se crea o sube desde el sistema entra al espejo al instante, sin releer Drive.

````php
<?php

namespace App\Planos;

use App\Models\Carpeta;
use App\Models\Configuracion;
use App\Models\Plano;
use App\Planos\Fuentes\ArchivoPlano;
use App\Planos\Fuentes\CarpetaOrigen;
use App\Planos\Fuentes\FuentePlanos;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día las tablas "carpetas" y "planos" con lo que hay en la fuente (Drive o local).
 *   - Nuevos → se agregan.  - Cambiados (nombre, huella, carpeta) → se actualizan.
 *   - Los que ya no están → activo = false (no se borran: pueden tener despiece).
 * Los que no cambiaron NO se tocan, para no llenar el historial de cambios.
 */
class Sincronizador
{
    /** @return array{nuevos: int, actualizados: int, retirados: int, total: int, carpetas: int} */
    public static function sincronizar(FuentePlanos $fuente): array
    {
        $todo = $fuente->explorar();
        $origen = $fuente->origen();
        $nuevos = $actualizados = 0;

        DB::transaction(function () use ($todo, $origen, &$nuevos, &$actualizados) {
            // 1. Carpetas (vienen con el padre antes que las hijas)
            $mapa = [];   // id en el origen → id en la base de datos
            $vistas = [];
            foreach ($todo['carpetas'] as $c) {
                $mapa[$c->id] = self::guardarCarpeta($origen, $c, $mapa)->id;
                $vistas[] = $c->id;
            }
            Carpeta::where('origen', $origen)->where('activo', true)
                ->whereNotIn('archivo_id', $vistas ?: [''])->update(['activo' => false]);

            // 2. Planos
            $existentes = Plano::where('origen', $origen)->get()->keyBy('archivo_id');
            foreach ($todo['archivos'] as $a) {
                $plano = $existentes[$a->id] ?? null;
                $datos = self::datosPlano($a, $mapa);
                if (! $plano) {
                    Plano::create($datos + ['origen' => $origen, 'archivo_id' => $a->id]);
                    $nuevos++;
                } elseif (self::cambio($plano, $datos)) {
                    $plano->update($datos);
                    $actualizados++;
                }
            }
        });

        $ids = array_map(fn ($a) => $a->id, $todo['archivos']);
        $retirados = Plano::where('origen', $origen)->where('activo', true)
            ->whereNotIn('archivo_id', $ids ?: [''])
            ->update(['activo' => false]);

        Configuracion::updateOrCreate(['clave' => 'planos_ultima_sync'],
            ['valor' => now()->toIso8601String(), 'descripcion' => 'Última sincronización de planos']);

        return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'retirados' => $retirados,
            'total' => count($todo['archivos']), 'carpetas' => count($todo['carpetas'])];
    }

    /** Registra una carpeta recién creada desde el sistema (sin volver a leer todo Drive). */
    public static function registrarCarpeta(string $origen, CarpetaOrigen $c, ?Carpeta $padre): Carpeta
    {
        return Carpeta::create([
            'origen' => $origen,
            'archivo_id' => $c->id,
            'nombre' => $c->nombre,
            'padre_id' => $padre?->id,
            'ruta' => $c->ruta,
            'creada_en_sistema' => true,
        ]);
    }

    /** Registra un PDF recién subido desde el sistema. */
    public static function registrarPlano(string $origen, ArchivoPlano $a, ?Carpeta $carpeta): Plano
    {
        return Plano::create(self::datosPlano($a, []) + [
            'origen' => $origen,
            'archivo_id' => $a->id,
            'carpeta_id' => $carpeta?->id,
            'subido_en_sistema' => true,
        ]);
    }

    private static function guardarCarpeta(string $origen, CarpetaOrigen $c, array $mapa): Carpeta
    {
        $datos = [
            'nombre' => $c->nombre,
            'padre_id' => $c->padreId !== null ? ($mapa[$c->padreId] ?? null) : null,
            'ruta' => $c->ruta,
            'activo' => true,
        ];
        $carpeta = Carpeta::firstOrNew(['origen' => $origen, 'archivo_id' => $c->id]);
        if (! $carpeta->exists || self::cambio($carpeta, $datos)) {
            $carpeta->fill($datos + ['sincronizado_en' => now()])->save();
        }

        return $carpeta;
    }

    private static function datosPlano(ArchivoPlano $a, array $mapa): array
    {
        $patron = config('sula.planos.patron_clave') ?: NombreArchivo::PATRON_CLAVE;
        $datos = [
            'nombre' => $a->nombre,
            'clave' => NombreArchivo::clave($a->nombre, $patron),
            'revision' => NombreArchivo::revision($a->nombre),
            'carpeta' => $a->carpeta,
            'tamano_bytes' => $a->tamano,
            'huella' => $a->huella,
            'modificado_en_origen' => $a->modificado,
            'activo' => true,
            'sincronizado_en' => now(),
        ];
        if ($mapa !== []) {
            $datos['carpeta_id'] = $a->carpetaId !== null ? ($mapa[$a->carpetaId] ?? null) : null;
        }

        return $datos;
    }

    /** ¿Cambió algo importante? (se ignoran las fechas de sincronización) */
    private static function cambio($modelo, array $datos): bool
    {
        foreach ($datos as $k => $v) {
            if (in_array($k, ['sincronizado_en', 'modificado_en_origen', 'tamano_bytes'], true)) {
                continue;
            }
            $actual = $modelo->getAttribute($k);
            if ((string) $actual !== (string) ($v === true ? '1' : ($v === false ? '' : $v))) {
                return true;
            }
        }

        return false;
    }
}
````

## 5b. CONECTAR GOOGLE (para escribir) Y COPIA EN EL AS500

### `database/migrations/2026_11_02_100006_crear_conexion_google.php`

**NUEVO.**

- Una sola fila: la cuenta conectada. `refresh_token` guarda la llave **cifrada**.
- **No** lleva `auditar()`: el historial de cambios guardaría una copia del token.

````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * La cuenta de Google que se conectó con "Conectar Google" (OAuth).
 * El sistema trabaja en Drive COMO esa persona: lee PLANOS, crea carpetas y sube PDFs.
 *
 * refresh_token va CIFRADO por Laravel (con APP_KEY): aunque alguien vea la base
 * de datos, no puede usarlo. A propósito esta tabla NO se audita (el historial
 * de cambios guardaría una copia del token).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE conexion_google (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email          TEXT NOT NULL,
    refresh_token  TEXT NOT NULL,          -- cifrado
    permisos       TEXT NOT NULL,          -- scopes que dio la persona
    conectado_por  TEXT,
    conectado_en   TIMESTAMPTZ NOT NULL DEFAULT now()
);
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS conexion_google;');
    }
};
````

### `app/Models/ConexionGoogle.php`

**NUEVO.**

- Cast `'encrypted'`: Laravel cifra el token al guardar y lo descifra al leer, con la `APP_KEY`. Aunque alguien vea la base de datos, no le sirve.
- `$hidden`: el token nunca sale si el modelo se convierte a JSON.

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** La cuenta de Google conectada (solo hay una). El refresh_token se guarda cifrado. */
class ConexionGoogle extends Model
{
    protected $table = 'conexion_google';

    const CREATED_AT = 'conectado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $hidden = ['refresh_token'];   // nunca sale en JSON ni en dumps

    protected function casts(): array
    {
        // 'encrypted': Laravel lo cifra al guardar y lo descifra al leer (usa APP_KEY)
        return ['refresh_token' => 'encrypted', 'conectado_en' => 'datetime'];
    }

    public static function actual(): ?self
    {
        return static::latest('id')->first();
    }
}
````

### `app/Planos/Fuentes/GoogleOAuth.php`

**NUEVO.**

- **OAuth 2.0** en 3 pasos: (1) mandar a la persona a Google a dar permiso; (2) Google regresa con un `code` que se cambia por un **refresh token**; (3) con el refresh token se piden tokens de 1 hora cuando haga falta (se guardan 50 min en caché).
- `access_type=offline` + `prompt=consent`: así Google sí entrega el refresh token.
- `invalid_grant` = la persona quitó el permiso o venció: el mensaje dice que vuelva a conectar.
- `desconectar()` le avisa a Google que anule el permiso (revoke) y lo borra de aquí.

````php
<?php

namespace App\Planos\Fuentes;

use App\Models\ConexionGoogle;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * "Conectar Google" (OAuth 2.0): el sistema trabaja en Drive COMO una persona.
 *
 * Por qué: la carpeta PLANOS vive en el Drive de una persona (Mi unidad).
 * Una cuenta de servicio puede LEER ahí, pero no puede GUARDAR archivos
 * (no tiene espacio propio). Una persona con permiso de Editor sí puede:
 * lo que se sube cuenta en su espacio, igual que si lo subiera a mano.
 *
 * Flujo:
 *   1. urlAutorizacion() → Google pregunta "¿Permites que SULA Core use tu Drive?"
 *   2. Google regresa a /google/callback con un "code" → canjear() lo cambia por un
 *      refresh_token (llave de larga duración) que se guarda CIFRADO.
 *   3. tokenDeAcceso() usa ese refresh_token para pedir tokens de 1 hora cuando haga falta.
 */
final class GoogleOAuth
{
    private const AUTORIZAR = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const PERFIL = 'https://openidconnect.googleapis.com/v1/userinfo';

    private const REVOCAR = 'https://oauth2.googleapis.com/revoke';

    // drive: leer y escribir en lo que la persona ya tiene acceso. openid email: saber quién se conectó.
    public const PERMISOS = 'https://www.googleapis.com/auth/drive openid email';

    public static function configurado(): bool
    {
        return config('sula.google.client_id') && config('sula.google.client_secret');
    }

    /** A dónde mandar a la persona para que dé permiso. $estado evita que otro sitio "finja" la respuesta. */
    public static function urlAutorizacion(string $estado): string
    {
        return self::AUTORIZAR.'?'.http_build_query([
            'client_id' => config('sula.google.client_id'),
            'redirect_uri' => config('sula.google.redirect'),
            'response_type' => 'code',
            'scope' => self::PERMISOS,
            'access_type' => 'offline',   // para recibir refresh_token
            'prompt' => 'consent',        // fuerza a Google a darlo aunque ya se haya conectado antes
            'include_granted_scopes' => 'true',
            'state' => $estado,
        ]);
    }

    /** Cambia el "code" por el refresh_token y guarda la conexión (reemplaza la anterior). */
    public static function canjear(string $codigo, ?string $quien): ConexionGoogle
    {
        $r = Http::asForm()->timeout(20)->post(self::TOKEN, [
            'code' => $codigo,
            'client_id' => config('sula.google.client_id'),
            'client_secret' => config('sula.google.client_secret'),
            'redirect_uri' => config('sula.google.redirect'),
            'grant_type' => 'authorization_code',
        ]);
        if ($r->failed()) {
            throw new RuntimeException('Google no aceptó la conexión: '.($r->json('error_description') ?? $r->body()));
        }
        $refresh = $r->json('refresh_token');
        if (! $refresh) {
            throw new RuntimeException('Google no regresó la llave de larga duración. Quita el acceso de "SULA Core" en myaccount.google.com/permissions y vuelve a conectar.');
        }
        if (! str_contains((string) $r->json('scope'), 'auth/drive')) {
            throw new RuntimeException('No se dio permiso de Drive. Vuelve a conectar y marca la casilla de Google Drive.');
        }

        $email = Http::withToken($r->json('access_token'))->timeout(20)->get(self::PERFIL)->json('email') ?? 'desconocido';

        ConexionGoogle::query()->delete();   // solo una cuenta conectada a la vez
        Cache::forget('drive_token_oauth');

        return ConexionGoogle::create([
            'email' => $email,
            'refresh_token' => $refresh,
            'permisos' => (string) $r->json('scope'),
            'conectado_por' => $quien,
        ]);
    }

    /** Token de 1 hora de la cuenta conectada (se reutiliza 50 min). */
    public static function tokenDeAcceso(): string
    {
        return Cache::remember('drive_token_oauth', now()->addMinutes(50), function () {
            $c = ConexionGoogle::actual();
            if (! $c) {
                throw new RuntimeException('No hay cuenta de Google conectada. En Producción dale "Conectar Google".');
            }
            $r = Http::asForm()->timeout(20)->post(self::TOKEN, [
                'client_id' => config('sula.google.client_id'),
                'client_secret' => config('sula.google.client_secret'),
                'refresh_token' => $c->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
            if ($r->json('error') === 'invalid_grant') {
                throw new RuntimeException("La conexión con Google de {$c->email} venció o se quitó. Vuelve a darle \"Conectar Google\".");
            }
            if ($r->failed()) {
                throw new RuntimeException('Google rechazó la cuenta conectada: '.($r->json('error_description') ?? $r->body()));
            }

            return $r->json('access_token');
        });
    }

    /** Desconectar: le dice a Google que anule el permiso y lo borra de aquí. */
    public static function desconectar(): void
    {
        $c = ConexionGoogle::actual();
        if ($c) {
            Http::asForm()->timeout(10)->post(self::REVOCAR, ['token' => $c->refresh_token]);
        }
        ConexionGoogle::query()->delete();
        Cache::forget('drive_token_oauth');
    }
}
````

### `app/Http/Controllers/GoogleController.php`

**NUEVO.**

- `conectar()`: genera un **estado** aleatorio, lo guarda en la sesión y manda a Google.
- `callback()`: compara el estado con `hash_equals` (si otro sitio intenta "colarse" con una respuesta falsa, no coincide) y guarda la conexión.
- Regresa a Producción con un aviso (`with('mensaje')`), que el explorador muestra arriba.

````php
<?php

namespace App\Http\Controllers;

use App\Planos\Fuentes\GoogleOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Conectar Google":
 *   GET  /google/conectar     → manda a la pantalla de permiso de Google
 *   GET  /google/callback     → Google regresa aquí con el código; se guarda la conexión
 *   POST /google/desconectar  → quita el permiso
 */
class GoogleController
{
    public function conectar(Request $request)
    {
        if (! GoogleOAuth::configurado()) {
            return redirect()->route('produccion')->with('error', 'Faltan GOOGLE_OAUTH_CLIENT_ID y GOOGLE_OAUTH_CLIENT_SECRET en el .env.');
        }
        // "estado" aleatorio guardado en la sesión: al regresar se compara (protege contra CSRF)
        $estado = Str::random(40);
        $request->session()->put('google_estado', $estado);

        return redirect()->away(GoogleOAuth::urlAutorizacion($estado));
    }

    public function callback(Request $request)
    {
        $esperado = $request->session()->pull('google_estado');
        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state'))) {
            return redirect()->route('produccion')->with('error', 'La respuesta de Google no coincide. Intenta conectar otra vez.');
        }
        if ($request->query('error')) {
            return redirect()->route('produccion')->with('error', 'No se dio permiso en Google ('.$request->query('error').').');
        }

        try {
            $c = GoogleOAuth::canjear((string) $request->query('code'), config('sula.usuario'));
        } catch (Throwable $e) {
            return redirect()->route('produccion')->with('error', $e->getMessage());
        }

        return redirect()->route('produccion')->with('mensaje', "Google conectado como {$c->email}. Ya puedes crear carpetas y subir PDFs.");
    }

    public function desconectar()
    {
        GoogleOAuth::desconectar();

        return redirect()->route('produccion')->with('mensaje', 'Se desconectó la cuenta de Google.');
    }
}
````

### `app/Planos/Fuentes/Fuente.php`

**REEMPLAZA.**

Le pasa a `FuenteDrive` la opción `PLANOS_DRIVE_ESCRIBIR_CON`.

````php
<?php

namespace App\Planos\Fuentes;

use InvalidArgumentException;

/** Arma la fuente correcta según el .env (o según el origen de un plano ya guardado). */
final class Fuente
{
    /** La fuente configurada en PLANOS_FUENTE (drive o local). */
    public static function actual(): FuentePlanos
    {
        return self::para(config('sula.planos.fuente'));
    }

    public static function para(string $origen): FuentePlanos
    {
        return match (strtoupper($origen)) {
            'DRIVE' => new FuenteDrive(
                config('sula.planos.drive_credenciales'),
                (string) config('sula.planos.drive_carpeta'),
                (string) config('sula.planos.drive_escribir_con', 'cuenta_conectada'),
            ),
            'LOCAL' => new FuenteLocal(config('sula.planos.carpeta_local')),
            default => throw new InvalidArgumentException("Fuente de planos desconocida: $origen (usa drive o local)"),
        };
    }
}
````

### `app/Planos/AlmacenPdf.php`

**REEMPLAZA.**

- `guardarCopia()`: guarda los bytes del PDF en `storage/app/private/planos/` (fuera de git y fuera de `public`). Se usa al **subir** (la copia queda dentro del AS500 en ese momento) y al ver un plano de Drive por primera vez.
- Si el plano cambia en Drive (cambia su huella), se vuelve a bajar y se borra la copia vieja.

````php
<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\Fuente;

/**
 * Copia de los PDFs DENTRO del AS500 (storage/app/private/planos, fuera de git y fuera de "public").
 *   - Al ver o leer un plano de Drive, se baja la primera vez y se reutiliza.
 *   - Al subir un PDF desde el sistema, se guarda aquí en ese momento (además de en Drive).
 * Si el archivo cambia en Drive (cambia su "huella"), se vuelve a bajar.
 */
class AlmacenPdf
{
    public static function ruta(Plano $plano): string
    {
        $ruta = self::rutaLocal($plano);
        if (is_file($ruta)) {
            return $ruta;
        }

        $bytes = Fuente::para($plano->origen)->descargar($plano->archivo_id);
        self::guardarCopia($plano, $bytes);

        return $ruta;
    }

    /** Guarda los bytes del PDF como la copia local del plano (borra copias viejas del mismo plano). */
    public static function guardarCopia(Plano $plano, string $bytes): string
    {
        if (! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('Lo que llegó no es un PDF.');
        }
        $ruta = self::rutaLocal($plano);
        $dir = dirname($ruta);
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $prefijo = sha1($plano->archivo_id);
        foreach (glob("$dir/$prefijo-*.pdf") ?: [] as $vieja) {
            if ($vieja !== $ruta) {
                @unlink($vieja);
            }
        }
        file_put_contents($ruta, $bytes);

        return $ruta;
    }

    /** storage/app/private/planos/drive/<sha1 del id>-<huella>.pdf */
    private static function rutaLocal(Plano $plano): string
    {
        $dir = storage_path('app/private/planos/'.strtolower($plano->origen));

        return "$dir/".sha1($plano->archivo_id).'-'.substr(sha1((string) $plano->huella), 0, 12).'.pdf';
    }
}
````

### `app/Console/Commands/SincronizarPlanos.php`

**REEMPLAZA.**

Igual que antes; la tabla de resultados incluye las carpetas. Úsalo para la **primera** sincronización con Drive (en la terminal no hay límite de 30 s como en el navegador).

````php
<?php

namespace App\Console\Commands;

use App\Planos\Fuentes\Fuente;
use App\Planos\Sincronizador;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan sula:planos
 * Lee la carpeta de planos (Drive o local, según PLANOS_FUENTE) y pone al día el índice.
 */
class SincronizarPlanos extends Command
{
    protected $signature = 'sula:planos {--fuente= : drive o local (por defecto la del .env)}';

    protected $description = 'Sincroniza carpetas y planos con Google Drive o la carpeta local';

    public function handle(): int
    {
        try {
            $fuente = $this->option('fuente') ? Fuente::para($this->option('fuente')) : Fuente::actual();
            $this->info('Leyendo planos de '.$fuente->origen().'…');
            $r = Sincronizador::sincronizar($fuente);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Carpetas', 'PDFs encontrados', 'Nuevos', 'Actualizados', 'Retirados'],
            [[$r['carpetas'], $r['total'], $r['nuevos'], $r['actualizados'], $r['retirados']]]);

        return self::SUCCESS;
    }
}
````

## 6. PANTALLAS

### `resources/css/app.css`

**REEMPLAZA.**

- Los **mismos nombres de token** (`fondo`, `panel`, `borde`, `texto`, `tenue`, `sula`…) con colores claros. Por eso **todas** las pantallas cambian sin reescribirlas.
- Nuevos: `sula-suave` (fondo rosado de lo seleccionado), `info` (azul), `.tarjeta`, `.aviso-ok`.
- Botones, campos y chips con esquinas redondeadas y sombra ligera; etiquetas en letra normal (ya no en código y mayúsculas).
- Se agrega el peso 500 de IBM Plex Sans (viene en el mismo paquete `@fontsource/ibm-plex-sans`).
- `[x-cloak]`: oculta el menú de celular hasta que Alpine arranca (evita que "parpadee").

````css
/* Tipografía de IBM (guiño al AS/400). Viene de npm: funciona sin internet */
@import '@fontsource/ibm-plex-sans/400.css';
@import '@fontsource/ibm-plex-sans/500.css';
@import '@fontsource/ibm-plex-sans/600.css';
@import '@fontsource/ibm-plex-mono/400.css';
@import '@fontsource/ibm-plex-mono/600.css';

@import 'tailwindcss';

/* Dónde busca Tailwind las clases que usamos */
@source '../../vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php';
@source '../../storage/framework/views/*.php';
@source '../**/*.blade.php';
@source '../**/*.js';
@source '../../app/**/*.php';

/*
 * Tokens de diseño de SULA · tema CLARO tipo oficina.
 * Todas las pantallas usan estos nombres (bg-fondo, text-tenue, border-borde…),
 * así que al cambiar los colores aquí cambia TODO el sistema sin tocar las vistas.
 */
@theme {
    --font-sans: 'IBM Plex Sans', ui-sans-serif, system-ui, sans-serif;
    --font-mono: 'IBM Plex Mono', ui-monospace, monospace;

    --color-sula: #d7141a;          /* rojo SULA: botones principales y alertas */
    --color-sula-oscuro: #a80f14;
    --color-sula-suave: #fdecec;    /* fondo rosado para resaltar */
    --color-fondo: #f5f5f3;         /* fondo general (gris muy claro, cálido) */
    --color-panel: #ffffff;         /* tarjetas, tablas, menú */
    --color-panel-alto: #f0f0ed;    /* hover y cabeceras de tabla */
    --color-borde: #e2e2de;
    --color-texto: #1d1d1b;
    --color-tenue: #6b6b66;
    --color-ok: #15803d;
    --color-alerta: #b45309;
    --color-info: #1d4ed8;
}

html {
    color-scheme: light;
}

body {
    @apply bg-fondo font-sans text-texto antialiased;
}

/* Números alineados en columnas (clave en tablas de costos) */
.cifras {
    font-variant-numeric: tabular-nums;
}

/* ---- Piezas reutilizables ---- */
.etiqueta {
    @apply mb-1 block text-xs font-medium text-tenue;
}

.campo {
    @apply w-full rounded-md border border-borde bg-panel px-3 py-2 text-sm text-texto shadow-xs outline-none transition placeholder:text-tenue/60 focus:border-sula focus:ring-2 focus:ring-sula/15 disabled:bg-panel-alto disabled:opacity-60;
}

.boton {
    @apply inline-flex items-center justify-center gap-2 rounded-md border border-borde bg-panel px-4 py-2 text-sm font-medium text-texto shadow-xs transition hover:bg-panel-alto disabled:cursor-not-allowed disabled:opacity-50;
}

.boton-primario {
    @apply inline-flex items-center justify-center gap-2 rounded-md border border-sula bg-sula px-4 py-2 text-sm font-medium text-white shadow-xs transition hover:bg-sula-oscuro disabled:cursor-not-allowed disabled:opacity-50;
}

.chip {
    @apply inline-flex items-center gap-1 rounded-full border bg-panel px-2.5 py-0.5 text-[11px] font-medium whitespace-nowrap;
}

.tarjeta {
    @apply rounded-lg border border-borde bg-panel shadow-xs;
}

.kpi {
    @apply bg-panel px-5 py-4;
}

.tabla-cabecera {
    @apply bg-panel-alto text-xs font-medium text-tenue;
}

.aviso-error {
    @apply rounded-md border border-sula/30 bg-sula-suave px-4 py-3 text-sm text-sula-oscuro;
}

.aviso-alerta {
    @apply rounded-md border border-alerta/30 bg-alerta/10 px-4 py-3 text-sm;
}

.aviso-ok {
    @apply rounded-md border border-ok/30 bg-ok/10 px-4 py-3 text-sm text-ok;
}

/* Alpine: oculta elementos hasta que Alpine arranca (evita parpadeos) */
[x-cloak] {
    display: none !important;
}
````

### `resources/views/components/icono.blade.php`

**NUEVO.**

- Íconos dibujados con SVG aquí mismo (sin instalar nada). `stroke="currentColor"`: toman el color del texto, así que `text-sula` los pinta de rojo.
- Uso: `<x-icono nombre="carpeta" class="size-4" />`. Si no se da tamaño, usa `size-5`.

````blade
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
````

### `resources/views/components/encabezado.blade.php`

**NUEVO.**

- Componente con **slots** (huecos): `acciones` para botones y `ayuda` para los pasos.
- `<details>`: desplegable nativo del navegador, sin JavaScript, para "¿Cómo se usa esta pantalla?".

````blade
{{-- Encabezado de cada pantalla: dónde estás, qué es y qué puedes hacer.
     Uso:
     <x-encabezado area="Producción" titulo="Planos y despiece" descripcion="…">
         <x-slot:acciones> <button class="boton-primario">…</button> </x-slot:acciones>
         <x-slot:ayuda> <li>Paso 1…</li> <li>Paso 2…</li> </x-slot:ayuda>
     </x-encabezado> --}}
@props(['area' => null, 'titulo', 'descripcion' => null])

<header class="mb-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            @if ($area)
                <p class="text-xs font-medium tracking-wide text-sula uppercase">{{ $area }}</p>
            @endif
            <h1 class="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">{{ $titulo }}</h1>
            @if ($descripcion)
                <p class="mt-1.5 max-w-2xl text-sm text-tenue">{{ $descripcion }}</p>
            @endif
        </div>
        @isset($acciones)
            <div class="flex flex-wrap items-center gap-2">{{ $acciones }}</div>
        @endisset
    </div>

    {{-- "¿Cómo se usa?": plegable, para la gente que entra por primera vez --}}
    @isset($ayuda)
        <details class="group mt-4 max-w-3xl rounded-lg border border-borde bg-panel px-4 py-2.5 text-sm">
            <summary class="flex cursor-pointer list-none items-center gap-2 font-medium text-tenue select-none hover:text-texto">
                <x-icono nombre="ayuda" class="size-4" /> ¿Cómo se usa esta pantalla?
                <x-icono nombre="flecha" class="ml-auto size-4 transition group-open:rotate-90" />
            </summary>
            <ol class="mt-3 mb-1 list-decimal space-y-1.5 pl-5 text-tenue marker:text-sula">{{ $ayuda }}</ol>
        </details>
    @endisset
</header>
````

### `resources/views/layouts/app.blade.php`

**REEMPLAZA.**

- El menú es un arreglo `área → [ruta, nombre, ícono, para qué sirve]`: agregar una pantalla es agregar una línea.
- `x-data="{ menu: false }"` (Alpine, viene con Livewire): en celular el menú se desliza con ☰ y se cierra tocando afuera.
- En computadora el menú queda fijo (`md:sticky`) mientras haces scroll.

````blade
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}SULA Core</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
@php
    // Menú: área → [ruta, nombre, ícono, para qué sirve]
    // Nombres en palabras normales: que cualquiera en SULA sepa a dónde ir.
    $menu = [
        null => [
            ['inicio', 'Inicio', 'inicio', 'Resumen del sistema'],
        ],
        'Producción' => [
            ['produccion', 'Planos y despiece', 'carpeta', 'PDFs de Drive, carpetas y despiece con IA'],
        ],
        'Ventas' => [
            ['cotizaciones', 'Cotizaciones', 'cotizacion', 'Precio de un mueble a partir de su plano'],
        ],
        'Catálogo' => [
            ['materiales', 'Materiales', 'material', 'Materiales y sus precios'],
            ['parametros', 'Precios y márgenes', 'ajustes', 'Mano de obra, overhead y margen'],
        ],
        'Sistema' => [
            ['journal', 'Historial de cambios', 'historial', 'Quién cambió qué y cuándo'],
        ],
    ];
@endphp

{{-- x-data: estado del menú en celular (abierto/cerrado), lo maneja Alpine (viene con Livewire) --}}
<div class="flex min-h-screen" x-data="{ menu: false }">
    {{-- Fondo oscuro detrás del menú en celular --}}
    <div class="fixed inset-0 z-30 bg-black/30 md:hidden" x-show="menu" x-transition.opacity x-on:click="menu = false" x-cloak></div>

    {{-- Menú lateral --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-72 -translate-x-full flex-col border-r border-borde bg-panel transition-transform md:sticky md:top-0 md:h-screen md:translate-x-0"
           x-bind:class="menu && 'translate-x-0'">
        <div class="flex items-center gap-3 border-b border-borde px-5 py-4">
            <span class="grid size-9 place-items-center rounded-md bg-sula text-sm font-bold tracking-wider text-white">S</span>
            <div class="leading-tight">
                <p class="font-semibold tracking-[0.18em]">SULA <span class="font-normal tracking-normal text-tenue">Core</span></p>
                <p class="text-[11px] text-tenue">Calidad · Confianza · Innovación</p>
            </div>
            <button class="ml-auto rounded p-1 text-tenue hover:bg-panel-alto md:hidden" x-on:click="menu = false" aria-label="Cerrar menú">
                <x-icono nombre="cerrar" />
            </button>
        </div>

        <nav class="flex-1 space-y-5 overflow-y-auto px-3 py-4">
            @foreach ($menu as $area => $items)
                <div>
                    @if ($area)
                        <p class="mb-1.5 px-3 text-[11px] font-semibold tracking-wider text-tenue uppercase">{{ $area }}</p>
                    @endif
                    <ul class="space-y-0.5">
                        @foreach ($items as [$ruta, $nombre, $icono, $para])
                            @php $activo = request()->routeIs($ruta) || request()->routeIs($ruta.'.*'); @endphp
                            <li>
                                <a href="{{ route($ruta) }}" wire:navigate
                                   @class([
                                       'group flex items-start gap-3 rounded-md px-3 py-2 transition-colors',
                                       'bg-sula-suave text-sula-oscuro' => $activo,
                                       'text-texto hover:bg-panel-alto' => ! $activo,
                                   ])>
                                    <x-icono :nombre="$icono" :class="$activo ? 'mt-0.5 text-sula' : 'mt-0.5 text-tenue group-hover:text-texto'" />
                                    <span class="min-w-0">
                                        <span class="block text-sm font-medium">{{ $nombre }}</span>
                                        <span @class(['block text-xs', 'text-sula-oscuro/70' => $activo, 'text-tenue' => ! $activo])>{{ $para }}</span>
                                    </span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>

        <div class="flex items-center justify-between border-t border-borde px-5 py-3 text-xs text-tenue">
            <span>Usuario: <span class="font-medium text-texto">{{ config('sula.usuario') }}</span></span>
            <span class="font-mono">v{{ config('sula.version') }}</span>
        </div>
    </aside>

    {{-- Contenido --}}
    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Barra superior solo en celular --}}
        <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-borde bg-panel px-4 py-3 md:hidden">
            <button class="rounded p-1 hover:bg-panel-alto" x-on:click="menu = true" aria-label="Abrir menú">
                <x-icono nombre="menu" />
            </button>
            <span class="grid size-7 place-items-center rounded bg-sula text-xs font-bold text-white">S</span>
            <span class="font-semibold tracking-[0.18em]">SULA</span>
        </header>
        <main class="flex-1 px-4 py-6 md:px-8 lg:px-10 lg:py-8">
            {{ $slot }}
        </main>
    </div>
</div>
</body>
</html>
````

### `app/Livewire/Inicio.php`

**REEMPLAZA.**

Cuenta planos, cotizaciones en borrador, materiales y cambios de hoy. Si no hay base de datos no truena: lo dice.

````php
<?php

namespace App\Livewire;

use App\Models\Cotizacion;
use App\Models\Evento;
use App\Models\Material;
use App\Models\Plano;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Inicio: qué hay en el sistema y a dónde ir. Se refresca sola cada 30 s (wire:poll). */
#[Title('Inicio')]
class Inicio extends Component
{
    public function render()
    {
        try {
            DB::select('SELECT 1');
            $bd = true;
        } catch (\Throwable) {
            $bd = false;
        }

        return view('livewire.inicio', [
            'bd' => $bd,
            'planos' => $bd ? Plano::where('activo', true)->count() : 0,
            'borradores' => $bd ? Cotizacion::where('estado', 'BORRADOR')->count() : 0,
            'materiales' => $bd ? Material::where('activo', true)->count() : 0,
            'eventosHoy' => $bd ? Evento::whereDate('ocurrido_en', today())->count() : 0,
        ]);
    }
}
````

### `resources/views/livewire/inicio.blade.php`

**REEMPLAZA.**

Tarjetas "¿Qué quieres hacer?" (cada una lleva a su pantalla) y el camino Plano → Despiece → Cotización → Envío.

````blade
<div class="mx-auto max-w-6xl" wire:poll.30s>
    <x-encabezado titulo="Bienvenido a SULA Core"
        descripcion="El sistema de SULA para pasar del plano al precio: planos y despiece, cotizaciones y catálogo de materiales en un solo lugar." />

    @unless ($bd)
        <div class="aviso-error mb-6">No hay conexión con la base de datos. Revisa que Docker esté corriendo (<span class="font-mono">docker compose up -d</span>).</div>
    @endunless

    {{-- Las áreas del sistema, explicadas: cada tarjeta lleva a su pantalla --}}
    <p class="mb-3 text-sm font-medium text-tenue">¿Qué quieres hacer?</p>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ([
            ['produccion', 'carpeta', 'Producción', 'Planos y despiece', 'Busca un plano por su clave, súbelo o crea carpetas en Drive, y saca su despiece (subensambles y piezas) con la IA.', $planos, 'planos'],
            ['cotizaciones', 'cotizacion', 'Ventas', 'Cotizaciones', 'Calcula el precio de un mueble a partir de su despiece: materiales, mano de obra, overhead y margen. Al enviarla, los precios se congelan.', $borradores, 'en borrador'],
            ['materiales', 'material', 'Catálogo', 'Materiales', 'La lista de materiales que usa SULA (lámina, tubo, melamina, herrajes) con su precio vigente y su historial.', $materiales, 'activos'],
            ['journal', 'historial', 'Sistema', 'Historial de cambios', 'Todo lo que se cambia queda registrado: quién, qué y cuándo. Nada se puede borrar del historial.', $eventosHoy, 'cambios hoy'],
        ] as [$ruta, $icono, $area, $nombre, $texto, $numero, $unidad])
            <a href="{{ route($ruta) }}" wire:navigate
               class="tarjeta group flex gap-4 p-5 transition hover:border-sula/40 hover:shadow-md">
                <span class="grid size-11 shrink-0 place-items-center rounded-lg bg-sula-suave text-sula">
                    <x-icono :nombre="$icono" class="size-6" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="text-xs font-medium tracking-wide text-tenue uppercase">{{ $area }}</span>
                    <span class="mt-0.5 flex items-center gap-2 text-lg font-semibold group-hover:text-sula">
                        {{ $nombre }} <x-icono nombre="flecha" class="size-4 opacity-0 transition group-hover:opacity-100" />
                    </span>
                    <span class="mt-1 block text-sm text-tenue">{{ $texto }}</span>
                    <span class="cifras mt-3 block text-sm"><b class="text-xl">{{ number_format($numero) }}</b> <span class="text-tenue">{{ $unidad }}</span></span>
                </span>
            </a>
        @endforeach
    </div>

    {{-- El camino completo, para que se entienda cómo se conectan los módulos --}}
    <div class="tarjeta mt-6 p-5">
        <p class="text-sm font-medium">Cómo se conecta todo</p>
        <ol class="mt-3 grid gap-3 text-sm sm:grid-cols-4">
            @foreach ([
                ['1', 'Plano', 'Está en Drive o lo subes en Producción.'],
                ['2', 'Despiece', 'La IA lo propone; tú lo revisas y apruebas.'],
                ['3', 'Cotización', 'Con un clic, con los precios del catálogo.'],
                ['4', 'Envío', 'Se congela el precio y queda su historial.'],
            ] as [$n, $paso, $detalle])
                <li class="flex gap-3">
                    <span class="grid size-7 shrink-0 place-items-center rounded-full bg-sula text-xs font-semibold text-white">{{ $n }}</span>
                    <span><b>{{ $paso }}</b><span class="block text-tenue">{{ $detalle }}</span></span>
                </li>
            @endforeach
        </ol>
    </div>

    <p class="mt-6 text-xs text-tenue">
        Base de datos: <b @class(['text-ok' => $bd, 'text-sula' => ! $bd])>{{ $bd ? 'conectada' : 'sin conexión' }}</b>
        · Versión {{ config('sula.version') }} · Entorno {{ app()->environment() }}
    </p>
</div>
````

### `app/Livewire/Produccion/Explorador.php`

**NUEVO.**

- `#[Url] $carpeta` y `$buscar`: la carpeta abierta y la búsqueda quedan en la dirección (`/produccion?carpeta=12&q=0072`); se puede recargar o compartir.
- `WithFileUploads` + `$archivos`: Livewire sube los PDFs a un temporal; `updatedArchivos()` se ejecuta solo al terminar y valida (solo PDF, máx. 20, 60 MB c/u).
- `subir()`: por cada archivo limpia el nombre, revisa que empiece con `%PDF` (que de verdad sea PDF), lo sube a Drive, lo registra y borra el temporal. Si uno falla, los demás siguen; al final muestra ✓/✕ por archivo.
- `fuenteParaEscribir()`: solo se escribe en la fuente del `.env`, la carpeta abierta debe ser de esa fuente y, en Drive, debe haber cuenta conectada (`puedeEscribir`).
- `subir()` además llama a `AlmacenPdf::guardarCopia()`: el PDF queda **en Drive y dentro del AS500**.
- `mount()` toma los avisos que deja "Conectar Google" al regresar.
- Al buscar, se busca en **todas** las carpetas (y también se muestran las carpetas cuyo nombre coincide).

````php
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
````

### `resources/views/livewire/produccion/explorador.blade.php`

**NUEVO.**

- **Arrastrar y soltar**: Alpine escucha `dragover`/`drop` en toda la pantalla y llama a `$wire.uploadMultiple('archivos', …)` (la función de Livewire para subir varios).
- Ruta de migas arriba, buscador, filtros por estado del despiece, tarjetas de carpetas y tabla de PDFs.
- Antes de subir, muestra la lista con nombre y tamaño: se puede quitar alguno o cancelar.
- Barra **"Se sube a Drive como …"** con **Desconectar**, o el botón **Conectar Google** si no hay cuenta. Sin cuenta, "Subir PDFs" y "Nueva carpeta" se cambian por "Conectar Google para subir" (ver y despiece siguen funcionando).

````blade
@use('App\Support\Formato', 'F')

<div class="mx-auto max-w-7xl"
     {{-- Arrastrar y soltar PDFs en cualquier parte de la pantalla --}}
     x-data="{ arrastrando: false }"
     x-on:dragover.prevent="arrastrando = true"
     x-on:dragleave.self="arrastrando = false"
     x-on:drop.prevent="arrastrando = false; @if ($this->puedeEscribir) $wire.uploadMultiple('archivos', $event.dataTransfer.files) @endif">

    <x-encabezado area="Producción" titulo="Planos y despiece"
        descripcion="Todos los planos de la carpeta PLANOS de Drive. Busca por clave, abre un plano para verlo y sacar su despiece (subensambles y piezas) con la IA.">
        <x-slot:acciones>
            <button class="boton" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar"
                    title="Trae de {{ $fuente === 'DRIVE' ? 'Drive' : 'la carpeta' }} lo que se haya agregado o cambiado">
                <x-icono nombre="sincronizar" class="size-4" wire:loading.class="animate-spin" wire:target="sincronizar" />
                <span wire:loading.remove wire:target="sincronizar">Actualizar desde {{ $fuente === 'DRIVE' ? 'Drive' : 'carpeta' }}</span>
                <span wire:loading wire:target="sincronizar">Leyendo…</span>
            </button>
            @if ($this->puedeEscribir)
                <button class="boton" wire:click="$set('creandoCarpeta', true)">
                    <x-icono nombre="carpeta-nueva" class="size-4" /> Nueva carpeta
                </button>
                <label class="boton-primario cursor-pointer">
                    <x-icono nombre="subir" class="size-4" /> Subir PDFs
                    <input type="file" class="hidden" accept="application/pdf,.pdf" multiple wire:model="archivos">
                </label>
            @else
                <a class="boton-primario" href="{{ route('google.conectar') }}">
                    <x-icono nombre="drive" class="size-4" /> Conectar Google para subir
                </a>
            @endif
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>Entra a una carpeta con un clic. Arriba ves en qué carpeta estás; regresa con la ruta.</li>
            <li>Escribe una clave o parte del nombre en el buscador para buscar en <b>todas</b> las carpetas.</li>
            <li><b>Subir PDFs</b> o arrástralos a la pantalla: se guardan en la carpeta que tienes abierta, en Drive, y una copia dentro del AS500.</li>
            <li>Para subir y crear carpetas, la primera vez dale <b>Conectar Google</b> con una cuenta que sea Editor de PLANOS.</li>
            <li><b>Nueva carpeta</b> también se crea en Drive. Desde aquí no se borra nada.</li>
            <li>Abre un plano y dale <b>Leer con IA</b> para armar su despiece. Revísalo, corrige lo rojo y apruébalo.</li>
        </x-slot:ayuda>
    </x-encabezado>

    {{-- Avisos --}}
    @if ($mensaje)<div class="aviso-ok mb-4">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mb-4">{{ $error }}</div>@endif
    @error('archivos')<div class="aviso-error mb-4">{{ $message }}</div>@enderror
    @error('archivos.*')<div class="aviso-error mb-4">{{ $message }}</div>@enderror

    {{-- Resumen --}}
    @php($r = $this->resumen)
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Planos', number_format($r['planos']), 'text-texto'],
            ['Con despiece aprobado', number_format($r['aprobados']), 'text-ok'],
            ['Uso de IA este mes', number_format($r['tokens_mes']).' tokens', 'text-texto'],
            ['Última actualización', $r['ultima_sync'] ? \Illuminate\Support\Carbon::parse($r['ultima_sync'])->locale('es')->diffForHumans() : 'Nunca', 'text-texto'],
        ] as [$nombre, $valor, $color])
            <div class="tarjeta px-4 py-3">
                <p class="text-xs text-tenue">{{ $nombre }}</p>
                <p class="cifras mt-1 text-xl font-semibold {{ $color }}">{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    {{-- Cuenta de Google con la que se escribe en Drive --}}
    @if ($fuente === 'DRIVE' && config('sula.planos.drive_escribir_con') === 'cuenta_conectada')
        <div class="tarjeta mb-6 flex flex-wrap items-center gap-3 px-4 py-3 text-sm">
            <x-icono nombre="drive" class="size-5 text-tenue" />
            @if ($this->conexion)
                <span>Se sube a Drive como <b>{{ $this->conexion->email }}</b>
                    <span class="text-tenue">· conectado {{ $this->conexion->conectado_en->locale('es')->diffForHumans() }}</span></span>
                <form method="POST" action="{{ route('google.desconectar') }}" class="ml-auto"
                      onsubmit="return confirm('¿Desconectar la cuenta de Google? Ya no se podrá subir hasta volver a conectar.')">
                    @csrf
                    <button class="text-xs text-tenue underline hover:text-sula">Desconectar</button>
                </form>
            @else
                <span class="text-tenue">Para <b class="text-texto">crear carpetas y subir PDFs</b> conecta una cuenta de Google que sea Editor de la carpeta PLANOS. Ver planos y sacar despiece funciona sin esto.</span>
                <a class="boton ml-auto" href="{{ route('google.conectar') }}">Conectar Google</a>
            @endif
        </div>
    @endif

    {{-- Crear carpeta --}}
    @if ($creandoCarpeta)
        <form wire:submit="crearCarpeta" class="tarjeta mb-4 flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-64 flex-1">
                <label class="etiqueta" for="nombre-carpeta">
                    Nombre de la carpeta nueva, dentro de <b>{{ $this->actual?->ruta ?? 'la raíz' }}</b>
                </label>
                <input id="nombre-carpeta" class="campo" placeholder="Ej. VENTO, KFC 2026, Vitrinas" autofocus wire:model="nombreCarpeta">
                @error('nombreCarpeta')<p class="mt-1 text-xs text-sula">{{ $message }}</p>@enderror
            </div>
            <button type="button" class="boton" wire:click="$set('creandoCarpeta', false)">Cancelar</button>
            <button class="boton-primario" wire:loading.attr="disabled" wire:target="crearCarpeta">Crear en Drive</button>
        </form>
    @endif

    {{-- PDFs listos para subir --}}
    <div wire:loading.flex wire:target="archivos" class="tarjeta mb-4 items-center gap-3 p-4 text-sm text-tenue">
        <x-icono nombre="subir" class="size-4 animate-bounce" /> Cargando archivos…
    </div>
    @if ($archivos)
        <div class="tarjeta mb-4 p-4">
            <p class="text-sm font-medium">
                {{ count($archivos) }} PDF(s) listos para subir a <b>{{ $this->actual?->ruta ?? 'la raíz' }}</b>
            </p>
            <ul class="mt-3 divide-y divide-borde rounded-md border border-borde text-sm">
                @foreach ($archivos as $i => $a)
                    <li class="flex items-center gap-3 px-3 py-2" wire:key="sube-{{ $i }}">
                        <x-icono nombre="pdf" class="size-4 text-sula" />
                        <span class="min-w-0 flex-1 truncate">{{ $a->getClientOriginalName() }}</span>
                        <span class="cifras text-xs text-tenue">{{ number_format($a->getSize() / 1048576, 1) }} MB</span>
                        <button class="text-tenue hover:text-sula" wire:click="quitarArchivo({{ $i }})" title="Quitar"><x-icono nombre="cerrar" class="size-4" /></button>
                    </li>
                @endforeach
            </ul>
            <div class="mt-3 flex justify-end gap-2">
                <button class="boton" wire:click="$set('archivos', [])">Cancelar</button>
                <button class="boton-primario" wire:click="subir" wire:loading.attr="disabled" wire:target="subir">
                    <span wire:loading.remove wire:target="subir">Subir a {{ $fuente === 'DRIVE' ? 'Drive' : 'la carpeta' }}</span>
                    <span wire:loading wire:target="subir">Subiendo…</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Resultado de la última subida --}}
    @if ($resultados)
        <div class="tarjeta mb-4 p-4 text-sm">
            <div class="flex items-center justify-between">
                <p class="font-medium">Resultado de la subida</p>
                <button class="text-tenue hover:text-texto" wire:click="$set('resultados', [])"><x-icono nombre="cerrar" class="size-4" /></button>
            </div>
            <ul class="mt-2 space-y-1">
                @foreach ($resultados as $res)
                    <li class="flex gap-2">
                        <span @class(['font-medium', 'text-ok' => $res['ok'], 'text-sula' => ! $res['ok']])>{{ $res['ok'] ? '✓' : '✕' }}</span>
                        <span class="truncate">{{ $res['nombre'] }}</span>
                        <span class="text-tenue">— {{ $res['mensaje'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Barra: ruta + buscador + filtros --}}
    <div class="tarjeta relative overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 border-b border-borde px-4 py-3">
            {{-- Ruta de migas: Producción › VENTO › VITRINAS --}}
            <nav class="flex min-w-0 flex-1 flex-wrap items-center gap-1 text-sm">
                <button wire:click="abrir(null)" @class(['flex items-center gap-1.5 rounded px-1.5 py-0.5 hover:bg-panel-alto', 'font-semibold' => ! $carpeta])>
                    <x-icono nombre="drive" class="size-4 text-tenue" /> Todos los planos
                </button>
                @foreach ($camino as $paso)
                    <x-icono nombre="flecha" class="size-3.5 text-tenue" />
                    <button wire:click="abrir({{ $paso->id }})" @class(['rounded px-1.5 py-0.5 hover:bg-panel-alto', 'font-semibold' => $loop->last])>{{ $paso->nombre }}</button>
                @endforeach
            </nav>
            <div class="relative w-full sm:w-72">
                <x-icono nombre="buscar" class="pointer-events-none absolute top-2.5 left-3 size-4 text-tenue" />
                <input class="campo pl-9" placeholder="Buscar clave o nombre en todas las carpetas" wire:model.live.debounce.300ms="buscar">
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2 border-b border-borde bg-panel-alto/50 px-4 py-2">
            <span class="text-xs text-tenue">Despiece:</span>
            @foreach (['' => 'Todos', 'SIN' => 'Sin despiece', 'BORRADOR' => 'En revisión', 'APROBADO' => 'Aprobados'] as $valor => $nombre)
                <button wire:click="$set('filtro', '{{ $valor }}')"
                    @class(['chip', 'border-sula bg-sula-suave text-sula-oscuro' => $filtro === $valor, 'border-borde text-tenue hover:text-texto' => $filtro !== $valor])>
                    {{ $nombre }}
                </button>
            @endforeach
            @if (trim($buscar) !== '')
                <span class="ml-auto text-xs text-tenue">Buscando en todas las carpetas</span>
            @endif
        </div>

        {{-- Aviso al arrastrar --}}
        <div x-show="arrastrando" x-cloak
             class="absolute inset-0 z-10 grid place-items-center border-2 border-dashed border-sula bg-sula-suave/90 text-sula-oscuro">
            <div class="text-center">
                <x-icono nombre="subir" class="mx-auto size-8" />
                <p class="mt-2 font-medium">Suelta los PDFs para subirlos a {{ $this->actual?->ruta ?? 'la raíz' }}</p>
            </div>
        </div>

        {{-- Carpetas --}}
        @if ($this->subcarpetas->isNotEmpty())
            <div class="grid gap-2 border-b border-borde p-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($this->subcarpetas as $c)
                    <button wire:click="abrir({{ $c->id }})" wire:key="carpeta-{{ $c->id }}"
                            class="flex items-center gap-3 rounded-md border border-borde px-3 py-2.5 text-left transition hover:border-sula/40 hover:bg-sula-suave/40">
                        <x-icono nombre="carpeta" class="size-6 text-alerta" />
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-medium">{{ trim($buscar) !== '' ? $c->ruta : $c->nombre }}</span>
                            <span class="block text-xs text-tenue">
                                {{ $c->planos_count }} {{ $c->planos_count === 1 ? 'plano' : 'planos' }}
                                @if (($c->hijas_count ?? 0) > 0) · {{ $c->hijas_count }} carpetas @endif
                            </span>
                        </span>
                    </button>
                @endforeach
            </div>
        @endif

        {{-- Planos --}}
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="tabla-cabecera">
                    <tr>
                        <th class="px-4 py-2.5">Clave</th>
                        <th class="px-4 py-2.5">Archivo</th>
                        @if (trim($buscar) !== '')<th class="px-4 py-2.5">Carpeta</th>@endif
                        <th class="px-4 py-2.5">Rev.</th>
                        <th class="px-4 py-2.5">Despiece</th>
                        <th class="px-4 py-2.5">Modificado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-borde">
                    @forelse ($this->planos as $p)
                        <tr wire:key="plano-{{ $p->id }}" class="group hover:bg-panel-alto/60">
                            <td class="px-4 py-3 font-mono text-sm font-semibold whitespace-nowrap">
                                <a href="{{ route('produccion.plano', $p) }}" wire:navigate class="group-hover:text-sula">{{ $p->clave }}</a>
                            </td>
                            <td class="min-w-64 px-4 py-3">
                                <a href="{{ route('produccion.plano', $p) }}" wire:navigate class="flex items-center gap-2">
                                    <x-icono nombre="pdf" class="size-4 text-sula" />
                                    <span class="line-clamp-1">{{ $p->nombre }}</span>
                                    @if ($p->subido_en_sistema)<span class="chip border-info/30 text-info">subido aquí</span>@endif
                                </a>
                            </td>
                            @if (trim($buscar) !== '')<td class="px-4 py-3 text-xs text-tenue">{{ $p->carpeta ?? 'Raíz' }}</td>@endif
                            <td class="px-4 py-3 font-mono text-xs">{{ $p->revision }}</td>
                            <td class="px-4 py-3"><x-chip-despiece :despiece="$p->aprobado ?? $p->ultimo" /></td>
                            <td class="px-4 py-3 text-xs whitespace-nowrap text-tenue">
                                {{ $p->modificado_en_origen ? F::fecha($p->modificado_en_origen) : '—' }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-14 text-center text-sm text-tenue">
                                @if (trim($buscar) !== '')
                                    Ningún plano coincide con «{{ $buscar }}».
                                @elseif ($r['planos'] === 0)
                                    Todavía no hay planos. Dale a <b>Actualizar desde {{ $fuente === 'DRIVE' ? 'Drive' : 'carpeta' }}</b> o sube tus PDFs.
                                @else
                                    Esta carpeta no tiene PDFs. Arrastra aquí los que quieras subir.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($this->planos->count() === 300)
        <p class="mt-2 text-xs text-tenue">Se muestran los primeros 300: escribe más de la clave para afinar.</p>
    @endif
</div>
````

### `app/Livewire/Produccion/Plano.php`

**NUEVO.**

Es **el mismo componente `Ver` del Módulo 3** con otro nombre y lugar (`App\Livewire\Produccion\Plano`). La lógica no cambia. El modelo se importa como `PlanoModelo` porque la clase del componente también se llama `Plano`.

````php
<?php

namespace App\Livewire\Produccion;

use App\Costeo\Dec;
use App\Models\Cliente;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\Plano as PlanoModelo;
use App\Planos\ErrorDespiece;
use App\Planos\FlujoDespiece;
use App\Planos\Normalizador;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Un plano: visor del PDF + su despiece (subensambles → piezas).
 * Leer con IA · capturar a mano · editar · aprobar · cotizar.
 */
class Plano extends Component
{
    public PlanoModelo $plano;

    // Versión del despiece que se está viendo (?v=2 en la dirección)
    #[Url(as: 'v')]
    public ?int $version = null;

    // Copia editable del despiece: [['nombre','cantidad','pagina','notas','piezas'=>[…]]]
    public array $subs = [];

    public bool $conDibujos = false;

    public bool $verPdf = true;

    public ?string $error = null;

    public ?string $mensaje = null;

    // Mandar a cotizar
    public bool $cotizando = false;

    public ?int $clienteId = null;

    public int $cantidad = 1;

    public function mount(PlanoModelo $plano): void
    {
        $this->plano = $plano;
        $this->cargar();
    }

    // ---------------- Datos ----------------

    /** El despiece que se está viendo: la versión pedida, o el aprobado, o el último. */
    #[Computed]
    public function despiece(): ?Despiece
    {
        $q = $this->plano->despieces();
        $d = $this->version ? (clone $q)->where('version', $this->version)->first() : null;

        return $d ?? (clone $q)->where('estado', 'APROBADO')->first() ?? $q->first();
    }

    #[Computed]
    public function versiones()
    {
        return $this->plano->despieces()->get(['id', 'version', 'estado', 'origen', 'creado_en']);
    }

    #[Computed]
    public function materiales()
    {
        return Material::where('activo', true)->orderBy('clave')
            ->get(['id', 'clave', 'descripcion', 'tipo_calculo', 'categoria', 'unidad'])->keyBy('id');
    }

    /** Totales por material para UN mueble (la "explosión"). Solo piezas con material. */
    #[Computed]
    public function explosion(): array
    {
        $porMaterial = [];
        foreach ($this->subs as $s) {
            $veces = Dec::de(Normalizador::numero((string) $s['cantidad']) ?? '1');
            foreach ($s['piezas'] as $p) {
                $m = $this->materiales->get((int) ($p['material_id'] ?? 0));
                if (! $m) {
                    continue;
                }
                $cant = Dec::de(Normalizador::numero((string) $p['cantidad']) ?? '0')->multipliedBy($veces);
                $largo = Dec::de(Normalizador::numero((string) $p['largo_mm']) ?? '0');
                $ancho = Dec::de(Normalizador::numero((string) $p['ancho_mm']) ?? '0');
                $f = $porMaterial[$m->id] ??= ['material' => $m, 'piezas' => Dec::de(0), 'medida' => Dec::de(0)];
                $f['piezas'] = $f['piezas']->plus($cant);
                $medida = match ($m->tipo_calculo) {
                    'LINEAL' => Dec::div($largo, Dec::de(1000))->multipliedBy($cant),                          // m
                    'AREA', 'AREA_KG' => Dec::div($largo->multipliedBy($ancho), Dec::de(1000000))->multipliedBy($cant), // m²
                    default => Dec::de(0),
                };
                $f['medida'] = $f['medida']->plus($medida);
                $porMaterial[$m->id] = $f;
            }
        }
        ksort($porMaterial);

        return array_map(fn ($f) => [
            'material' => $f['material'],
            'piezas' => Dec::d4($f['piezas']),
            'medida' => Dec::d4($f['medida']),
            'unidad' => match ($f['material']->tipo_calculo) { 'LINEAL' => 'm', 'AREA', 'AREA_KG' => 'm²', default => '' },
        ], array_values($porMaterial));
    }

    /** Copia el despiece de la base de datos al arreglo editable. */
    private function cargar(): void
    {
        unset($this->despiece, $this->versiones, $this->explosion);
        $d = $this->despiece;
        $this->version = $d?->version;
        $this->subs = [];
        if (! $d) {
            return;
        }

        foreach ($d->subensambles()->with('piezas')->get() as $s) {
            $this->subs[] = [
                'nombre' => $s->nombre,
                'cantidad' => Dec::d4(Dec::de($s->cantidad)),
                'pagina' => $s->pagina,
                'notas' => (string) $s->notas,
                'piezas' => $s->piezas->map(fn ($p) => [
                    'descripcion' => $p->descripcion,
                    'material_texto' => (string) $p->material_texto,
                    'material_id' => $p->material_id,
                    'cantidad' => Dec::d4(Dec::de($p->cantidad)),
                    'largo_mm' => Dec::d4(Dec::de($p->largo_mm)),
                    'ancho_mm' => Dec::d4(Dec::de($p->ancho_mm)),
                    // Una sola columna "pintura": perímetro (tubo) o caras (lámina)
                    'pintura' => Dec::d4(Dec::de((float) $p->perimetro_pintable_mm > 0 ? $p->perimetro_pintable_mm : $p->caras_pintables)),
                    'pagina' => $p->pagina,
                    'notas' => (string) $p->notas,
                    'avisos' => $p->avisos ?? [],
                ])->all(),
            ];
        }
    }

    // ---------------- Acciones ----------------

    /** Ejecuta una acción y muestra el error de negocio si lo hay. */
    private function intentar(callable $accion, ?string $ok = null): void
    {
        $this->error = $this->mensaje = null;
        try {
            $accion();
            $this->mensaje = $ok;
        } catch (ErrorDespiece $e) {
            $this->error = $e->getMessage();
        }
        $this->cargar();
    }

    public function verVersion(int $version): void
    {
        $this->version = $version;
        $this->error = $this->mensaje = null;
        $this->cargar();
    }

    public function leerConIa(): void
    {
        $this->intentar(function () {
            $d = FlujoDespiece::leerConIa($this->plano, $this->conDibujos);
            $this->version = $d->version;
        });
    }

    /** Lo llama wire:poll mientras la IA trabaja. */
    public function refrescar(): void
    {
        $d = $this->despiece;
        $antes = $d?->estado;
        $d?->refresh();
        if ($antes !== $d?->estado) {
            $this->cargar();
        }
    }

    /** Si la cola se cayó, permite soltar una lectura atorada (más de 20 min). */
    public function cancelarLectura(): void
    {
        $d = $this->despiece;
        if ($d && $d->estado === 'LEYENDO' && $d->actualizado_en->lt(now()->subMinutes(20))) {
            $d->update(['estado' => 'ERROR', 'progreso' => null, 'error' => 'Cancelada a mano (¿estaba corriendo php artisan queue:work?).']);
        }
        $this->cargar();
    }

    public function capturarManual(): void
    {
        $this->intentar(function () {
            $this->version = FlujoDespiece::nuevoManual($this->plano)->version;
        });
    }

    public function nuevaVersion(): void
    {
        $this->intentar(function () {
            $this->version = FlujoDespiece::copiar($this->despiece)->version;
        }, 'Se creó una versión nueva en BORRADOR a partir de la anterior.');
    }

    public function guardar(): void
    {
        $this->intentar(fn () => FlujoDespiece::guardar($this->despiece, $this->subs), 'Guardado. Los avisos se recalcularon.');
    }

    public function aprobar(): void
    {
        $this->intentar(function () {
            FlujoDespiece::guardar($this->despiece, $this->subs);
            FlujoDespiece::aprobar($this->despiece->refresh());
        }, 'Despiece aprobado. Los materiales que elegiste a mano quedaron como alias en el catálogo.');
    }

    public function cotizar()
    {
        $this->error = null;
        try {
            $c = FlujoDespiece::cotizar($this->despiece, $this->clienteId, max(1, $this->cantidad));
        } catch (ErrorDespiece $e) {
            $this->error = $e->getMessage();

            return null;
        }

        return $this->redirectRoute('cotizaciones.editor', $c, navigate: true);
    }

    // ---------------- Edición del arreglo (no guarda hasta "Guardar") ----------------

    public function agregarSubensamble(): void
    {
        $this->subs[] = ['nombre' => '', 'cantidad' => '1', 'pagina' => null, 'notas' => '', 'piezas' => [$this->piezaVacia()]];
    }

    public function quitarSubensamble(int $i): void
    {
        unset($this->subs[$i]);
        $this->subs = array_values($this->subs);
    }

    public function agregarPieza(int $i): void
    {
        $this->subs[$i]['piezas'][] = $this->piezaVacia();
    }

    public function quitarPieza(int $i, int $j): void
    {
        unset($this->subs[$i]['piezas'][$j]);
        $this->subs[$i]['piezas'] = array_values($this->subs[$i]['piezas']);
    }

    private function piezaVacia(): array
    {
        return ['descripcion' => '', 'material_texto' => '', 'material_id' => null, 'cantidad' => '1', 'largo_mm' => '0',
            'ancho_mm' => '0', 'pintura' => '0', 'pagina' => null, 'notas' => '', 'avisos' => []];
    }

    public function render()
    {
        $d = $this->despiece;
        $piezas = collect($this->subs)->flatMap(fn ($s) => $s['piezas']);

        return view('livewire.produccion.plano', [
            'd' => $d,
            'editable' => $d?->esEditable() ?? false,
            'mats' => $this->materiales,
            'clientes' => $this->cotizando ? Cliente::where('activo', true)->orderBy('nombre')->get() : collect(),
            'conteo' => [
                'subensambles' => count($this->subs),
                'piezas' => $piezas->count(),
                'errores' => $piezas->filter(fn ($p) => collect($p['avisos'])->contains('nivel', 'error'))->count(),
            ],
        ])->title($this->plano->clave.' · Plano');
    }
}
````

### `resources/views/livewire/produccion/plano.blade.php`

**NUEVO.**

La vista del Módulo 3 con el diseño nuevo: ruta de carpetas arriba, encabezado con "¿Cómo se usa?" y bloques en tarjetas. El visor del PDF, la lectura con IA, el aprobado y el cotizar funcionan igual.

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-[100rem]">
    {{-- Ruta de regreso: Planos › VENTO › VITRINAS › clave --}}
    <nav class="mb-3 flex flex-wrap items-center gap-1 text-sm text-tenue">
        <a href="{{ route('produccion') }}" wire:navigate class="hover:text-texto">Planos</a>
        @foreach ($plano->carpetaPadre?->camino() ?? [] as $paso)
            <x-icono nombre="flecha" class="size-3.5" />
            <a href="{{ route('produccion', ['carpeta' => $paso->id]) }}" wire:navigate class="hover:text-texto">{{ $paso->nombre }}</a>
        @endforeach
        <x-icono nombre="flecha" class="size-3.5" />
        <span class="font-medium text-texto">{{ $plano->clave }}</span>
    </nav>

    <x-encabezado area="Producción · plano" :titulo="$plano->clave"
        :descripcion="$plano->nombre.' · '.$plano->revision.($plano->activo ? '' : ' · ya no está en '.$plano->origen)">
        <x-slot:acciones>
            <button class="boton" wire:click="$toggle('verPdf')">
                <x-icono nombre="ojo" class="size-4" /> {{ $verPdf ? 'Ocultar plano' : 'Ver plano' }}
            </button>
            <a class="boton" href="{{ route('produccion.pdf', $plano) }}?descargar=1">
                <x-icono nombre="descargar" class="size-4" /> Descargar PDF
            </a>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li><b>Leer con IA</b>: la IA lee el plano y propone subensambles y piezas. Tarda de segundos a unos minutos.</li>
            <li>Revisa lo que salga <span class="text-sula">en rojo</span>: falta un dato o el material no está en el catálogo. Corrígelo y dale <b>Guardar</b>.</li>
            <li><b>Aprobar</b> deja el despiece fijo. Los materiales que elegiste a mano se aprenden para los siguientes planos.</li>
            <li>Con el despiece aprobado, <b>Cotizar este plano</b> crea la cotización con las piezas ya multiplicadas.</li>
        </x-slot:ayuda>
    </x-encabezado>

    @if ($mensaje)<div class="aviso-ok mb-4">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mb-4">{{ $error }}</div>@endif

    <div @class(['grid gap-6', 'xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]' => $verPdf])>
        {{-- Visor del PDF: el navegador trae su propio visor (zoom, páginas, imprimir) --}}
        @if ($verPdf)
            <div class="tarjeta h-[70vh] overflow-hidden xl:sticky xl:top-6 xl:h-[85vh]" wire:ignore>
                <iframe src="{{ route('produccion.pdf', $plano) }}" class="h-full w-full" title="Plano {{ $plano->clave }}"></iframe>
            </div>
        @endif

        {{-- Despiece --}}
        <div class="min-w-0 space-y-4">
            {{-- Versiones --}}
            <div class="flex flex-wrap items-center gap-2">
                <p class="mr-2 text-sm font-medium">Versiones del despiece:</p>
                @foreach ($this->versiones as $v)
                    <button wire:click="verVersion({{ $v->version }})"
                        @class(['chip py-1', 'border-sula text-texto' => $d?->id === $v->id, 'border-borde text-tenue hover:text-texto' => $d?->id !== $v->id])>
                        v{{ $v->version }} · {{ $v->estado }}
                    </button>
                @endforeach
            </div>

            @if (! $d)
                {{-- Sin despiece todavía --}}
                <div class="tarjeta p-6">
                    <h2 class="text-lg font-semibold">Este plano todavía no tiene despiece</h2>
                    <p class="mt-2 text-sm text-tenue">
                        La IA lee el texto del plano (tablas, materiales, cotas) y propone subensambles y piezas.
                        Tú lo revisas, corriges lo que esté en rojo y lo apruebas.
                    </p>
                    <label class="mt-4 flex items-start gap-2 text-sm">
                        <input type="checkbox" class="mt-1 accent-sula" wire:model="conDibujos">
                        <span>Revisar también los dibujos <span class="text-tenue">(más lento: manda imágenes de las páginas para completar medidas que solo están dibujadas)</span></span>
                    </label>
                    <div class="mt-5 flex flex-wrap gap-2">
                        <button class="boton-primario" wire:click="leerConIa"><x-icono nombre="ia" class="size-4" /> Leer con IA</button>
                        <button class="boton" wire:click="capturarManual">Capturar a mano</button>
                    </div>
                </div>
            @elseif ($d->estado === 'LEYENDO')
                {{-- La IA está trabajando: se pregunta el avance cada 3 s --}}
                <div class="rounded-lg border border-alerta/40 bg-alerta/5 p-6" wire:poll.3s="refrescar">
                    <p class="flex items-center gap-2 text-sm font-medium text-alerta"><x-icono nombre="ia" class="size-4" /> Leyendo con IA · versión {{ $d->version }}</p>
                    <p class="mt-2 animate-pulse text-lg">{{ $d->progreso ?? 'Trabajando…' }}</p>
                    <p class="mt-3 text-sm text-tenue">
                        Puedes cambiar de pantalla; sigue en segundo plano.
                        Si se queda en "En la fila…", revisa que esté corriendo <span class="font-mono">php artisan queue:work</span>.
                    </p>
                    @if ($d->actualizado_en->lt(now()->subMinutes(20)))
                        <button class="boton mt-4" wire:click="cancelarLectura">Cancelar lectura atorada</button>
                    @endif
                </div>
            @else
                @if ($d->estado === 'ERROR')
                    <div class="aviso-error">
                        <p class="font-semibold">La IA no pudo leer el plano</p>
                        <p class="mt-1">{{ $d->error }}</p>
                    </div>
                @endif

                {{-- Datos que la IA leyó del cuadro del plano --}}
                @php($dp = $d->datos['plano'] ?? [])
                @if (array_filter($dp) || ! empty($d->datos['leyenda']))
                    <div class="grid gap-px overflow-hidden rounded-lg border border-borde bg-borde text-sm sm:grid-cols-2">
                        <div class="bg-panel px-4 py-3">
                            @foreach (['descripcion' => 'Mueble', 'modelo' => 'Modelo', 'cliente' => 'Cliente', 'acabado' => 'Acabado'] as $k => $n)
                                @if (! empty($dp[$k]))<p><span class="etiqueta inline">{{ $n }}</span> {{ $dp[$k] }}</p>@endif
                            @endforeach
                        </div>
                        <div class="bg-panel px-4 py-3 font-mono text-xs">
                            <p class="etiqueta">Leyenda de materiales</p>
                            @forelse ($d->datos['leyenda'] ?? [] as $codigo => $texto)
                                <p><span class="text-tenue">{{ $codigo }}</span> {{ $texto }}</p>
                            @empty
                                <p class="text-tenue">—</p>
                            @endforelse
                        </div>
                    </div>
                @endif

                {{-- Barra de acciones --}}
                <div class="flex flex-wrap items-center gap-2">
                    <x-chip-despiece :despiece="$d" />
                    <span class="font-mono text-xs text-tenue">
                        {{ $conteo['subensambles'] }} subensambles · {{ $conteo['piezas'] }} piezas
                        @if ($conteo['errores']) · <span class="text-sula">{{ $conteo['errores'] }} en rojo</span> @endif
                        · {{ $d->origen }}{{ $d->con_dibujos ? ' + dibujos' : '' }}
                        @if ($d->aprobado_en) · aprobado {{ F::fecha($d->aprobado_en) }} por {{ $d->aprobado_por }} @endif
                    </span>
                    <div class="ml-auto flex flex-wrap gap-2">
                        @if ($editable)
                            <button class="boton" wire:click="guardar">
                                <span wire:loading.remove wire:target="guardar">Guardar</span>
                                <span wire:loading wire:target="guardar">Guardando…</span>
                            </button>
                            <button class="boton-primario" wire:click="aprobar" wire:confirm="¿Aprobar este despiece? Ya no se podrá editar (sí se puede sacar una versión nueva).">Aprobar</button>
                        @elseif ($d->estado === 'APROBADO')
                            <button class="boton" wire:click="nuevaVersion">Nueva versión</button>
                            <button class="boton-primario" wire:click="$set('cotizando', true)">Cotizar este plano</button>
                        @elseif ($d->estado === 'REEMPLAZADO')
                            <button class="boton" wire:click="nuevaVersion">Copiar a versión nueva</button>
                        @endif
                        @if ($d->estado !== 'LEYENDO')
                            <button class="boton" wire:click="leerConIa" wire:confirm="Se creará una versión nueva leyendo el plano otra vez con la IA. ¿Seguir?">Volver a leer con IA</button>
                        @endif
                    </div>
                </div>
                @if ($d->estado !== 'LEYENDO')
                    <label class="flex items-center gap-2 text-xs text-tenue">
                        <input type="checkbox" class="accent-sula" wire:model="conDibujos"> Al volver a leer, revisar también los dibujos
                    </label>
                @endif

                @include('livewire.produccion._despiece')

                {{-- Explosión por mueble --}}
                @if ($this->explosion)
                    <div>
                        <p class="etiqueta">Explosión: total por mueble</p>
                        <div class="tarjeta overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="tabla-cabecera">
                                    <tr>
                                        <th class="px-4 py-2 text-left">Material</th>
                                        <th class="px-4 py-2 text-right">Piezas</th>
                                        <th class="px-4 py-2 text-right">Medida</th>
                                    </tr>
                                </thead>
                                <tbody class="cifras divide-y divide-borde font-mono text-xs">
                                    @foreach ($this->explosion as $f)
                                        <tr>
                                            <td class="px-4 py-2"><span class="font-semibold">{{ $f['material']->clave }}</span> <span class="font-sans text-tenue">{{ $f['material']->descripcion }}</span></td>
                                            <td class="px-4 py-2 text-right">{{ F::num($f['piezas']) }}</td>
                                            <td class="px-4 py-2 text-right">{{ $f['unidad'] ? F::num($f['medida']).' '.$f['unidad'] : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="mt-1 font-mono text-[11px] text-tenue">Medida neta (sin merma ni plan de corte): eso se decide en la cotización.</p>
                    </div>
                @endif
            @endif
        </div>
    </div>
</section>

{{-- Ventana: cotizar --}}
@if ($cotizando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('cotizando', false)"></button>
    <div class="fixed inset-x-4 top-24 z-40 mx-auto max-w-md rounded-lg border border-borde bg-panel p-6 shadow-2xl">
        <p class="text-xs font-medium text-sula uppercase">Cotizar · {{ $plano->clave }}</p>
        <h2 class="mt-1 text-lg font-semibold">Nueva cotización con este despiece</h2>
        <p class="mt-2 text-sm text-tenue">Se crea en BORRADOR con las piezas ya multiplicadas por subensamble. Ahí ajustas merma, plan de corte y parámetros.</p>
        <div class="mt-4 space-y-3">
            <div>
                <label class="etiqueta" for="cot-cliente">Cliente</label>
                <select id="cot-cliente" class="campo" wire:model="clienteId">
                    <option value="">Sin cliente (parámetros de SULA)</option>
                    @foreach ($clientes as $cl)
                        <option value="{{ $cl->id }}">{{ $cl->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="etiqueta" for="cot-cantidad">Cantidad de muebles</label>
                <input id="cot-cantidad" type="number" min="1" class="campo w-32 font-mono" wire:model="cantidad">
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button class="boton" wire:click="$set('cotizando', false)">Cancelar</button>
            <button class="boton-primario" wire:click="cotizar">Crear cotización</button>
        </div>
    </div>
@endif
</div>
````

### `resources/views/livewire/produccion/_despiece.blade.php`

**NUEVO.**

Igual que el del Módulo 3; cada subensamble va en una tarjeta.

````blade
@use('App\Support\Formato', 'F')
{{-- Árbol del despiece: subensamble (× cantidad por mueble) → piezas (cantidad por subensamble).
     Variables: $subs, $mats (catálogo), $editable --}}
<div class="space-y-4">
    @foreach ($subs as $i => $s)
        <div wire:key="sub-{{ $i }}" class="tarjeta overflow-hidden">
            {{-- Encabezado del subensamble --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-borde bg-panel px-3 py-2">
                @if ($editable)
                    <input class="campo w-64 px-2 py-1 font-mono text-sm font-semibold uppercase" placeholder="NOMBRE DEL SUBENSAMBLE"
                           wire:model="subs.{{ $i }}.nombre">
                    <span class="font-mono text-sm text-tenue">×</span>
                    <input class="campo w-20 px-2 py-1 text-right font-mono text-sm" inputmode="decimal" title="Cuántos lleva un mueble"
                           wire:model="subs.{{ $i }}.cantidad">
                    <span class="text-xs text-tenue">por mueble</span>
                    <button class="ml-auto px-2 text-tenue hover:text-sula" title="Quitar subensamble"
                            wire:click="quitarSubensamble({{ $i }})" wire:confirm="¿Quitar el subensamble {{ $s['nombre'] }} con todas sus piezas?">✕</button>
                @else
                    <span class="font-mono text-sm font-semibold">{{ $s['nombre'] }}</span>
                    <span class="font-mono text-sm text-tenue">× {{ F::num($s['cantidad']) }} por mueble</span>
                @endif
                @if ($s['pagina'])<span class="font-mono text-[11px] text-tenue">pág. {{ $s['pagina'] }}</span>@endif
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="tabla-cabecera">
                        <tr>
                            <th class="px-3 py-2">Pieza</th>
                            <th class="px-2 py-2">Material</th>
                            <th class="px-2 py-2 text-right" title="Por subensamble">Cant.</th>
                            <th class="px-2 py-2 text-right">Largo mm</th>
                            <th class="px-2 py-2 text-right">Ancho mm</th>
                            <th class="px-2 py-2 text-right" title="Perímetro pintable mm (tubo) o caras (lámina)">Pintura</th>
                            <th class="px-2 py-2 text-right">Pág.</th>
                            @if ($editable)<th class="w-8"></th>@endif
                        </tr>
                    </thead>
                    <tbody class="cifras divide-y divide-borde">
                        @foreach ($s['piezas'] as $j => $p)
                            @php
                                $errores = collect($p['avisos'])->where('nivel', 'error');
                                $info = collect($p['avisos'])->where('nivel', 'info');
                                $m = $mats->get((int) $p['material_id']);
                            @endphp
                            <tr wire:key="pz-{{ $i }}-{{ $j }}" @class(['align-top', 'bg-sula/5' => $errores->isNotEmpty()])>
                                @if ($editable)
                                    <td class="px-2 py-1">
                                        <input class="campo min-w-48 px-2 py-1 text-xs" wire:model="subs.{{ $i }}.piezas.{{ $j }}.descripcion">
                                    </td>
                                    <td class="px-1 py-1">
                                        <select @class(['campo min-w-52 px-2 py-1 text-xs', 'border-sula' => ! $m]) wire:model="subs.{{ $i }}.piezas.{{ $j }}.material_id">
                                            <option value="">— elegir material —</option>
                                            @foreach ($mats as $op)
                                                <option value="{{ $op->id }}">{{ $op->clave }} · {{ $op->descripcion }}</option>
                                            @endforeach
                                        </select>
                                        @if ($p['material_texto'])
                                            <p class="mt-0.5 truncate font-mono text-[10px] text-tenue" title="Como viene en el plano">plano: {{ $p['material_texto'] }}</p>
                                        @endif
                                    </td>
                                    @foreach (['cantidad' => 'w-16', 'largo_mm' => 'w-20', 'ancho_mm' => 'w-20', 'pintura' => 'w-20', 'pagina' => 'w-14'] as $campo => $ancho)
                                        <td class="px-1 py-1">
                                            <input class="campo {{ $ancho }} px-2 py-1 text-right font-mono text-xs" inputmode="decimal"
                                                   wire:model="subs.{{ $i }}.piezas.{{ $j }}.{{ $campo }}">
                                        </td>
                                    @endforeach
                                    <td class="px-1 py-1">
                                        <button class="px-1 text-tenue hover:text-sula" title="Quitar pieza" wire:click="quitarPieza({{ $i }}, {{ $j }})">✕</button>
                                    </td>
                                @else
                                    <td class="px-3 py-2">{{ $p['descripcion'] }}</td>
                                    <td class="px-2 py-2 text-xs">
                                        @if ($m)<span class="font-mono font-semibold">{{ $m->clave }}</span> <span class="text-tenue">{{ $m->descripcion }}</span>@else<span class="text-sula">sin material</span>@endif
                                    </td>
                                    <td class="px-2 py-2 text-right font-mono">{{ F::num($p['cantidad']) }}</td>
                                    <td class="px-2 py-2 text-right font-mono">{{ (float) $p['largo_mm'] ? F::num($p['largo_mm']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono">{{ (float) $p['ancho_mm'] ? F::num($p['ancho_mm']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono text-tenue">{{ (float) $p['pintura'] ? F::num($p['pintura']) : '—' }}</td>
                                    <td class="px-2 py-2 text-right font-mono text-tenue">{{ $p['pagina'] ?? '—' }}</td>
                                @endif
                            </tr>
                            @if ($p['avisos'] || ($p['notas'] && ! $editable))
                                <tr wire:key="av-{{ $i }}-{{ $j }}" @class(['bg-sula/5' => $errores->isNotEmpty()])>
                                    <td colspan="{{ $editable ? 8 : 7 }}" class="px-3 pb-2 text-[11px]">
                                        @foreach ($errores as $a)<span class="mr-3 text-sula">⚠ {{ $a['texto'] }}</span>@endforeach
                                        @foreach ($info as $a)<span class="mr-3 text-tenue">ℹ {{ $a['texto'] }}</span>@endforeach
                                        @if ($p['notas'] && ! $editable)<span class="text-tenue">Notas: {{ $p['notas'] }}</span>@endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($editable)
                <div class="border-t border-borde px-3 py-2">
                    <button class="font-mono text-xs text-tenue hover:text-texto" wire:click="agregarPieza({{ $i }})">+ Pieza</button>
                </div>
            @endif
        </div>
    @endforeach

    @if ($editable)
        <button class="boton" wire:click="agregarSubensamble">+ Subensamble</button>
        <p class="font-mono text-[11px] text-tenue">Los avisos se recalculan al dar "Guardar". Lo que no esté en rojo ya está listo.</p>
    @endif
</div>
````

### `routes/web.php`

**REEMPLAZA.**

Producción vive en `/produccion`. Las direcciones viejas `/planos` y `/planos/{id}` **redirigen** a las nuevas, por si alguien guardó un enlace.

````php
<?php

use App\Http\Controllers\GoogleController;
use App\Http\Controllers\PdfPlanoController;
use App\Livewire\Cotizaciones\Editor as EditorCotizacion;
use App\Livewire\Cotizaciones\Indice as Cotizaciones;
use App\Livewire\Inicio;
use App\Livewire\Journal;
use App\Livewire\Materiales\Indice as Materiales;
use App\Livewire\Parametros;
use App\Livewire\Produccion\Explorador;
use App\Livewire\Produccion\Plano;
use Illuminate\Support\Facades\Route;

// Cada pantalla es un componente Livewire de página completa
Route::livewire('/', Inicio::class)->name('inicio');
Route::livewire('/journal', Journal::class)->name('journal');
Route::livewire('/materiales', Materiales::class)->name('materiales');
Route::livewire('/parametros', Parametros::class)->name('parametros');
Route::livewire('/cotizaciones', Cotizaciones::class)->name('cotizaciones');
Route::livewire('/cotizaciones/{cotizacion}', EditorCotizacion::class)->name('cotizaciones.editor');

// Producción: planos de Drive (carpetas, subir PDFs) y despiece con IA
Route::livewire('/produccion', Explorador::class)->name('produccion');
Route::livewire('/produccion/planos/{plano}', Plano::class)->name('produccion.plano');
Route::get('/produccion/planos/{plano}/pdf', PdfPlanoController::class)->name('produccion.pdf');

// "Conectar Google": para crear carpetas y subir PDFs en el Drive de una persona
Route::get('/google/conectar', [GoogleController::class, 'conectar'])->name('google.conectar');
Route::get('/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
Route::post('/google/desconectar', [GoogleController::class, 'desconectar'])->name('google.desconectar');

// Direcciones viejas del Módulo 3 → las nuevas (por si alguien guardó el enlace)
Route::redirect('/planos', '/produccion');
Route::get('/planos/{plano}', fn (string $plano) => redirect()->route('produccion.plano', $plano));
````

### `config/sula.php`

**REEMPLAZA.**

Solo cambia la versión a **0.5.0**.

````php
<?php

// Configuración propia de SULA Core
return [
    'version' => '0.5.0',

    // Quién firma los cambios en el journal (temporal, hasta tener login)
    'usuario' => env('SULA_USUARIO', 'desarrollo'),

    // Módulo 3: de dónde salen los planos
    'planos' => [
        'fuente' => env('PLANOS_FUENTE', 'local'),                         // drive o local
        'drive_credenciales' => env('PLANOS_DRIVE_CREDENCIALES', 'datos-privados/drive-service-account.json'),
        'drive_carpeta' => env('PLANOS_DRIVE_CARPETA'),                    // id de la carpeta raíz en Drive
        // Con qué cuenta se crean carpetas y se suben PDFs: cuenta_conectada ("Conectar Google") o cuenta_servicio
        'drive_escribir_con' => env('PLANOS_DRIVE_ESCRIBIR_CON', 'cuenta_conectada'),
        'carpeta_local' => env('PLANOS_CARPETA_LOCAL', base_path('datos-privados/planos')),
        'patron_clave' => env('PLANOS_PATRON_CLAVE'),                      // vacío = 2-1-0072-0003
        // Tamaño de cada envío a la IA. Los valores por defecto caben en el plan GRATIS de Groq
        // (6,000 tokens por minuto). Con plan de pago puedes subirlos (12000 y 3).
        'caracteres_por_lote' => (int) env('PLANOS_CARACTERES_POR_LOTE', 5000),
        'imagenes_por_lote' => (int) env('PLANOS_IMAGENES_POR_LOTE', 1),
    ],

    // "Conectar Google" (OAuth): para escribir en el Drive de una persona
    'google' => [
        'client_id' => env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_OAUTH_REDIRECT', 'http://localhost:8000/google/callback'),
    ],

    // IA: Groq (API compatible con OpenAI)
    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'modelo_texto' => env('GROQ_MODELO_TEXTO', 'llama-3.3-70b-versatile'),
        'modelo_vision' => env('GROQ_MODELO_VISION', 'qwen/qwen3.8-27b'),
        'max_tokens' => (int) env('GROQ_MAX_TOKENS', 3000),
        'max_tokens_vision' => (int) env('GROQ_MAX_TOKENS_VISION', 2000),
    ],
];
````

### Pantallas existentes: solo encabezado y etiquetas

En estas vistas solo cambió el bloque de arriba (ahora `<x-encabezado>` con su ayuda) y las etiquetas de indicadores (de `font-mono text-[10px] tracking-widest uppercase` a `text-xs`). **La lógica y los `wire:` no cambian.**

### `resources/views/livewire/materiales/indice.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-7xl">
    <x-encabezado area="Catálogo" titulo="Materiales"
        descripcion="Los materiales que usa SULA con su precio vigente (sin IVA, por unidad de consumo). Cambiar un precio no borra el anterior: queda en el historial.">
        <x-slot:acciones>
            <button class="boton-primario" wire:click="nuevo">+ Nuevo material</button>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>Haz clic en un material para ver su precio, su historial y sus alias (cómo se llama en los planos).</li>
            <li><b>Nuevo precio</b> registra el precio de hoy; el anterior se guarda.</li>
            <li>Rojo = precio vencido, de confianza baja o sin precio: revisa esos antes de cotizar.</li>
        </x-slot:ayuda>
    </x-encabezado>

    {{-- Indicadores --}}
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        @foreach ($kpis as [$etiqueta, $valor, $alerta])
            <div class="kpi">
                <p class="text-xs text-tenue">{{ $etiqueta }}</p>
                <p @class(['cifras mt-2 font-mono text-3xl font-semibold', 'text-sula' => $alerta])>{{ $valor }}</p>
            </div>
        @endforeach
    </div>

    {{-- Filtros --}}
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-xs" placeholder="Buscar clave o descripción…" wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (array_merge([''], \App\Models\Material::CATEGORIAS) as $c)
                <button wire:click="$set('categoria', '{{ $c }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $categoria === $c, 'border-borde text-tenue hover:text-texto' => $categoria !== $c])>
                    {{ $c ?: 'Todas' }}
                </button>
            @endforeach
        </div>
        <label class="ml-auto flex items-center gap-2 text-sm text-tenue">
            <input type="checkbox" class="accent-sula" wire:model.live="inactivos"> Mostrar inactivos
        </label>
    </div>

    {{-- Tabla --}}
    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Clave</th>
                    <th class="px-4 py-3">Descripción</th>
                    <th class="px-4 py-3">Categoría</th>
                    <th class="px-4 py-3">Presentación</th>
                    <th class="px-4 py-3 text-right">$ Presentación</th>
                    <th class="px-4 py-3 text-right">$ Unidad</th>
                    <th class="px-4 py-3">Confianza</th>
                    <th class="px-4 py-3 text-right">Antigüedad</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($this->materiales as $m)
                    <tr wire:key="mat-{{ $m->id }}" wire:click="ver({{ $m->id }})"
                        @class(['cursor-pointer hover:bg-panel', 'opacity-40' => ! $m->activo, 'bg-panel-alto' => $seleccionado === $m->id])>
                        <td class="px-4 py-3 font-mono font-semibold">{{ $m->clave }}</td>
                        <td class="max-w-sm px-4 py-3"><span class="line-clamp-1">{{ $m->descripcion }}</span></td>
                        <td class="px-4 py-3 font-mono text-xs text-tenue">{{ $m->categoria }}</td>
                        <td class="px-4 py-3 text-tenue">{{ $m->presentacion }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::dinero($m->precio_presentacion_sin_iva) }}</td>
                        <td class="px-4 py-3 text-right font-mono whitespace-nowrap">
                            {{ F::dineroFino($m->precio_unitario_sin_iva) }} <span class="text-tenue">/{{ F::unidad($m->unidad) }}</span>
                        </td>
                        <td class="px-4 py-3"><x-chip-confianza :valor="$m->confianza" /></td>
                        <td @class(['px-4 py-3 text-right font-mono text-xs', 'text-sula' => $m->vencido($this->vigencia), 'text-tenue' => ! $m->vencido($this->vigencia)])>
                            {{ $m->dias_antiguedad === null ? '—' : $m->dias_antiguedad.' d' }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-10 text-center text-tenue">No hay materiales con esos filtros.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

{{-- Panel lateral --}}
@if ($panel)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="cerrar"></button>
    <aside class="fixed inset-y-0 right-0 z-40 flex w-full max-w-xl flex-col border-l border-borde bg-panel shadow-2xl">
        @if ($panel === 'nuevo')
            <div class="overflow-y-auto px-6 py-6">
                <p class="font-mono text-xs tracking-widest text-tenue">MAT · NUEVO</p>
                <h2 class="mt-1 mb-6 text-lg font-semibold">Nuevo material</h2>
                @include('livewire.materiales._form-material', ['esNuevo' => true])
            </div>
        @elseif ($this->detalle && $this->vista)
            @php($m = $this->vista)
            <header class="flex items-start justify-between gap-4 border-b border-borde px-6 py-5">
                <div class="min-w-0">
                    <p class="font-mono text-xs tracking-widest text-tenue">
                        {{ $m->clave }} · {{ $m->categoria }} @unless ($m->activo)<span class="text-sula">· INACTIVO</span>@endunless
                    </p>
                    <h2 class="mt-1 text-lg leading-snug font-semibold">{{ $m->descripcion }}</h2>
                </div>
                <button class="boton px-3" wire:click="cerrar" aria-label="Cerrar">✕</button>
            </header>

            <div class="flex-1 space-y-8 overflow-y-auto px-6 py-6">
                @if ($panel === 'editar')
                    @include('livewire.materiales._form-material', ['esNuevo' => false])
                @else
                    {{-- Precio vigente --}}
                    <section>
                        <div class="flex items-center justify-between">
                            <p class="etiqueta">Precio vigente sin IVA</p>
                            <x-chip-confianza :valor="$m->confianza" />
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-px border border-borde bg-borde">
                            <div class="bg-fondo px-4 py-4">
                                <p class="text-xs text-tenue">POR {{ mb_strtoupper(F::unidad($m->unidad)) }}</p>
                                <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dineroFino($m->precio_unitario_sin_iva) }}</p>
                            </div>
                            <div class="bg-fondo px-4 py-4">
                                <p class="text-xs text-tenue">{{ $m->presentacion }}</p>
                                <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($m->precio_presentacion_sin_iva) }}</p>
                            </div>
                        </div>
                        @if ($m->vigente_desde)
                            <p @class(['mt-2 font-mono text-xs', 'text-sula' => $m->vencido($this->vigencia), 'text-tenue' => ! $m->vencido($this->vigencia)])>
                                Desde {{ F::fecha($m->vigente_desde) }} · hace {{ $m->dias_antiguedad }} días
                                @if ($m->vencido($this->vigencia)) · VENCIDO (vigencia {{ $this->vigencia }} días) @endif
                            </p>
                        @endif

                        @if ($capturandoPrecio)
                            <form wire:submit="registrarPrecio" class="mt-4 space-y-4 border border-borde bg-fondo p-4">
                                @include('livewire.materiales._form-precio', ['tipo' => $m->tipo_calculo, 'presentacion' => $m->presentacion])
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="boton" wire:click="$set('capturandoPrecio', false)">Cancelar</button>
                                    <button type="submit" class="boton-primario">Registrar precio</button>
                                </div>
                            </form>
                        @else
                            <div class="mt-4 flex gap-2">
                                <button class="boton-primario" wire:click="$set('capturandoPrecio', true)">Nuevo precio</button>
                                <button class="boton" wire:click="editar">Editar datos</button>
                            </div>
                        @endif
                    </section>

                    {{-- Datos --}}
                    <section>
                        <p class="etiqueta">Datos</p>
                        <dl class="cifras grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
                            <dt class="text-tenue">Cálculo</dt><dd class="font-mono">{{ $m->tipo_calculo }} · por {{ F::unidad($m->unidad) }}</dd>
                            <dt class="text-tenue">Presentación</dt><dd>{{ $m->presentacion }} · {{ F::num($m->contenido) }} {{ F::unidad($m->unidad) }}</dd>
                            @if ($m->calibre)
                                <dt class="text-tenue">Calibre</dt><dd class="font-mono">Cal {{ $m->calibre }} · {{ $m->kg_m2 }} kg/m²</dd>
                            @endif
                            <dt class="text-tenue">Se cobra</dt><dd>{{ $m->modo_carga === 'COMPLETA' ? 'Presentación completa' : 'Solo lo consumido' }}</dd>
                            <dt class="text-tenue">Proveedor</dt><dd>{{ $m->proveedor ?? '—' }}</dd>
                            @if ($m->liga)
                                <dt class="text-tenue">Liga</dt><dd class="truncate"><a href="{{ $m->liga }}" target="_blank" rel="noopener" class="text-sula underline">{{ $m->liga }}</a></dd>
                            @endif
                            @if ($m->notas)
                                <dt class="text-tenue">Notas</dt><dd class="text-tenue">{{ $m->notas }}</dd>
                            @endif
                        </dl>
                    </section>

                    {{-- Alias --}}
                    <section>
                        <p class="etiqueta">Cómo aparece en los planos (alias)</p>
                        <div class="flex flex-wrap gap-2">
                            @forelse ($this->detalle->alias as $a)
                                <span class="chip border-borde text-texto" wire:key="alias-{{ $a->alias }}">
                                    {{ $a->alias }}
                                    <button class="text-tenue hover:text-sula" wire:click="quitarAlias(@js($a->alias))" aria-label="Quitar">✕</button>
                                </span>
                            @empty
                                <span class="text-sm text-tenue">Sin alias todavía.</span>
                            @endforelse
                        </div>
                        <form wire:submit="agregarAlias" class="mt-3 flex gap-2">
                            <input class="campo font-mono uppercase" placeholder="Nombre del material en el plano" wire:model="nuevoAlias">
                            <button class="boton shrink-0" type="submit">Agregar</button>
                        </form>
                        @error('nuevoAlias') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
                    </section>

                    {{-- Historial --}}
                    <section>
                        <p class="etiqueta">Historial de precios</p>
                        <ol class="divide-y divide-borde border border-borde">
                            @foreach ($this->detalle->precios as $i => $p)
                                <li wire:key="precio-{{ $p->id }}" @class(['cifras grid grid-cols-[1fr_auto] gap-2 px-4 py-3 text-sm', 'bg-fondo' => $i === 0])>
                                    <div>
                                        <p class="font-mono">
                                            {{ F::dineroFino($p->precio_publicado) }}
                                            <span class="text-tenue">/ {{ $p->precio_por === 'UNIDAD' ? F::unidad($m->unidad) : $m->presentacion }} · {{ $p->incluye_iva ? 'con IVA' : 'sin IVA' }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-tenue">{{ F::fecha($p->vigente_desde) }} · {{ $p->fuente ?? 'sin fuente' }} · {{ $p->registrado_por ?? '—' }}</p>
                                    </div>
                                    <div class="text-right">
                                        <x-chip-confianza :valor="$p->confianza" />
                                        @if ($i === 0)<p class="mt-1 font-mono text-[10px] text-ok">VIGENTE</p>@endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            </div>
        @endif
    </aside>
@endif
</div>
````

### `resources/views/livewire/parametros.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-5xl">
    <x-encabezado area="Catálogo" titulo="Precios y márgenes"
        descripcion="Los porcentajes con los que se calcula el precio: mano de obra, overhead y margen. Cada cliente usa los de SULA, a menos que tenga los suyos.">
        <x-slot:ayuda>
            <li>Mano de obra y overhead se suman al costo de materiales.</li>
            <li>Precio = costo ÷ (1 − margen). Con 30% de margen, un costo de $70 se vende en $100.</li>
            <li>Si un cliente negocia otros números, agrégale sus propios parámetros.</li>
        </x-slot:ayuda>
    </x-encabezado>

    <div class="mt-6 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Aplica a</th>
                    <th class="px-4 py-3 text-right">M.O.</th>
                    <th class="px-4 py-3 text-right">Overhead</th>
                    <th class="px-4 py-3 text-right">Margen</th>
                    <th class="px-4 py-3 text-right">Pintura</th>
                    <th class="px-4 py-3 text-right">Vigencia</th>
                    <th class="px-4 py-3">Actualizado</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @foreach ($this->parametros as $p)
                    <tr wire:key="par-{{ $p->id }}" @class(['bg-panel' => ! $p->cliente_id])>
                        <td class="px-4 py-3">
                            @if ($p->cliente_id) <span class="font-mono">{{ $p->cliente->nombre }}</span>
                            @else <span class="font-semibold">SULA · por defecto</span> @endif
                        </td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::pct($p->mano_obra_pct) }}</td>
                        <td class="px-4 py-3 text-right font-mono">
                            {{ F::pct($p->overhead_pct) }} <span class="text-tenue">{{ $p->overhead_incluye_mo ? '(+M.O.)' : '' }}</span>
                        </td>
                        <td class="px-4 py-3 text-right font-mono font-semibold">{{ F::pct($p->margen_pct) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::num($p->rendimiento_pintura_m2kg) }} m²/kg</td>
                        <td class="px-4 py-3 text-right font-mono">{{ $p->vigencia_precios_dias }} d</td>
                        <td class="px-4 py-3 text-xs text-tenue">{{ F::fecha($p->actualizado_en) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <button class="boton px-3 py-1" wire:click="abrir({{ $p->cliente_id ?? 'null' }})">Editar</button>
                            @if ($p->cliente_id)
                                <button class="boton px-3 py-1" wire:click="quitar({{ $p->cliente_id }})">Quitar</button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-8">
        <p class="etiqueta">Clientes que usan los parámetros de SULA</p>
        <div class="flex flex-wrap gap-2">
            @forelse ($this->clientesSinPropios as $c)
                <button class="chip border-borde py-1.5 text-texto hover:border-sula" wire:click="abrir({{ $c->id }})" title="Darle parámetros propios">
                    {{ $c->nombre }} <span class="text-tenue">＋</span>
                </button>
            @empty
                <span class="text-sm text-tenue">Todos los clientes tienen parámetros propios.</span>
            @endforelse
        </div>
        <form wire:submit="agregarCliente" class="mt-4 flex max-w-sm gap-2">
            <input class="campo uppercase" placeholder="Nuevo cliente" wire:model="nuevoCliente">
            <button class="boton shrink-0" type="submit">Agregar</button>
        </form>
        @error('nuevoCliente') <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
    </div>
</section>

@if ($editando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('editando', null)"></button>
    <aside class="fixed inset-y-0 right-0 z-40 w-full max-w-md overflow-y-auto border-l border-borde bg-panel px-6 py-6">
        <p class="font-mono text-xs tracking-widest text-tenue">PAR · EDITAR</p>
        <h2 class="mt-1 mb-6 text-lg font-semibold">{{ $editando['titulo'] }}</h2>

        <form wire:submit="guardar" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                @foreach ([['mo', 'Mano de obra %'], ['overhead', 'Overhead %'], ['margen', 'Margen sobre precio %'], ['pintura', 'Pintura m²/kg'], ['vigencia', 'Vigencia precios (días)']] as [$campo, $nombre])
                    <div>
                        <label class="etiqueta" for="{{ $campo }}">{{ $nombre }}</label>
                        <input id="{{ $campo }}" class="campo cifras font-mono" inputmode="decimal" wire:model.live.debounce.300ms="editando.{{ $campo }}">
                        @error("editando.$campo") <p class="mt-1 text-xs text-sula">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <label class="col-span-2 flex items-center gap-2 text-sm">
                    <input type="checkbox" class="accent-sula" wire:model.live="editando.overhead_incluye_mo"> El overhead se calcula sobre materiales + M.O.
                </label>
                <div class="col-span-2">
                    <label class="etiqueta" for="notas">Notas</label>
                    <textarea id="notas" class="campo" rows="2" wire:model="editando.notas"></textarea>
                </div>
            </div>

            @if ($ej = $this->ejemplo)
                <div class="cifras border border-borde bg-fondo p-4 font-mono text-xs">
                    <p class="etiqueta">Ejemplo con $1,000 de materiales</p>
                    <div class="grid grid-cols-2 gap-y-1">
                        <span class="text-tenue">Mano de obra</span><span class="text-right">{{ F::dinero($ej['mo']) }}</span>
                        <span class="text-tenue">Overhead</span><span class="text-right">{{ F::dinero($ej['oh']) }}</span>
                        <span class="text-tenue">Costo total</span><span class="text-right">{{ F::dinero($ej['costo']) }}</span>
                        <span class="font-semibold">Precio de venta</span>
                        <span class="text-right text-base font-semibold text-sula">{{ F::dinero($ej['precio']) }}</span>
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <button type="button" class="boton" wire:click="$set('editando', null)">Cancelar</button>
                <button type="submit" class="boton-primario">Guardar</button>
            </div>
        </form>
    </aside>
@endif
</div>
````

### `resources/views/livewire/journal.blade.php`

**REEMPLAZA.**

````blade
<section class="mx-auto max-w-6xl">
    <x-encabezado area="Sistema" titulo="Historial de cambios"
        descripcion="Cada alta, cambio o baja queda registrada aquí con quién la hizo y cuándo. Este registro no se puede editar ni borrar.">
        <x-slot:acciones>
            <select wire:model.live="tabla" class="campo w-auto text-sm">
                <option value="">Todo</option>
                @foreach ($tablas as $t)
                    <option value="{{ $t }}">{{ $t }}</option>
                @endforeach
            </select>
        </x-slot:acciones>
    </x-encabezado>

    <div class="mt-6 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">#</th>
                    <th class="px-4 py-3">Fecha</th>
                    <th class="px-4 py-3">Tabla</th>
                    <th class="px-4 py-3">Operación</th>
                    <th class="px-4 py-3">Registro</th>
                    <th class="px-4 py-3">Usuario</th>
                    <th class="px-4 py-3">Cambio</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde font-mono text-xs">
                @forelse ($eventos as $e)
                    <tr class="hover:bg-panel" wire:key="evento-{{ $e->id }}">
                        <td class="px-4 py-3 text-tenue">{{ $e->id }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $e->ocurrido_en->format('d/m/y H:i:s') }}</td>
                        <td class="px-4 py-3">{{ $e->tabla }}</td>
                        <td @class(['px-4 py-3 font-semibold',
                            'text-ok' => $e->operacion === 'INSERT',
                            'text-alerta' => $e->operacion === 'UPDATE',
                            'text-sula' => $e->operacion === 'DELETE'])>{{ $e->operacion }}</td>
                        <td class="px-4 py-3">{{ $e->registro_id ?? '—' }}</td>
                        <td class="px-4 py-3 text-tenue">{{ $e->usuario ?? 'sistema' }}</td>
                        <td class="max-w-md px-4 py-3">
                            @forelse ($e->cambios() as $c)
                                <div class="truncate" title="{{ $c }}">{{ $c }}</div>
                            @empty
                                <span class="text-tenue">—</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-tenue">Sin eventos todavía.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
````

### `resources/views/livewire/cotizaciones/indice.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="mx-auto max-w-7xl">
    <x-encabezado area="Ventas" titulo="Cotizaciones"
        descripcion="Una cotización por plano. Mientras está en borrador se calcula con los precios de hoy; al enviarla, los precios se congelan.">
        <x-slot:acciones>
            <button class="boton-primario" wire:click="nueva">+ Nueva cotización</button>
        </x-slot:acciones>
        <x-slot:ayuda>
            <li>La forma más rápida: en <b>Producción</b>, abre el plano, aprueba su despiece y dale <b>Cotizar este plano</b>.</li>
            <li>También puedes crearla aquí y pegar el despiece desde Excel.</li>
            <li><b>Enviar</b> congela los precios. Para cambiarla después se abre una nueva revisión (R1, R2…).</li>
        </x-slot:ayuda>
    </x-encabezado>

    @php($k = $this->kpis)
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        <div class="kpi">
            <p class="text-xs text-tenue">En borrador</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $k['borradores'] }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Enviadas · {{ $k['n_enviadas'] }}</p>
            <p class="cifras mt-2 font-mono text-2xl font-semibold text-alerta">{{ F::dinero($k['enviadas']) }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Aprobadas · {{ $k['n_aprobadas'] }}</p>
            <p class="cifras mt-2 font-mono text-2xl font-semibold text-ok">{{ F::dinero($k['aprobadas']) }}</p>
        </div>
        <div class="kpi">
            <p class="text-xs text-tenue">Tasa de aprobación</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $k['tasa'] === null ? '—' : $k['tasa'].'%' }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-xs" placeholder="Folio, plano, mueble o cliente…" wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (['', 'BORRADOR', 'ENVIADA', 'APROBADA', 'RECHAZADA'] as $e)
                <button wire:click="$set('estado', '{{ $e }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $estado === $e, 'border-borde text-tenue hover:text-texto' => $estado !== $e])>
                    {{ $e ?: 'Todas' }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Folio</th>
                    <th class="px-4 py-3">Plano</th>
                    <th class="px-4 py-3">Mueble</th>
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3 text-right">Cant.</th>
                    <th class="px-4 py-3 text-right">Precio unitario</th>
                    <th class="px-4 py-3 text-right">Total pedido</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3">Riesgo</th>
                    <th class="px-4 py-3">Actualizada</th>
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($this->lista as $c)
                    <tr wire:key="cot-{{ $c->id }}" class="hover:bg-panel">
                        <td class="px-4 py-3 font-mono font-semibold whitespace-nowrap">
                            <a href="{{ route('cotizaciones.editor', $c) }}" wire:navigate class="hover:text-sula">{{ $c->folio }}</a>
                            @if ($c->revision > 0)<span class="text-tenue"> R{{ $c->revision }}</span>@endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap">{{ $c->plano_codigo }} <span class="text-tenue">{{ $c->plano_rev }}</span></td>
                        <td class="min-w-48 px-4 py-3">
                            <a href="{{ route('cotizaciones.editor', $c) }}" wire:navigate class="line-clamp-1 hover:text-sula">{{ $c->descripcion }}</a>
                        </td>
                        <td class="px-4 py-3 text-tenue">{{ $c->cliente?->nombre ?? '—' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ $c->cantidad }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ F::dinero($c->precio_lista) }}</td>
                        <td class="px-4 py-3 text-right font-mono font-semibold">{{ F::dinero($c->total_lista) }}</td>
                        <td class="px-4 py-3"><x-chip-estado :estado="$c->estado" :vencida="$c->estaVencida()" /></td>
                        <td class="px-4 py-3">
                            @if ($c->riesgo_lista) <x-chip-riesgo :nivel="$c->riesgo_lista" />
                            @else <span class="text-xs text-tenue">congelada</span> @endif
                        </td>
                        <td class="px-4 py-3 text-xs whitespace-nowrap text-tenue">{{ F::fecha($c->actualizado_en) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-4 py-12 text-center text-tenue">Todavía no hay cotizaciones. Crea la primera con “+ Nueva cotización”.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

@if ($creando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('creando', false)"></button>
    <aside class="fixed inset-y-0 right-0 z-40 w-full max-w-xl overflow-y-auto border-l border-borde bg-panel px-6 py-6">
        <p class="font-mono text-xs tracking-widest text-tenue">CST · NUEVA</p>
        <h2 class="mt-1 mb-6 text-lg font-semibold">Nueva cotización</h2>
        @include('livewire.cotizaciones._encabezado', [
            'campo' => 'form', 'accion' => 'crear', 'textoBoton' => 'Crear y capturar despiece',
            'cancelar' => "\$set('creando', false)",
        ])
    </aside>
@endif
</div>
````

### `resources/views/livewire/cotizaciones/editor.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')
@php
    $c = $cotizacion;
    $calc = $this->calculo;
    $r = $calc['resultado'];
    $s = $r['resumen'] ?? null;
    $versionActual = $c->versiones->firstWhere('revision', $c->revision);
    $pestanas = ['despiece' => 'Despiece', 'compras' => 'Compras', 'resumen' => 'Resumen', 'simulador' => 'Simulador', 'versiones' => 'Versiones', 'datos' => 'Datos'];
@endphp

<div>
<section class="mx-auto max-w-7xl space-y-6">
    {{-- Encabezado y acciones --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm text-tenue">
                <a href="{{ route('cotizaciones') }}" wire:navigate class="hover:text-texto">Cotizaciones</a> ›
                <span class="font-mono">{{ $c->folio }}</span> · Revisión {{ $c->revision }}
            </p>
            <h1 class="mt-1 text-3xl font-semibold">{{ $c->descripcion }}</h1>
            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-tenue">
                <span class="font-mono">{{ $c->plano_codigo }} · {{ $c->plano_rev }}</span>
                <span>· {{ $c->cliente?->nombre ?? 'Sin cliente' }}</span>
                <span>· {{ $c->cantidad }} mueble{{ $c->cantidad === 1 ? '' : 's' }}</span>
                @if ($c->dibujo)<span>· Dibujó {{ $c->dibujo }}</span>@endif
                <x-chip-estado :estado="$c->estado" :vencida="$c->estaVencida()" />
                @if ($r) <x-chip-riesgo :nivel="$r['riesgo']['nivel']" /> @endif
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            @if ($c->esBorrador())
                <button class="boton-primario" wire:click="enviar" @disabled(! $c->piezas->count())
                        wire:confirm="Se congelan los precios de hoy y ya no se podrá editar. ¿Enviar al cliente?">
                    Enviar al cliente
                </button>
            @elseif ($c->estado === 'ENVIADA')
                <button class="boton border-ok/50 text-ok" wire:click="decidir('APROBADA')">Aprobada</button>
                <button class="boton border-sula/50 text-sula" wire:click="decidir('RECHAZADA')">Rechazada</button>
            @endif
            @unless ($c->esBorrador())
                <button class="boton" wire:click="abrirRevision">Nueva revisión</button>
            @endunless
            <button class="boton" wire:click="duplicar">Duplicar</button>
        </div>
    </div>

    @if ($error) <div class="aviso-error">{{ $error }}</div> @endif

    @if ($calc['congelada'])
        <div class="aviso-alerta">
            Precios congelados el {{ F::fecha($versionActual?->creado_en) }}: aunque cambie el catálogo, esta revisión no se mueve.
            Para modificarla abre una <strong>nueva revisión</strong>.
            @if ($c->estaVencida()) <span class="text-sula">La vigencia de {{ $c->vigencia_dias }} días ya pasó.</span> @endif
        </div>
    @endif
    @if ($calc['error']) <div class="aviso-error">{{ $calc['error'] }}</div> @endif

    {{-- Números grandes --}}
    @if ($s)
        <div class="grid grid-cols-2 gap-px border border-borde bg-borde md:grid-cols-3 xl:grid-cols-6">
            @foreach (['Materiales' => $s['materiales'], 'Mano de obra' => $s['mano_obra'], 'Overhead' => $s['overhead'], 'Costo' => $s['costo_total']] as $k => $v)
                <div class="kpi">
                    <p class="text-xs text-tenue">{{ $k }}</p>
                    <p class="cifras mt-2 font-mono text-lg md:text-xl">{{ F::dinero($v) }}</p>
                </div>
            @endforeach
            <div class="kpi">
                <p class="text-xs text-tenue">Precio / mueble</p>
                <p class="cifras mt-2 font-mono text-xl font-semibold text-sula md:text-2xl">{{ F::dinero($s['precio_venta']) }}</p>
            </div>
            <div class="kpi">
                <p class="text-xs text-tenue">Pedido × {{ $s['cantidad'] }}</p>
                <p class="cifras mt-2 font-mono text-xl font-semibold md:text-2xl">{{ F::dinero($s['total_pedido']) }}</p>
            </div>
        </div>
    @endif

    {{-- Pestañas --}}
    <nav class="flex gap-1 overflow-x-auto border-b border-borde">
        @foreach ($pestanas as $id => $nombre)
            <button wire:click="$set('pestana', '{{ $id }}')"
                @class(['-mb-px border-b-2 px-4 py-2.5 font-mono text-xs tracking-widest whitespace-nowrap uppercase transition-colors',
                        'border-sula text-texto' => $pestana === $id, 'border-transparent text-tenue hover:text-texto' => $pestana !== $id])>
                {{ $nombre }}
                @if ($id === 'despiece')<span class="text-tenue">· {{ $c->piezas->count() }}</span>@endif
                @if ($id === 'versiones' && $c->versiones->count())<span class="text-tenue">· {{ $c->versiones->count() }}</span>@endif
            </button>
        @endforeach
    </nav>

    @include('livewire.cotizaciones.pestanas.'.$pestana)
</section>
</div>
````

### `resources/views/livewire/cotizaciones/pestanas/despiece.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')
{{-- Despiece del plano por UN mueble. En borrador es editable; se recalcula al guardar. --}}
@php
    $editable = $c->esBorrador();
    $calculadas = $r['piezas'] ?? [];
    $automaticas = array_filter($calculadas, fn ($p) => $p['automatica']);
@endphp

<div class="space-y-4">
    @if ($editable)
        <div class="flex flex-wrap items-center gap-2">
            <button class="boton" wire:click="agregarFila">+ Pieza</button>
            <button class="boton" wire:click="$set('pegando', true)">Pegar desde Excel</button>
            <div class="ml-auto flex items-center gap-3">
                <span wire:dirty wire:target="filas" class="font-mono text-xs text-alerta">● Cambios sin guardar: los costos se recalculan al guardar</span>
                <button class="boton" wire:click="descartarCambios" wire:dirty wire:target="filas">Descartar</button>
                <button class="boton-primario" wire:click="guardarPiezas">
                    <span wire:loading.remove wire:target="guardarPiezas">Guardar y calcular</span>
                    <span wire:loading wire:target="guardarPiezas">Calculando…</span>
                </button>
            </div>
        </div>
        @if ($errors->any())
            <div class="aviso-error">@foreach ($errors->all() as $e) <p>{{ $e }}</p> @endforeach</div>
        @endif
    @endif

    <div class="overflow-x-auto border border-borde">
        <table class="w-full min-w-[1100px] text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="w-10 px-3 py-3">#</th>
                    <th class="px-2 py-3">Subensamble</th>
                    <th class="px-2 py-3">Pieza</th>
                    <th class="px-2 py-3">Material</th>
                    <th class="px-2 py-3 text-right">Cant.</th>
                    <th class="px-2 py-3 text-right">Largo mm</th>
                    <th class="px-2 py-3 text-right">Ancho mm</th>
                    <th class="px-2 py-3 text-right" title="Perímetro pintable (tubo) o caras (lámina)">Pintura</th>
                    <th class="px-2 py-3 text-right">Consumo</th>
                    <th class="px-2 py-3 text-right">$ ref.</th>
                    @if ($editable)<th class="w-16 px-2 py-3"></th>@endif
                </tr>
            </thead>
            <tbody class="cifras divide-y divide-borde">
                @forelse ($filas as $i => $f)
                    @php
                        $calc = $calculadas[$i] ?? null;
                        $tipo = $mats[$f['material_id']]->tipo_calculo ?? null;
                    @endphp
                    <tr wire:key="fila-{{ $i }}" @class(['align-top', 'bg-sula/5' => $calc && $calc['avisos']])>
                        <td class="px-3 py-2 font-mono text-xs text-tenue">{{ $i + 1 }}</td>
                        @if ($editable)
                            <td class="px-1 py-1"><input class="campo px-2 py-1 text-xs uppercase" wire:model="filas.{{ $i }}.subensamble"></td>
                            <td class="px-1 py-1"><input class="campo min-w-48 px-2 py-1 text-xs" wire:model="filas.{{ $i }}.descripcion"></td>
                            <td class="px-1 py-1">
                                <select class="campo min-w-44 px-2 py-1 text-xs" wire:model.live="filas.{{ $i }}.material_id">
                                    @foreach ($mats as $m)
                                        <option value="{{ $m->id }}">{{ $m->clave }} · {{ $m->descripcion }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1"><input class="campo w-20 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.cantidad"></td>
                            <td class="px-1 py-1"><input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.largo_mm" @disabled(in_array($tipo, ['PIEZA', 'PESO']))></td>
                            <td class="px-1 py-1"><input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" wire:model="filas.{{ $i }}.ancho_mm" @disabled(! in_array($tipo, ['AREA', 'AREA_KG']))></td>
                            <td class="px-1 py-1">
                                @if ($tipo === 'LINEAL')
                                    <input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" placeholder="perím. mm" title="Perímetro pintable en mm" wire:model="filas.{{ $i }}.perimetro_pintable_mm">
                                @elseif (in_array($tipo, ['AREA', 'AREA_KG']))
                                    <input class="campo w-24 px-2 py-1 text-right font-mono text-xs" inputmode="decimal" placeholder="caras" title="Caras pintadas (0, 1 o 2)" wire:model="filas.{{ $i }}.caras_pintables">
                                @else
                                    <span class="block px-2 py-1 text-right text-tenue">—</span>
                                @endif
                            </td>
                        @else
                            <td class="px-2 py-2 font-mono text-xs text-tenue">{{ $f['subensamble'] ?: '—' }}</td>
                            <td class="px-2 py-2">{{ $f['descripcion'] }}</td>
                            <td class="px-2 py-2 font-mono text-xs">{{ $mats[$f['material_id']]->clave ?? $calc['clave'] ?? '' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ F::num($f['cantidad']) }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ (float) $f['largo_mm'] ? F::num($f['largo_mm']) : '—' }}</td>
                            <td class="px-2 py-2 text-right font-mono">{{ (float) $f['ancho_mm'] ? F::num($f['ancho_mm']) : '—' }}</td>
                            <td class="px-2 py-2 text-right font-mono text-tenue">
                                {{ (float) $f['perimetro_pintable_mm'] ? F::num($f['perimetro_pintable_mm']).' mm' : ((float) $f['caras_pintables'] ? F::num($f['caras_pintables']).' c' : '—') }}
                            </td>
                        @endif
                        <td class="px-2 py-2 text-right font-mono text-xs whitespace-nowrap">
                            @if ($calc) {{ F::num($calc['consumo_total']) }} <span class="text-tenue">{{ F::unidad($calc['unidad']) }}</span> @else <span class="text-tenue">…</span> @endif
                        </td>
                        <td class="px-2 py-2 text-right font-mono text-xs">
                            {{ $calc ? F::dinero($calc['costo']) : '…' }}
                            @foreach ($calc['avisos'] ?? [] as $a)
                                <p class="mt-1 text-left font-sans text-[11px] text-sula">⚠ {{ $a }}</p>
                            @endforeach
                        </td>
                        @if ($editable)
                            <td class="px-2 py-2 whitespace-nowrap">
                                <button class="px-1 text-tenue hover:text-texto" title="Duplicar" wire:click="duplicarFila({{ $i }})">⧉</button>
                                <button class="px-1 text-tenue hover:text-sula" title="Quitar" wire:click="quitarFila({{ $i }})">✕</button>
                            </td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="11" class="px-4 py-12 text-center text-tenue">Sin piezas. Agrega la primera o pega el despiece desde Excel.</td></tr>
                @endforelse

                @foreach ($automaticas as $a)
                    <tr class="bg-panel/50 text-tenue">
                        <td class="px-3 py-2 font-mono text-xs">auto</td>
                        <td class="px-2 py-2 font-mono text-xs">{{ $a['subensamble'] }}</td>
                        <td class="px-2 py-2" colspan="6">{{ $a['descripcion'] }}</td>
                        <td class="px-2 py-2 text-right font-mono text-xs">{{ F::num($a['consumo_total']) }} {{ F::unidad($a['unidad']) }}</td>
                        <td class="px-2 py-2 text-right font-mono text-xs">{{ F::dinero($a['costo']) }}</td>
                        @if ($editable)<td></td>@endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($s)
        <p class="font-mono text-xs text-tenue">
            {{ count($filas) }} piezas · área metálica pintable {{ F::num($s['area_pintable_m2']) }} m² ·
            "$ ref." = consumo × precio por unidad (el costo real se decide en Compras)
        </p>
    @endif
</div>

{{-- Ventana: pegar desde Excel --}}
@if ($pegando)
    <button class="fixed inset-0 z-30 bg-black/30" aria-label="Cerrar" wire:click="$set('pegando', false)"></button>
    <div class="fixed inset-x-4 top-12 z-40 mx-auto max-h-[85vh] max-w-3xl overflow-y-auto border border-borde bg-panel p-6 shadow-2xl">
        <p class="font-mono text-xs tracking-widest text-tenue">DESPIECE · IMPORTAR</p>
        <h2 class="mt-1 text-lg font-semibold">Pegar desde Excel</h2>
        <p class="mt-2 text-sm text-tenue">
            Copia las columnas en este orden y pégalas aquí. El material puede ser la clave (<span class="font-mono">M05</span>)
            o como aparece en el plano (<span class="font-mono">MELAMINA ROBLE (SMA) 15MM</span>).
        </p>
        <div class="mt-3 overflow-x-auto border border-borde bg-fondo px-3 py-2 font-mono text-[11px] whitespace-nowrap text-tenue">
            Descripción ⇥ Material ⇥ Cantidad ⇥ Largo mm ⇥ Ancho mm ⇥ Pintura* ⇥ Subensamble
        </div>
        <p class="mt-1 text-xs text-tenue">*Pintura = perímetro pintable en mm (tubo) o número de caras (lámina). Las últimas columnas son opcionales.</p>

        <textarea class="campo mt-4 font-mono text-xs" rows="10" wire:model="textoPegado"></textarea>

        @if ($importacion)
            <div class="mt-4 grid grid-cols-2 gap-px border border-borde bg-borde">
                <div class="bg-fondo px-4 py-3">
                    <p class="text-xs text-tenue">PIEZAS RECONOCIDAS</p>
                    <p class="cifras mt-1 font-mono text-2xl font-semibold text-ok">{{ count($importacion['piezas']) }}</p>
                </div>
                <div class="bg-fondo px-4 py-3">
                    <p class="text-xs text-tenue">LÍNEAS CON ERROR</p>
                    <p @class(['cifras mt-1 font-mono text-2xl font-semibold', 'text-sula' => count($importacion['errores'])])>{{ count($importacion['errores']) }}</p>
                </div>
            </div>
            <ul class="mt-3 space-y-1 text-xs">
                @foreach ($importacion['errores'] as $e)
                    <li class="border-l-2 border-sula pl-2"><span class="font-mono text-tenue">Línea {{ $e['linea'] }}:</span> {{ $e['mensaje'] }}</li>
                @endforeach
            </ul>
        @endif

        <div class="mt-5 flex flex-wrap justify-end gap-2">
            <button class="boton" wire:click="$set('pegando', false)">Cancelar</button>
            @if ($importacion && count($importacion['piezas']))
                <button class="boton" wire:click="aplicarPegado(true)">Reemplazar todo</button>
                <button class="boton-primario" wire:click="aplicarPegado(false)">Agregar {{ count($importacion['piezas']) }} piezas</button>
            @else
                <button class="boton-primario" wire:click="revisarPegado">Revisar</button>
            @endif
        </div>
    </div>
@endif
````

### `resources/views/livewire/cotizaciones/pestanas/simulador.blade.php`

**REEMPLAZA.**

````blade
@use('App\Support\Formato', 'F')
{{-- Simulador "¿y si…?": nada se guarda. Cada movimiento recalcula con el mismo motor. --}}
@php
    $delta = fn ($a, $b) => (float) $b - (float) $a;
@endphp

@if (! $c->piezas->count())
    <p class="text-tenue">Captura el despiece para simular.</p>
@else
<div class="grid gap-6 lg:grid-cols-[1fr_1.2fr]">
    <section class="space-y-5 border border-borde bg-panel p-5">
        <div class="flex items-center justify-between">
            <p class="etiqueta mb-0">¿Y si…?</p>
            <button class="boton px-3 py-1" wire:click="reiniciarSimulador">Reiniciar</button>
        </div>

        @foreach ([['simMargen', 'Margen sobre precio', 0, 60], ['simMo', 'Mano de obra', 0, 60], ['simOh', 'Overhead', 0, 40]] as [$prop, $nombre, $min, $max])
            <label class="block">
                <span class="flex justify-between text-sm"><span>{{ $nombre }}</span><span class="cifras font-mono">{{ $$prop }}%</span></span>
                <input type="range" class="mt-2 w-full accent-sula" min="{{ $min }}" max="{{ $max }}" step="0.5" wire:model.live.debounce.150ms="{{ $prop }}">
            </label>
        @endforeach

        <label class="block">
            <span class="text-sm">Cantidad de muebles</span>
            <input type="number" min="1" class="campo mt-2 w-32 font-mono" wire:model.live.debounce.300ms="simCantidad">
        </label>

        <div class="border-t border-borde pt-4">
            <p class="etiqueta">Si cambia el precio de…</p>
            @foreach ([['simMetal', 'Acero / metal'], ['simMadera', 'Madera / melamina'], ['simHerrajes', 'Herrajes']] as [$prop, $nombre])
                @php($v = $$prop)
                <label class="mt-3 block">
                    <span class="flex justify-between text-sm">
                        <span>{{ $nombre }}</span>
                        <span @class(['cifras font-mono', 'text-sula' => $v > 0, 'text-ok' => $v < 0])>{{ $v > 0 ? '+' : '' }}{{ $v }}%</span>
                    </span>
                    <input type="range" class="mt-2 w-full accent-sula" min="-30" max="30" step="1" wire:model.live.debounce.150ms="{{ $prop }}">
                </label>
            @endforeach
        </div>
    </section>

    <section class="space-y-4">
        @if ($sim)
            @php
                [$b, $x] = [$sim['base']['resumen'], $sim['sim']['resumen']];
                $dp = $delta($b['precio_venta'], $x['precio_venta']);
                $rel = (float) $b['precio_venta'] ? $dp / (float) $b['precio_venta'] * 100 : 0;
            @endphp
            <div class="grid gap-px border border-borde bg-borde sm:grid-cols-2">
                <div class="kpi sm:col-span-2">
                    <p class="text-xs text-tenue">PRECIO POR MUEBLE SIN IVA</p>
                    <p class="cifras mt-2 font-mono text-4xl font-semibold">{{ F::dinero($x['precio_venta']) }}</p>
                    <p @class(['cifras mt-1 font-mono text-sm', 'text-sula' => $dp > 0, 'text-ok' => $dp < 0, 'text-tenue' => $dp == 0])>
                        {{ $dp >= 0 ? '+' : '' }}{{ F::dinero($dp) }} ({{ $rel >= 0 ? '+' : '' }}{{ number_format($rel, 1) }}%) vs. {{ F::dinero($b['precio_venta']) }}
                    </p>
                </div>
                <div class="kpi">
                    <p class="text-xs text-tenue">TOTAL DEL PEDIDO × {{ $x['cantidad'] }}</p>
                    <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($x['total_pedido']) }}</p>
                </div>
                <div class="kpi">
                    <p class="text-xs text-tenue">UTILIDAD POR MUEBLE</p>
                    <p class="cifras mt-2 font-mono text-2xl font-semibold">{{ F::dinero($x['utilidad']) }}</p>
                    <p class="cifras mt-1 font-mono text-xs text-tenue">{{ $delta($b['utilidad'], $x['utilidad']) >= 0 ? '+' : '' }}{{ F::dinero($delta($b['utilidad'], $x['utilidad'])) }}</p>
                </div>
                <div class="kpi"><p class="text-xs text-tenue">MATERIALES</p><p class="cifras mt-2 font-mono text-xl">{{ F::dinero($x['materiales']) }}</p></div>
                <div class="kpi"><p class="text-xs text-tenue">COSTO TOTAL</p><p class="cifras mt-2 font-mono text-xl">{{ F::dinero($x['costo_total']) }}</p></div>
            </div>

            <div class="border border-borde bg-panel p-5">
                <label class="etiqueta" for="precio-cliente">¿El cliente pide un precio? Escríbelo y ve tu margen real</label>
                <div class="flex flex-wrap items-center gap-4">
                    <input id="precio-cliente" class="campo w-48 font-mono" inputmode="decimal" placeholder="11000" wire:model.live.debounce.300ms="precioCliente">
                    @if (is_numeric($precioCliente) && (float) $precioCliente > 0)
                        @php
                            $ganancia = (float) $precioCliente - (float) $x['costo_total'];
                            $margenReal = $ganancia / (float) $precioCliente * 100;
                        @endphp
                        <p @class(['cifras font-mono text-lg font-semibold', 'text-sula' => $margenReal < 15, 'text-alerta' => $margenReal >= 15 && $margenReal < 25, 'text-ok' => $margenReal >= 25])>
                            Margen {{ number_format($margenReal, 1) }}%
                        </p>
                        <p class="text-xs text-tenue">{{ $ganancia < 0 ? 'Pierdes dinero en cada mueble.' : 'Ganas '.F::dinero($ganancia).' por mueble.' }}</p>
                    @endif
                </div>
            </div>
        @else
            <p class="text-tenue">Revisa los valores del simulador (el margen debe ser menor a 100%).</p>
        @endif
    </section>
</div>
@endif
````

## 7. PRUEBAS

### `tests/Unit/NombreArchivoTest.php`

**REEMPLAZA.**

+2 pruebas: limpiar nombres de carpeta y de PDF.

````php
<?php

namespace Tests\Unit;

use App\Planos\NombreArchivo;
use PHPUnit\Framework\TestCase;

/** La clave y la revisión salen del nombre del PDF. */
class NombreArchivoTest extends TestCase
{
    public function test_clave_sula_en_el_nombre(): void
    {
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('2-1-0072-0003 VITRINA PARA REFACCIONES REV-01.pdf'));
        $this->assertSame('2-1-0072-0003', NombreArchivo::clave('VENTO_2-1-0072-0003.pdf'));
    }

    public function test_sin_clave_usa_el_nombre_limpio(): void
    {
        $this->assertSame('SULA - MODULO EXHIBIDOR PL - FT', NombreArchivo::clave('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
        $this->assertSame('GONDOLA CENTRAL', NombreArchivo::clave('gondola_central REV-02.pdf'));
    }

    public function test_revision(): void
    {
        $this->assertSame('REV-01', NombreArchivo::revision('2-1-0072-0003 VITRINA REV-01.pdf'));
        $this->assertSame('REV-03', NombreArchivo::revision('mesa rev 3.pdf'));
        $this->assertSame('REV-00', NombreArchivo::revision('SULA - MODULO EXHIBIDOR PL - FT.pdf'));
    }

    public function test_patron_propio(): void
    {
        $this->assertSame('KFC-0012', NombreArchivo::clave('Mostrador KFC-0012.pdf', '/KFC-\d{4}/'));
    }

    public function test_limpia_nombres_de_carpeta(): void
    {
        $this->assertSame('VENTO Vitrinas 2026', NombreArchivo::limpiarCarpeta('  VENTO / Vitrinas: 2026?  '));
        $this->assertSame('a b c', NombreArchivo::limpiarCarpeta('a\\b/c'));
        $this->assertSame('', NombreArchivo::limpiarCarpeta(' ../ '));
    }

    public function test_limpia_nombres_de_pdf(): void
    {
        $this->assertSame('2-1-0072 VITRINA.pdf', NombreArchivo::limpiarPdf('C:\\Users\\x\\2-1-0072 VITRINA.PDF'));
        $this->assertSame('mesa.pdf', NombreArchivo::limpiarPdf('mesa'));
    }
}
````

### `tests/Unit/FuenteLocalTest.php`

**NUEVO.**

Con una **carpeta temporal de verdad** (sin Drive): explora en orden y solo PDFs, crea carpeta y sube, **no sobrescribe** y **no deja salirse** con `../`. `setUp()` arma la carpeta antes de cada prueba y `tearDown()` la borra después.

````php
<?php

namespace Tests\Unit;

use App\Planos\Fuentes\FuenteLocal;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** La fuente local se prueba con una carpeta temporal de verdad (sin Drive). */
class FuenteLocalTest extends TestCase
{
    private string $raiz;

    protected function setUp(): void
    {
        $this->raiz = sys_get_temp_dir().'/sula-planos-'.uniqid();
        mkdir($this->raiz.'/VENTO/VITRINAS', 0775, true);
        file_put_contents($this->raiz.'/VENTO/VITRINAS/2-1-0072-0003 VITRINA.pdf', '%PDF-1.4 prueba');
        file_put_contents($this->raiz.'/raiz.pdf', '%PDF-1.4 prueba');
        file_put_contents($this->raiz.'/VENTO/notas.txt', 'no es pdf');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->raiz));
    }

    public function test_explora_carpetas_en_orden_y_solo_pdfs(): void
    {
        $r = (new FuenteLocal($this->raiz))->explorar();

        $this->assertSame(['VENTO', 'VENTO/VITRINAS'], array_map(fn ($c) => $c->id, $r['carpetas'])); // padre antes que hija
        $this->assertSame('VENTO', $r['carpetas'][1]->padreId);
        $this->assertCount(2, $r['archivos']);
        $porId = [];
        foreach ($r['archivos'] as $a) {
            $porId[$a->id] = $a;
        }
        $this->assertSame('VENTO/VITRINAS', $porId['VENTO/VITRINAS/2-1-0072-0003 VITRINA.pdf']->carpetaId);
        $this->assertSame(null, $porId['raiz.pdf']->carpetaId);
    }

    public function test_crea_carpeta_y_sube_pdf(): void
    {
        $f = new FuenteLocal($this->raiz);
        $c = $f->crearCarpeta('KFC', 'VENTO');
        $this->assertSame('VENTO/KFC', $c->id);
        $this->assertSame('VENTO', $c->padreId);

        $a = $f->subir('mostrador.pdf', '%PDF-1.4 hola', $c->id);
        $this->assertSame('VENTO/KFC/mostrador.pdf', $a->id);
        $this->assertSame('%PDF-1.4 hola', $f->descargar($a->id));
    }

    public function test_no_sobrescribe(): void
    {
        $this->expectException(RuntimeException::class);
        (new FuenteLocal($this->raiz))->subir('raiz.pdf', '%PDF otra', null);
    }

    public function test_no_deja_salir_de_la_carpeta(): void
    {
        $this->expectException(RuntimeException::class);
        (new FuenteLocal($this->raiz))->descargar('../../etc/passwd');
    }
}
````

## 8. ARRANCAR

```bash
cd ~/dev/sula-core-laravel
docker compose up -d --wait
php artisan config:clear
php artisan migrate                       # 100005_crear_carpetas y 100006_crear_conexion_google
php artisan test --testsuite=Unit         # 27 pruebas en verde
php artisan cache:clear                   # tira el token viejo de Drive (solo lectura)
php artisan sula:planos                   # primera sincronización: carpetas + PDFs de PLANOS (puede tardar)
npm run build                             # o npm run dev: recompila el CSS con el tema claro
```
Terminales de siempre: `php artisan serve`, `npm run dev` y **`php artisan queue:work --timeout=900`** (la IA).

---

## 9. CRITERIOS DE ACEPTACIÓN

| # | Prueba | Esperado |
|---|---|---|
| 1 | `php artisan test --testsuite=Unit` | **27** OK |
| 2 | `php artisan migrate:status` | `100005_crear_carpetas` y `100006_crear_conexion_google` en "Ran" |
| 3 | Abrir `/` | Tema **claro**; menú con Producción / Ventas / Catálogo / Sistema, cada opción con su descripción; versión 0.5.0 abajo |
| 4 | Inicio | 4 tarjetas "¿Qué quieres hacer?" con su número y el camino de 4 pasos |
| 5 | Ventana angosta (celular) | el menú se esconde; ☰ lo abre; tocar afuera lo cierra |
| 6 | Materiales, Precios y márgenes, Cotizaciones, Historial | encabezado nuevo con "¿Cómo se usa esta pantalla?" desplegable; todo lo demás funciona igual |
| 7 | `php artisan sula:planos` con `PLANOS_FUENTE=drive` | tabla con **Carpetas** (PROMODA, KFC, LIVERPOOL…) y PDFs; sin errores |
| 8 | Producción | tarjetas de carpetas de la raíz + PDFs de la raíz; "Última actualización: hace X" |
| 9 | Clic en una carpeta | ruta "Todos los planos › VENTO"; se ven sus subcarpetas y PDFs; la dirección dice `?carpeta=…` |
| 10 | Buscar una clave (ej. `0072`) o `vitrina` | encuentra en **todas** las carpetas y muestra columna "Carpeta"; la clave sale del nombre del PDF |
| 10a | Sin cuenta conectada | barra "Para crear carpetas y subir PDFs conecta…"; botón "Conectar Google para subir"; ver planos y "Leer con IA" funcionan |
| 10b | **Conectar Google** con `gutzessp@gmail.com` | pantalla de Google → "no verificada" → Avanzado → Ir a SULA Core → aceptar Drive → regresa a Producción con "Google conectado como gutzessp@gmail.com" |
| 10c | Tabla `conexion_google` | 1 fila; `refresh_token` es texto cifrado (empieza con `eyJ`), no se lee la llave |
| 11 | **Nueva carpeta** "PRUEBA AS500" dentro de una carpeta de cliente | aviso "creada en Drive"; aparece en el sistema **y en Drive** dentro de PLANOS |
| 12 | Crear otra vez "PRUEBA AS500" | error "Ya existe una carpeta llamada…" |
| 13 | Nombre `A/B:C` | se crea como `A B C` |
| 14 | Arrastrar 2 PDFs a la pantalla | aparece "Suelta los PDFs…"; luego la lista con nombre y tamaño |
| 15 | **Subir a Drive** | ✓ por archivo con su clave; aparecen en la tabla con etiqueta "subido aquí", **en Drive** dentro de esa carpeta y su copia en `storage/app/private/planos/drive/` |
| 16 | Subir el mismo PDF otra vez | ✕ "Ya existe … ponle REV-01…" (no se sobrescribe) |
| 17 | Subir un `.jpg` o `.txt` | "Solo se aceptan PDFs." |
| 18 | PDF de 15 MB | sube bien (confirma `php.ini` y `config/livewire.php`) |
| 19 | Historial de cambios | INSERT en `carpetas` y `planos` firmados por `gutz` |
| 20 | Abrir un PDF | ruta de carpetas arriba; visor; "Leer con IA" funciona como en el Módulo 3 (con `queue:work`) |
| 21 | `/planos` (dirección vieja) | redirige a `/produccion` |
| 22 | Subir un archivo **directo en Drive** y darle "Actualizar desde Drive" | aparece; "1 nuevos" |
| 23 | **Desconectar** y volver a intentar subir | vuelve el botón "Conectar Google para subir"; no se puede subir |
| 24 | `PLANOS_FUENTE=local` | crear carpeta y subir funcionan en `datos-privados/planos/` |

**Para limpiar las pruebas:** la carpeta "PRUEBA AS500" y los PDFs de prueba se borran **en Drive a mano** (desde el sistema no se borra). Luego "Actualizar desde Drive": quedan como retirados y dejan de verse.

**Si "Conectar Google" falla:** `redirect_uri_mismatch` = el URI del `.env` no es idéntico al de Google Cloud · `access_denied` = tu correo no está en "Usuarios de prueba" o no aceptaste Drive · se desconecta cada 7 días = la app sigue en "Prueba": publícala.

---

## 10. GIT

```bash
git status                 # datos-privados/ y .env NO deben aparecer
git add -A
git commit -m "Módulo Producción: buscador, carpetas y subida a Drive (Conectar Google), despiece con IA; rediseño claro"
```
(El push lo hago yo.)

---

## 11. SIGUIENTE

- Pasar Materiales, Cotizaciones y Precios y márgenes al diseño nuevo **por dentro** (tablas, formularios, pestañas), una por una.
- Automatizaciones con n8n (avisos por correo o WhatsApp cuando un despiece está listo o una cotización se aprueba).
- Lo de ClickBalance (clientes, inventario, cotización → orden) y, al final, el importador de listas de precios.
