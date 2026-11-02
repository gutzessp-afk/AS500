<!-- SPEC SULA Core · MÓDULO 3 · PARTE 1 DE 5 -->
> **PARTE 1 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte1.md` y responde solo "recibida 1/5". **No ejecutes nada** hasta recibir la parte 5/5.

# SPEC — SULA Core · Módulo 3: Planos y despiece con IA (Groq)

**Proyecto:** SULA Core (AS500), en Laravel 13 + Livewire 4 + PostgreSQL 17
**Fecha:** 2026-10-30
**Autor:** Gutz + Claude
**Requisito:** los Módulos 0, 1 y 2 ya construidos en `~/dev/sula-core-laravel` (spec de 7 partes).

**Qué hace este módulo, en una línea:** buscas la clave → ves el plano de Drive → la IA (Groq) arma el despiece con subensambles → lo revisas y lo apruebas → con un botón se vuelve cotización con los costos del Módulo 2.

**Qué está verificado y qué no:**
- ✅ **12 pruebas nuevas en verde** (`NombreArchivoTest`, `NormalizadorTest`) + las 9 del motor siguen en verde (21 en total). Incluyen: leer números como vienen en los planos ("1,800", "37 mm"), juntar dos lecturas sin pisar datos, reconocer materiales por leyenda (ML2 → Melamina 25 mm → M06), estimar pintura (tubo 1" = 101.6 mm, igual que el Excel) y la explosión (2 costados × 3 gabinetes = 6).
- ✅ **Migración**: subió, bajó y volvió a subir en PostgreSQL. Se probó que no puede haber 2 despieces aprobados del mismo plano, que no acepta cantidad 0, que el journal registra todo y que borrar un plano borra su despiece.
- ✅ **Poppler** con el PDF real del exhibidor: el texto sale (tablas, leyenda, cotas) y la imagen de cada página pesa ~300 KB.
- ✅ Sintaxis de todos los archivos (`php -l`).
- ⚠️ **No se pudo probar aquí** (sin Packagist, sin tu Drive ni tu llave de Groq): las pantallas Livewire, la conexión real a Drive y las respuestas reales de Groq. La sección 9 trae los criterios para probarlo en tu máquina; si algo truena, se corrige ahí.

---

## 0. INSTRUCCIONES PARA CLAUDE CODE

- Este módulo se **agrega** al proyecto existente `~/dev/sula-core-laravel`. **No reescribas** los Módulos 0–2: solo se modifican los archivos marcados como **REEMPLAZA** en la sección 4.
- Antes de crear archivos, muéstrame la lista de lo que vas a tocar y explícame qué hace cada uno (estoy aprendiendo).
- **No ejecutes `sudo`** (dime el comando y yo lo corro) y **no hagas `git push`**.
- `datos-privados/` (precios, JSON de Drive, planos de prueba) y `.env` **nunca** se suben a git. El repo es **privado**.
- **Nunca** pegues mi `GROQ_API_KEY` ni el JSON de la Service Account en el chat, en el código ni en commits: solo van en `.env` y `datos-privados/`.
- Si un comando falla, detente, muéstrame el error y propón la solución. Si una pantalla falla, corrige ese archivo y dime qué cambiaste.
- El proyecto viejo en Go (`~/dev/sula-core`) y el contenedor `sula-core-db` **no se tocan**.

---

## 1. CÓMO FUNCIONA

```
 Google Drive (carpeta de planos)          ó   datos-privados/planos/ (carpeta local)
            │  php artisan sula:planos  /  botón "Sincronizar"
            ▼
 tabla planos  ── clave sacada del nombre: "2-1-0072-0003 VITRINA REV-01.pdf" → 2-1-0072-0003 · REV-01
            │
            │  /planos  → buscas "0072"  → /planos/{id}
            ▼
 ┌───────────── pantalla del plano ─────────────┐
 │  visor PDF (se baja de Drive y se guarda     │
 │  en storage/app/private/planos)              │
 │                                              │
 │  [Leer con IA]  ☐ revisar también dibujos    │──► Job LeerPlano (cola) ──► Groq
 │                                              │      1. texto de páginas (pdftotext)
 │  despiece v1 · BORRADOR                      │      2. imágenes de páginas (pdftoppm) opcional
 │   GABINETE ×3                                │      3. Normalizador: limpia, junta, cruza
 │     Costado  ML1→M05  2  669×333             │         materiales con el catálogo, avisos
 │     Puerta   ⚠ «…» no está en catálogo       │
 │   [Guardar] [Aprobar]                        │
 │                                              │
 │  APROBADO → [Cotizar este plano] ────────────┼──► Cotización BORRADOR (Módulo 2)
 └──────────────────────────────────────────────┘     piezas × subensamble = por mueble
```

**Reglas de negocio:**
1. **La IA propone, una persona aprueba.** Todo lo que lee la IA queda en **BORRADOR**. Las piezas con dato faltante o material desconocido salen **en rojo** y bloquean "Aprobar".
2. **No inventar.** El prompt le exige a la IA poner `null` si no ve un dato. Es mejor un rojo que un número inventado.
3. **El sistema aprende.** Si la IA leyó "BISAGRA RECTA CAZOLETA 35mm" y tú eliges M09, al aprobar se guarda como **alias**: el siguiente plano ya la reconoce solo.
4. **Versiones.** Cada lectura o cambio grande es una versión nueva (v1, v2…). Solo puede haber **un aprobado** por plano; al aprobar otro, el anterior queda **REEMPLAZADO**.
5. **Una lectura por plano.** Ya aprobado, no se vuelve a gastar IA en esa clave (salvo que tú le des "Volver a leer").
6. **Explosión.** La cantidad de una pieza es **por subensamble**. Por mueble = cantidad del subensamble × cantidad de la pieza. Así se manda a la cotización.
7. **Confidencialidad.** El PDF nunca se guarda en la base de datos. A Groq solo va el texto o las imágenes de las páginas, y en Groq se activa **Zero Data Retention**.

**Por qué en segundo plano (cola):** un plano de 26 páginas puede tardar minutos (y Groq gratis limita tokens por minuto). Si se hiciera en la petición web, el navegador se quedaría colgado o PHP lo cortaría a los 30 s. Con la cola, la pantalla solo pregunta el avance cada 3 s (`wire:poll`).

**Dos pasadas de IA:**
- **Texto** (rápido y barato): los planos de SolidWorks traen como texto la tabla de subensambles, la leyenda (LM1, ML2, TC1…) y muchas cotas.
- **Dibujos** (opcional, más lento): las piezas marcadas "VARIOS" (postes, gabinetes) solo están **dibujadas**. Se mandan imágenes de las páginas al modelo de visión para completar medidas.

---

## 2. PRERREQUISITOS (los hago yo)

### 2.1 Poppler (saca texto e imágenes del PDF)

```bash
sudo pacman -S poppler
pdftotext -v
```

### 2.2 Llave de Groq

1. Entra a <https://console.groq.com> → **API Keys** → **Create API Key** (nómbrala `sula-core`, aparte de la de Mobi para ver el gasto por separado).
2. **Settings → Data Controls → activa Zero Data Retention.** Los planos de clientes son confidenciales: con esto Groq no guarda nada.
3. Revisa en <https://console.groq.com/docs/models> que los modelos del `.env` sigan vigentes (Groq los cambia seguido). Si alguno ya no existe, solo cambia el nombre en el `.env`.

**Límites del plan gratis** (por modelo): ~30 peticiones y **~6,000 tokens por minuto**. Los valores por defecto del `.env` están pensados para caber ahí. Un plano chico (3 páginas) se lee en segundos. Uno grande o con dibujos tarda minutos, porque el sistema espera cuando Groq dice "429: espera". Si se queda corto, el plan de pago (Developer) es barato y solo subes los números del `.env`.

### 2.3 Planos: Drive o carpeta local

**Opción A: Google Drive (igual que SULA MOB).**
1. Copia el JSON de la Service Account de SULA MOB a `datos-privados/drive-service-account.json`.
2. En Drive, la carpeta de planos debe estar **compartida** (lector) con el `client_email` de ese JSON.
3. El **id de la carpeta** es lo que va después de `/folders/` en la dirección: `https://drive.google.com/drive/folders/ESTE_ES_EL_ID`.
4. En el `.env`: `PLANOS_FUENTE=drive`.

**Opción B: carpeta local (para probar ya, sin Drive).**
```bash
mkdir -p ~/dev/sula-core-laravel/datos-privados/planos
cp ~/Descargas/"SULA - MODULO EXHIBIDOR PL - FT.pdf" ~/dev/sula-core-laravel/datos-privados/planos/
```
En el `.env`: `PLANOS_FUENTE=local`. Se pueden usar subcarpetas (por cliente, por línea).

---

## 3. CONFIGURACIÓN

### `.env`: agregar al final

```dotenv
# ---------- Módulo 3: planos ----------
# drive o local
PLANOS_FUENTE=local
PLANOS_DRIVE_CREDENCIALES=datos-privados/drive-service-account.json
PLANOS_DRIVE_CARPETA=
# Vacío = carpeta datos-privados/planos
PLANOS_CARPETA_LOCAL=
# Vacío = claves tipo 2-1-0072-0003 (expresión regular de PHP)
PLANOS_PATRON_CLAVE=
# Tamaño de cada envío a la IA (caben en el plan gratis de Groq)
PLANOS_CARACTERES_POR_LOTE=5000
PLANOS_IMAGENES_POR_LOTE=1

# ---------- Groq ----------
GROQ_API_KEY=
GROQ_MODELO_TEXTO=llama-3.3-70b-versatile
GROQ_MODELO_VISION=qwen/qwen3.8-27b
GROQ_MAX_TOKENS=3000
GROQ_MAX_TOKENS_VISION=2000

# ---------- Cola ----------
# La lectura con IA puede tardar hasta 15 min: el trabajo no debe "reintentarse" antes
DB_QUEUE_RETRY_AFTER=1000
```

**Ojo con `PLANOS_CARPETA_LOCAL`:** si lo dejas vacío, el `config/sula.php` usa `datos-privados/planos`. Si pones una ruta, que sea absoluta.

**Verifica la cola:** abre `config/queue.php` y confirma que la conexión `database` tenga `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)`. Si el nombre de la variable es otro, usa ese en el `.env`. Confirma también que `.env` tenga `QUEUE_CONNECTION=database` (viene así en Laravel 13) y que exista la tabla `jobs` (`php artisan migrate:status` → `0001_01_01_000002_create_jobs_table` en "Ran").

**Plan de pago de Groq:** sube `PLANOS_CARACTERES_POR_LOTE=12000`, `PLANOS_IMAGENES_POR_LOTE=3` y `GROQ_MAX_TOKENS=6000`. Menos llamadas = más rápido.

---

## 4. ARCHIVOS

| # | Archivo | | Qué es |
|---|---|---|---|
| 1 | `database/migrations/2026_10_30_100004_crear_planos.php` | **NUEVO** | Tablas `planos`, `despieces`, `despiece_subensambles`, `despiece_piezas`, `ia_llamadas` |
| 2 | `app/Models/Plano.php` | **NUEVO** | Modelo del índice de planos |
| 3 | `app/Models/Despiece.php` | **NUEVO** | Versión del despiece |
| 4 | `app/Models/DespieceSubensamble.php` | **NUEVO** | Subensamble (× cantidad) |
| 5 | `app/Models/DespiecePieza.php` | **NUEVO** | Pieza |
| 6 | `app/Models/IaLlamada.php` | **NUEVO** | Bitácora de llamadas a la IA |
| 7 | `app/Planos/Fuentes/ArchivoPlano.php` | **NUEVO** | Datos de un PDF en la fuente |
| 8 | `app/Planos/Fuentes/FuentePlanos.php` | **NUEVO** | Interfaz: qué debe saber hacer una fuente |
| 9 | `app/Planos/Fuentes/FuenteLocal.php` | **NUEVO** | Planos desde una carpeta |
| 10 | `app/Planos/Fuentes/FuenteDrive.php` | **NUEVO** | Planos desde Google Drive (Service Account) |
| 11 | `app/Planos/Fuentes/Fuente.php` | **NUEVO** | Elige Drive o local según el `.env` |
| 12 | `app/Planos/NombreArchivo.php` | **NUEVO** | Clave y revisión desde el nombre del PDF |
| 13 | `app/Planos/Sincronizador.php` | **NUEVO** | Pone al día la tabla `planos` |
| 14 | `app/Planos/AlmacenPdf.php` | **NUEVO** | Copia local del PDF |
| 15 | `app/Planos/ExtractorPdf.php` | **NUEVO** | Texto e imágenes de páginas (Poppler) |
| 16 | `app/Ia/ErrorIa.php` | **NUEVO** | Error de la IA |
| 17 | `app/Ia/Groq.php` | **NUEVO** | Cliente de Groq (JSON, reintentos, bitácora) |
| 18 | `app/Planos/Prompts.php` | **NUEVO** | Instrucciones para la IA |
| 19 | `app/Planos/Normalizador.php` | **NUEVO** | Limpia, junta, resuelve y explota (sin base de datos) |
| 20 | `app/Planos/LectorPlano.php` | **NUEVO** | Orquesta la lectura completa |
| 21 | `app/Planos/ErrorDespiece.php` | **NUEVO** | Error de regla de negocio |
| 22 | `app/Planos/FlujoDespiece.php` | **NUEVO** | Leer, capturar, copiar, guardar, aprobar, cotizar |
| 23 | `app/Jobs/LeerPlano.php` | **NUEVO** | Trabajo en segundo plano |
| 24 | `app/Console/Commands/SincronizarPlanos.php` | **NUEVO** | `php artisan sula:planos` |
| 25 | `app/Http/Controllers/PdfPlanoController.php` | **NUEVO** | Sirve el PDF al visor |
| 26 | `app/Livewire/Planos/Indice.php` + vista | **NUEVO** | Buscador de planos |
| 27 | `app/Livewire/Planos/Ver.php` + vista + `_despiece` | **NUEVO** | Visor + despiece editable |
| 28 | `resources/views/components/chip-despiece.blade.php` | **NUEVO** | Chip de estado |
| 29 | `tests/Unit/NombreArchivoTest.php` | **NUEVO** | 4 pruebas |
| 30 | `tests/Unit/NormalizadorTest.php` | **NUEVO** | 8 pruebas |
| 31 | `config/sula.php` | **REEMPLAZA** | Versión 0.4.0 + config de planos y Groq |
| 32 | `routes/web.php` | **REEMPLAZA** | Rutas `/planos` |
| 33 | `resources/views/layouts/app.blade.php` | **CAMBIO PUNTUAL** | Sección "Ingeniería" en el menú |
| 34 | `app/Livewire/Inicio.php` + `inicio.blade.php` | **REEMPLAZA** | Tarjeta de planos |

**No se toca:** el motor (`app/Costeo`), `app/Cotizaciones`, las migraciones 1–3, Materiales, Parámetros, Cotizaciones.

---

## 5. BASE DE DATOS

### `database/migrations/2026_10_30_100004_crear_planos.php`

**NUEVO.**

**Qué hace:**
- `planos`: el **índice** de PDFs. `UNIQUE (origen, archivo_id)` evita duplicados al sincronizar. `activo = false` si el archivo ya no está en Drive (no se borra porque puede tener despiece).
- `despieces`: versiones. El índice parcial `uq_despiece_aprobado` (`WHERE estado = 'APROBADO'`) hace que **la base de datos misma** impida dos aprobados del mismo plano.
- `despiece_subensambles` → `despiece_piezas`: el árbol. `ON DELETE CASCADE` borra hijos con el padre.
- `avisos JSONB`: la lista de avisos de cada pieza (`error` = rojo, `info` = gris).
- `ia_llamadas`: cada llamada a Groq con tokens y tiempo, para saber cuánto gastas.
- `SELECT auditar(...)`: el journal del Módulo 0 registra cada cambio del despiece.

````php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Módulo 3: planos (índice de los PDFs de Drive o de una carpeta local)
 * y su despiece: plano → subensambles → piezas.
 * El despiece lo propone la IA (Groq) y una persona lo revisa y lo aprueba.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- =====================================================================
-- PLANOS: índice de archivos. El PDF NO se guarda en la base de datos,
-- solo el id del archivo en su origen (Drive o carpeta local).
-- =====================================================================
CREATE TABLE planos (
    id                   BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    origen               TEXT NOT NULL CHECK (origen IN ('DRIVE', 'LOCAL')),
    archivo_id           TEXT NOT NULL,                -- id de Drive o ruta relativa en la carpeta local
    nombre               TEXT NOT NULL,                -- nombre del archivo tal cual
    clave                TEXT NOT NULL,                -- sacada del nombre (2-1-0072-0003) o el nombre limpio
    revision             TEXT NOT NULL DEFAULT 'REV-00',
    carpeta              TEXT,                         -- subcarpeta donde vive (cliente, línea…)
    tamano_bytes         BIGINT,
    huella               TEXT,                         -- md5 o fecha: si cambia, el PDF cambió
    modificado_en_origen TIMESTAMPTZ,
    activo               BOOLEAN NOT NULL DEFAULT true, -- false = ya no está en el origen
    sincronizado_en      TIMESTAMPTZ NOT NULL DEFAULT now(),
    creado_en            TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (origen, archivo_id)
);
CREATE INDEX idx_planos_clave ON planos (clave);
CREATE INDEX idx_planos_nombre ON planos (lower(nombre));

-- =====================================================================
-- DESPIECES: versiones del despiece de un plano.
-- Estados: LEYENDO (la IA trabaja) → BORRADOR (se revisa) → APROBADO
--          ERROR (la IA falló)  ·  REEMPLAZADO (había uno aprobado y se aprobó otro)
-- =====================================================================
CREATE TABLE despieces (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    plano_id        BIGINT NOT NULL REFERENCES planos (id) ON DELETE CASCADE,
    version         INT    NOT NULL,
    estado          TEXT   NOT NULL DEFAULT 'BORRADOR'
                      CHECK (estado IN ('LEYENDO', 'BORRADOR', 'APROBADO', 'REEMPLAZADO', 'ERROR')),
    origen          TEXT   NOT NULL CHECK (origen IN ('IA', 'MANUAL', 'COPIA')),
    con_dibujos     BOOLEAN NOT NULL DEFAULT false,   -- la IA también miró las imágenes de las páginas
    datos           JSONB  NOT NULL DEFAULT '{}',     -- modelo, descripción, cliente, acabado, leyenda de materiales
    progreso        TEXT,                             -- "Leyendo páginas 4-6 de 26…"
    error           TEXT,
    aprobado_en     TIMESTAMPTZ,
    aprobado_por    TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    UNIQUE (plano_id, version)
);
-- Solo puede haber UN despiece aprobado por plano
CREATE UNIQUE INDEX uq_despiece_aprobado ON despieces (plano_id) WHERE estado = 'APROBADO';
SELECT auditar('despieces');

-- Subensambles: "GABINETE ×3", "POSTES ×1", "HERRAJE DE ARMADO ×1"
CREATE TABLE despiece_subensambles (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    despiece_id  BIGINT NOT NULL REFERENCES despieces (id) ON DELETE CASCADE,
    orden        INT    NOT NULL DEFAULT 0,
    nombre       TEXT   NOT NULL,
    cantidad     NUMERIC(12,4) NOT NULL DEFAULT 1 CHECK (cantidad > 0),  -- cuántos lleva UN mueble
    pagina       INT,
    notas        TEXT
);
CREATE INDEX idx_subensambles_despiece ON despiece_subensambles (despiece_id, orden);
SELECT auditar('despiece_subensambles');

-- Piezas de cada subensamble. La cantidad es POR SUBENSAMBLE.
-- Por mueble = cantidad del subensamble × cantidad de la pieza (la "explosión").
CREATE TABLE despiece_piezas (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    subensamble_id        BIGINT NOT NULL REFERENCES despiece_subensambles (id) ON DELETE CASCADE,
    orden                 INT    NOT NULL DEFAULT 0,
    descripcion           TEXT   NOT NULL,
    material_texto        TEXT,                                    -- como viene en el plano
    material_id           BIGINT REFERENCES materiales (id),       -- NULL = falta decidir
    cantidad              NUMERIC(12,4) NOT NULL DEFAULT 1 CHECK (cantidad > 0),
    largo_mm              NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (largo_mm >= 0),
    ancho_mm              NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (ancho_mm >= 0),
    perimetro_pintable_mm NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (perimetro_pintable_mm >= 0),
    caras_pintables       NUMERIC(4,1)  NOT NULL DEFAULT 0 CHECK (caras_pintables >= 0),
    pagina                INT,
    notas                 TEXT,
    avisos                JSONB NOT NULL DEFAULT '[]'              -- [{nivel: error|info, texto}]
);
CREATE INDEX idx_piezas_subensamble ON despiece_piezas (subensamble_id, orden);
SELECT auditar('despiece_piezas');

-- =====================================================================
-- IA_LLAMADAS: bitácora de cada llamada a Groq (tokens, tiempo, error).
-- Sirve para saber cuánto se gasta y por qué falló algo.
-- =====================================================================
CREATE TABLE ia_llamadas (
    id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    despiece_id     BIGINT REFERENCES despieces (id) ON DELETE SET NULL,
    paso            TEXT   NOT NULL,     -- TEXTO o DIBUJOS
    modelo          TEXT   NOT NULL,
    paginas         TEXT,                -- "1-3"
    tokens_entrada  INT    NOT NULL DEFAULT 0,
    tokens_salida   INT    NOT NULL DEFAULT 0,
    milisegundos    INT    NOT NULL DEFAULT 0,
    ok              BOOLEAN NOT NULL,
    error           TEXT,
    creado_en       TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_ia_llamadas_fecha ON ia_llamadas (creado_en);
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS ia_llamadas;
DROP TABLE IF EXISTS despiece_piezas;
DROP TABLE IF EXISTS despiece_subensambles;
DROP TABLE IF EXISTS despieces;
DROP TABLE IF EXISTS planos;
SQL);
    }
};
````

## 6. MODELOS

Modelos de Eloquent: cada uno representa una tabla. `casts` convierte columnas (JSON → arreglo, fecha → Carbon). Las relaciones (`hasMany`, `belongsTo`) permiten escribir `$plano->despieces` en lugar de SQL.

### `app/Models/Plano.php`

**NUEVO.**

`aprobado()` y `ultimo()` son relaciones `HasOne` con filtro: con `Plano::with(['aprobado', 'ultimo'])` se cargan en 3 consultas para toda la lista, no una por plano.

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
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
        return ['activo' => 'boolean', 'modificado_en_origen' => 'datetime', 'sincronizado_en' => 'datetime'];
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

### `app/Models/Despiece.php`

**NUEVO.**

`piezas()` es `HasManyThrough`: llega a las piezas pasando por los subensambles (sirve para contar errores al aprobar).

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/** Una versión del despiece de un plano: subensambles → piezas. */
class Despiece extends Model
{
    protected $table = 'despieces';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = 'actualizado_en';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['datos' => 'array', 'con_dibujos' => 'boolean', 'aprobado_en' => 'datetime'];
    }

    public function plano(): BelongsTo
    {
        return $this->belongsTo(Plano::class);
    }

    public function subensambles(): HasMany
    {
        return $this->hasMany(DespieceSubensamble::class)->orderBy('orden')->orderBy('id');
    }

    /** Todas las piezas, pasando por sus subensambles. */
    public function piezas(): HasManyThrough
    {
        return $this->hasManyThrough(DespiecePieza::class, DespieceSubensamble::class, 'despiece_id', 'subensamble_id');
    }

    public function esEditable(): bool
    {
        return $this->estado === 'BORRADOR';
    }
}
````

### `app/Models/DespieceSubensamble.php`

**NUEVO.**

Sin `timestamps`: la tabla no tiene `created_at`/`updated_at`.

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "GABINETE ×3": cuántos lleva un mueble y qué piezas tiene. */
class DespieceSubensamble extends Model
{
    protected $table = 'despiece_subensambles';

    public $timestamps = false;

    protected $guarded = ['id'];

    public function piezas(): HasMany
    {
        return $this->hasMany(DespiecePieza::class, 'subensamble_id')->orderBy('orden')->orderBy('id');
    }
}
````

### `app/Models/DespiecePieza.php`

**NUEVO.**

`avisos` se guarda como JSON y se lee como arreglo PHP.

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una pieza de un subensamble. La cantidad es POR SUBENSAMBLE. */
class DespiecePieza extends Model
{
    protected $table = 'despiece_piezas';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['avisos' => 'array'];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }
}
````

### `app/Models/IaLlamada.php`

**NUEVO.**

Solo se escribe (bitácora).

````php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Bitácora de cada llamada a la IA (tokens, tiempo, error). */
class IaLlamada extends Model
{
    protected $table = 'ia_llamadas';

    const CREATED_AT = 'creado_en';

    const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }
}
````

## 7. DE DÓNDE SALEN LOS PLANOS

Aquí se usa una **interfaz**: `FuentePlanos` dice *qué* debe saber hacer una fuente (listar y descargar) y cada clase dice *cómo*. El resto del sistema habla con la interfaz, así mañana se puede agregar otra fuente (un servidor de la planta, SharePoint) sin tocar nada más.

### `app/Planos/Fuentes/ArchivoPlano.php`

**NUEVO.**

`final readonly class`: un objeto de solo lectura con los datos de un PDF. Se crea una vez y no se modifica.

````php
<?php

namespace App\Planos\Fuentes;

/** Lo que una fuente (Drive o carpeta local) dice de un PDF. */
final readonly class ArchivoPlano
{
    public function __construct(
        public string $id,          // id de Drive o ruta relativa
        public string $nombre,      // "2-1-0072-0003 VITRINA REV-01.pdf"
        public ?string $carpeta,    // "VENTO/VITRINAS"
        public ?int $tamano,
        public ?string $huella,     // md5 (Drive) o fecha+tamaño (local)
        public ?string $modificado, // fecha ISO 8601
    ) {}
}
````

### `app/Planos/Fuentes/FuentePlanos.php`

**NUEVO.**

El contrato. Cualquier clase que diga `implements FuentePlanos` está obligada a tener estos 3 métodos.

````php
<?php

namespace App\Planos\Fuentes;

/**
 * "Contrato" que cumple cualquier lugar de donde salen los planos.
 * Hoy hay dos: Google Drive y una carpeta local. El resto del sistema
 * no sabe ni le importa de cuál viene el PDF.
 */
interface FuentePlanos
{
    /** 'DRIVE' o 'LOCAL' (se guarda en planos.origen) */
    public function origen(): string;

    /** Todos los PDFs, recorriendo subcarpetas. @return ArchivoPlano[] */
    public function listar(): array;

    /** Los bytes del PDF. */
    public function descargar(string $id): string;
}
````

### `app/Planos/Fuentes/FuenteLocal.php`

**NUEVO.**

- Recorre la carpeta y subcarpetas buscando `.pdf`.
- La "huella" es fecha + tamaño: si cambias el PDF, cambia la huella y se vuelve a copiar.
- **Seguridad** en `descargar()`: `realpath` + `str_starts_with` impide pedir `../../.env`.

````php
<?php

namespace App\Planos\Fuentes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Planos desde una carpeta de tu máquina (PLANOS_CARPETA_LOCAL).
 * Sirve para probar sin Drive o si algún día los planos viven en un servidor.
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

    public function listar(): array
    {
        if (! is_dir($this->raiz)) {
            throw new RuntimeException("La carpeta de planos no existe: {$this->raiz}");
        }

        $archivos = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->raiz, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || strtolower($f->getExtension()) !== 'pdf') {
                continue;
            }
            $relativa = ltrim(substr($f->getPathname(), strlen($this->raiz)), '/');
            $carpeta = dirname($relativa);
            $archivos[] = new ArchivoPlano(
                id: $relativa,
                nombre: $f->getFilename(),
                carpeta: $carpeta === '.' ? null : $carpeta,
                tamano: $f->getSize(),
                huella: $f->getMTime().'-'.$f->getSize(),
                modificado: date(DATE_ATOM, $f->getMTime()),
            );
        }

        return $archivos;
    }

    public function descargar(string $id): string
    {
        // Seguridad: no dejar salir de la carpeta con "../"
        $ruta = realpath($this->raiz.'/'.$id);
        $raiz = realpath($this->raiz);
        if ($ruta === false || $raiz === false || ! str_starts_with($ruta, $raiz.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("El plano no existe en la carpeta local: $id");
        }

        return file_get_contents($ruta);
    }
}
````

### `app/Planos/Fuentes/FuenteDrive.php`

**NUEVO.**

- **Sin la librería de Google** (pesa decenas de MB): se hace el mismo login "a mano" con el cliente HTTP de Laravel.
- `token()`: arma un **JWT** (encabezado.datos.firma), lo firma con la llave privada del JSON (`openssl_sign`, RS256) y Google lo cambia por un token de 1 hora. Se guarda 55 min en caché para no pedirlo en cada llamada.
- `listar()`: recorre carpetas "a lo ancho" con una cola (`$pendientes`). `$vistas` evita ciclos con accesos directos. Pide de 1,000 en 1,000 (`nextPageToken`).
- `supportsAllDrives`: funciona también si la carpeta está en una **unidad compartida**.
- Permiso `drive.readonly`: el sistema **no puede** modificar ni borrar nada en Drive.

````php
<?php

namespace App\Planos\Fuentes;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Planos desde Google Drive con una Service Account (solo lectura).
 *
 * No usamos la librería oficial de Google (pesa mucho). Hacemos lo mismo
 * a mano con el cliente HTTP de Laravel:
 *   1. Firmamos un JWT con la llave privada del JSON de la Service Account.
 *   2. Lo cambiamos por un token de acceso (dura 1 hora; lo guardamos en caché).
 *   3. Con ese token listamos la carpeta y descargamos PDFs.
 *
 * Importante: la carpeta de Drive debe estar COMPARTIDA con el correo de la
 * Service Account (client_email del JSON), como en SULA MOB.
 */
class FuenteDrive implements FuentePlanos
{
    private const API = 'https://www.googleapis.com/drive/v3';

    private const CARPETA = 'application/vnd.google-apps.folder';

    public function __construct(private string $rutaCredenciales, private string $carpetaRaiz) {}

    public function origen(): string
    {
        return 'DRIVE';
    }

    public function listar(): array
    {
        $archivos = [];
        // Recorremos carpetas "a lo ancho": [id de carpeta, ruta legible]
        $pendientes = [[$this->carpetaRaiz, null]];
        $vistas = [];

        while ($pendientes) {
            [$carpetaId, $ruta] = array_shift($pendientes);
            if (isset($vistas[$carpetaId])) {
                continue; // un atajo podría crear un ciclo
            }
            $vistas[$carpetaId] = true;

            $pagina = null;
            do {
                $r = $this->http()->get(self::API.'/files', array_filter([
                    'q' => "'{$carpetaId}' in parents and trashed = false",
                    'fields' => 'nextPageToken, files(id, name, mimeType, size, md5Checksum, modifiedTime)',
                    'pageSize' => 1000,
                    'pageToken' => $pagina,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ]))->throw()->json();

                foreach ($r['files'] ?? [] as $f) {
                    if ($f['mimeType'] === self::CARPETA) {
                        $pendientes[] = [$f['id'], $ruta ? "$ruta/{$f['name']}" : $f['name']];
                    } elseif ($f['mimeType'] === 'application/pdf') {
                        $archivos[] = new ArchivoPlano(
                            id: $f['id'],
                            nombre: $f['name'],
                            carpeta: $ruta,
                            tamano: isset($f['size']) ? (int) $f['size'] : null,
                            huella: $f['md5Checksum'] ?? $f['modifiedTime'] ?? null,
                            modificado: $f['modifiedTime'] ?? null,
                        );
                    }
                }
                $pagina = $r['nextPageToken'] ?? null;
            } while ($pagina);
        }

        return $archivos;
    }

    public function descargar(string $id): string
    {
        return $this->http()->timeout(120)
            ->get(self::API.'/files/'.rawurlencode($id), ['alt' => 'media', 'supportsAllDrives' => 'true'])
            ->throw()
            ->body();
    }

    /** Cliente HTTP ya autenticado. */
    private function http()
    {
        return Http::withToken($this->token())->acceptJson()->timeout(30)->retry(2, 500, throw: false);
    }

    /** Token de acceso. Se pide una vez y se reutiliza 55 minutos. */
    private function token(): string
    {
        return Cache::remember('drive_token', now()->addMinutes(55), function () {
            $cred = $this->credenciales();
            $ahora = time();

            // JWT = base64url(encabezado) . base64url(datos) . base64url(firma)
            $encabezado = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $datos = $this->b64(json_encode([
                'iss' => $cred['client_email'],
                'scope' => 'https://www.googleapis.com/auth/drive.readonly',
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

### `app/Planos/Fuentes/Fuente.php`

**NUEVO.**

Una "fábrica": `Fuente::actual()` regresa Drive o local según `PLANOS_FUENTE`; `Fuente::para('DRIVE')` según el origen de un plano ya guardado.

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
            'DRIVE' => new FuenteDrive(config('sula.planos.drive_credenciales'), (string) config('sula.planos.drive_carpeta')),
            'LOCAL' => new FuenteLocal(config('sula.planos.carpeta_local')),
            default => throw new InvalidArgumentException("Fuente de planos desconocida: $origen (usa drive o local)"),
        };
    }
}
````

### `app/Planos/NombreArchivo.php`

**NUEVO.**

- Busca la clave con una expresión regular (`2-1-0072-0003`). Si el nombre no la trae (como el exhibidor), usa el nombre limpio en mayúsculas.
- La revisión acepta `REV-01`, `REV 1`, `rev_3` → `REV-01`, `REV-03`.
- Si las claves de algún cliente son distintas, se cambia `PLANOS_PATRON_CLAVE` sin tocar código.

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
}
````
