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

<!-- SPEC SULA Core · MÓDULO 3 · PARTE 2 DE 5 -->
> **PARTE 2 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte2.md` y responde solo "recibida 2/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Planos/Sincronizador.php`

**NUEVO.**

- Compara lo que hay en la fuente contra la tabla: agrega nuevos, actualiza los que cambiaron (huella o nombre) y marca `activo = false` los que desaparecieron.
- Todo dentro de una **transacción**: o se guarda todo, o nada.
- Las variables con `&` (`&$nuevos`) se pasan "por referencia" para que la función de adentro pueda sumarles.

````php
<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\FuentePlanos;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día la tabla "planos" con lo que hay en la fuente (Drive o local).
 *   - Archivos nuevos → se agregan.
 *   - Archivos que cambiaron → se actualizan (nombre, huella, fecha).
 *   - Archivos que ya no están → activo = false (no se borran: pueden tener despiece).
 */
class Sincronizador
{
    /** @return array{nuevos: int, actualizados: int, retirados: int, total: int} */
    public static function sincronizar(FuentePlanos $fuente): array
    {
        $archivos = $fuente->listar();
        $origen = $fuente->origen();
        $patron = config('sula.planos.patron_clave') ?: NombreArchivo::PATRON_CLAVE;

        $existentes = Plano::where('origen', $origen)->get()->keyBy('archivo_id');
        $vistos = [];
        $nuevos = $actualizados = 0;

        DB::transaction(function () use ($archivos, $origen, $patron, $existentes, &$vistos, &$nuevos, &$actualizados) {
            foreach ($archivos as $a) {
                $vistos[$a->id] = true;
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

                $plano = $existentes[$a->id] ?? null;
                if (! $plano) {
                    Plano::create($datos + ['origen' => $origen, 'archivo_id' => $a->id]);
                    $nuevos++;
                } elseif ($plano->huella !== $a->huella || $plano->nombre !== $a->nombre || ! $plano->activo) {
                    $plano->update($datos);
                    $actualizados++;
                } else {
                    $plano->update(['sincronizado_en' => now()]);
                }
            }
        });

        // Los que ya no aparecen se marcan como retirados
        $retirados = Plano::where('origen', $origen)->where('activo', true)
            ->whereNotIn('archivo_id', array_keys($vistos) ?: [''])
            ->update(['activo' => false]);

        return ['nuevos' => $nuevos, 'actualizados' => $actualizados, 'retirados' => $retirados, 'total' => count($archivos)];
    }
}
````

### `app/Planos/AlmacenPdf.php`

**NUEVO.**

- La primera vez baja el PDF de Drive a `storage/app/private/planos/` (fuera de git y fuera de `public`). Las siguientes veces lo lee de ahí: el visor abre al instante.
- El nombre incluye la huella: si el plano cambia en Drive, se baja la versión nueva y se borra la vieja.
- Revisa que lo descargado empiece con `%PDF` (que sí sea un PDF).

````php
<?php

namespace App\Planos;

use App\Models\Plano;
use App\Planos\Fuentes\Fuente;

/**
 * Copia local de los PDFs (storage/app/private/planos, fuera de git).
 * Se baja de Drive la primera vez y se reutiliza mientras el archivo no cambie
 * (si cambia la "huella" en Drive, se vuelve a bajar).
 */
class AlmacenPdf
{
    public static function ruta(Plano $plano): string
    {
        $dir = storage_path('app/private/planos/'.strtolower($plano->origen));
        $prefijo = sha1($plano->archivo_id);
        $ruta = "$dir/$prefijo-".substr(sha1((string) $plano->huella), 0, 12).'.pdf';

        if (is_file($ruta)) {
            return $ruta;
        }

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        // Borra copias viejas de este mismo plano
        foreach (glob("$dir/$prefijo-*.pdf") ?: [] as $vieja) {
            @unlink($vieja);
        }

        $bytes = Fuente::para($plano->origen)->descargar($plano->archivo_id);
        if (! str_starts_with($bytes, '%PDF')) {
            throw new \RuntimeException('Lo que llegó de la fuente no es un PDF.');
        }
        file_put_contents($ruta, $bytes);

        return $ruta;
    }
}
````

### `app/Planos/ExtractorPdf.php`

**NUEVO.**

- `Process::run([...])` ejecuta programas de Poppler **sin pasar por el shell** (cada argumento va separado), así un nombre de archivo raro no puede inyectar comandos.
- `pdftotext -layout`: respeta columnas; las tablas del plano salen legibles.
- `pdftoppm -jpeg -scale-to 1600`: imagen de la página con el lado mayor de 1600 px (~300 KB), suficiente para leer cotas.
- `finally` borra los archivos temporales aunque algo falle.

````php
<?php

namespace App\Planos;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Saca texto e imágenes de las páginas de un PDF con Poppler
 * (pdfinfo, pdftotext, pdftoppm). En Arch: sudo pacman -S poppler
 *
 * Los planos de SolidWorks traen texto (tablas, materiales, cotas),
 * así que leer el texto es rápido y barato. Lo que solo está dibujado
 * se manda como imagen a la IA de visión.
 */
class ExtractorPdf
{
    public function __construct(private string $ruta) {}

    public function paginas(): int
    {
        $salida = $this->correr(['pdfinfo', $this->ruta]);
        if (! preg_match('/^Pages:\s+(\d+)/m', $salida, $m)) {
            throw new RuntimeException('No se pudo leer el número de páginas del PDF.');
        }

        return (int) $m[1];
    }

    /** Texto de una página respetando columnas (-layout), sin renglones vacíos repetidos. */
    public function texto(int $pagina): string
    {
        $t = $this->correr(['pdftotext', '-layout', '-f', $pagina, '-l', $pagina, $this->ruta, '-']);
        $t = preg_replace('/[ \t]+$/m', '', $t);

        return trim(preg_replace("/\n{3,}/", "\n\n", $t));
    }

    /** Imagen JPEG de una página (lado mayor 1600 px), en base64 lista para la IA. */
    public function imagenBase64(int $pagina): string
    {
        $base = tempnam(sys_get_temp_dir(), 'plano');
        try {
            $this->correr(['pdftoppm', '-jpeg', '-jpegopt', 'quality=80', '-scale-to', '1600',
                '-f', $pagina, '-l', $pagina, '-singlefile', $this->ruta, $base]);

            return base64_encode(file_get_contents("$base.jpg"));
        } finally {
            @unlink($base);
            @unlink("$base.jpg");
        }
    }

    private function correr(array $comando): string
    {
        $r = Process::timeout(120)->run(array_map('strval', $comando));
        if ($r->failed()) {
            $err = trim($r->errorOutput());
            if (str_contains($err, 'not found') || $r->exitCode() === 127) {
                throw new RuntimeException("Falta Poppler ({$comando[0]}). Instálalo: sudo pacman -S poppler");
            }
            throw new RuntimeException("{$comando[0]} falló: $err");
        }

        return $r->output();
    }
}
````

## 8. LA IA

### `app/Ia/ErrorIa.php`

**NUEVO.**

Excepción propia: así se distingue un error de la IA (que se le muestra al usuario) de un error de programación.

````php
<?php

namespace App\Ia;

use RuntimeException;

/** La IA no respondió o respondió algo que no sirve. El mensaje se le muestra al usuario. */
class ErrorIa extends RuntimeException {}
````

### `app/Ia/Groq.php`

**NUEVO.**

- Groq usa el **mismo formato que OpenAI** (`/openai/v1/chat/completions`), así que basta el cliente HTTP de Laravel.
- `response_format: json_object` obliga al modelo a responder JSON. `temperature 0.1` lo hace consistente (menos "creativo").
- **Con imágenes**, las instrucciones se pegan al texto del usuario porque algunos modelos de visión no aceptan mensaje de sistema junto con imágenes.
- **Reintentos:** 429 (límite por minuto) → espera lo que diga `retry-after` (máx. 65 s); `json_validate_failed` → reintenta; sin conexión → espera y reintenta. Máximo 4 intentos.
- **413** = la petición no cabe en tu plan: el mensaje dice qué variable bajar.
- Cada intento queda en `ia_llamadas` (tokens, milisegundos, error).
- `sinMarkdown()`: algunos modelos envuelven el JSON en ```` ```json ````; se lo quitamos.

````php
<?php

namespace App\Ia;

use App\Models\IaLlamada;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente mínimo de Groq (API compatible con OpenAI).
 *
 *  - Pide la respuesta en JSON ("json_object") y la regresa ya decodificada.
 *  - Si Groq dice 429 (límite por minuto), espera lo que indique y reintenta.
 *  - Registra cada llamada en ia_llamadas (tokens, tiempo, error).
 *
 * La API key y los modelos vienen del .env; los modelos de Groq cambian seguido,
 * por eso NO están escritos en el código.
 */
class Groq
{
    private const URL = 'https://api.groq.com/openai/v1/chat/completions';

    private const INTENTOS = 4;

    /**
     * @param  string  $sistema  instrucciones fijas
     * @param  string  $usuario  el texto a analizar
     * @param  string[]  $imagenes  JPEGs en base64 (solo modelos con visión)
     * @param  array  $bitacora  ['despiece_id' => …, 'paso' => 'TEXTO', 'paginas' => '1-3']
     */
    public static function json(string $modelo, string $sistema, string $usuario, array $imagenes = [], array $bitacora = []): array
    {
        $clave = config('sula.groq.api_key');
        if (! $clave) {
            throw new ErrorIa('Falta GROQ_API_KEY en el .env.');
        }

        $mensajes = [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $usuario]];
        if ($imagenes) {
            // Mensaje "multimodal": texto + imágenes en data URL.
            // Algunos modelos de visión no aceptan mensaje de sistema junto con imágenes,
            // por eso las instrucciones van pegadas al inicio del texto.
            $contenido = [['type' => 'text', 'text' => $sistema."\n\n".$usuario]];
            foreach ($imagenes as $img) {
                $contenido[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.$img]];
            }
            $mensajes = [['role' => 'user', 'content' => $contenido]];
        }

        $cuerpo = [
            'model' => $modelo,
            'temperature' => 0.1,   // bajo = respuestas más consistentes, menos "creativas"
            // Lo máximo que puede contestar. Ojo: Groq lo cuenta contra tu límite de tokens por minuto.
            'max_tokens' => (int) ($imagenes ? config('sula.groq.max_tokens_vision', 2000) : config('sula.groq.max_tokens', 3000)),
            'response_format' => ['type' => 'json_object'],
            'messages' => $mensajes,
        ];

        $ultimoError = 'sin respuesta';
        for ($intento = 1; $intento <= self::INTENTOS; $intento++) {
            $inicio = hrtime(true);
            try {
                $r = Http::withToken($clave)->acceptJson()->timeout(180)->post(self::URL, $cuerpo);
            } catch (ConnectionException $e) {
                $ultimoError = 'No hay conexión con Groq: '.$e->getMessage();
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);
                sleep(3 * $intento);

                continue;
            }

            // 429 = te pasaste del límite por minuto: esperar y reintentar
            if ($r->status() === 429) {
                $espera = min(65, max(2, (int) ceil((float) ($r->header('retry-after') ?: 10 * $intento))));
                $ultimoError = "Límite de Groq alcanzado (429), se esperó {$espera}s";
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);
                sleep($espera);

                continue;
            }

            // 400 json_validate_failed = el modelo escribió JSON roto: se vuelve a intentar
            if ($r->status() === 400 && $r->json('error.code') === 'json_validate_failed') {
                $ultimoError = 'La IA escribió un JSON inválido';
                self::registrar($bitacora, $modelo, $inicio, false, $ultimoError);

                continue;
            }

            if ($r->failed()) {
                $mensaje = $r->json('error.message') ?? $r->body();
                self::registrar($bitacora, $modelo, $inicio, false, "HTTP {$r->status()}: $mensaje");
                // 413 = la petición es más grande de lo que permite tu plan
                $pista = $r->status() === 413 ? ' (la petición es muy grande para tu plan de Groq: baja PLANOS_CARACTERES_POR_LOTE o PLANOS_IMAGENES_POR_LOTE)' : '';
                throw new ErrorIa("Groq respondió {$r->status()}: $mensaje$pista");
            }

            $texto = $r->json('choices.0.message.content') ?? '';
            $datos = json_decode(self::sinMarkdown($texto), true);
            self::registrar($bitacora, $modelo, $inicio, is_array($datos), is_array($datos) ? null : 'JSON inválido',
                (int) $r->json('usage.prompt_tokens', 0), (int) $r->json('usage.completion_tokens', 0));

            if (is_array($datos)) {
                return $datos;
            }
            $ultimoError = 'La IA no regresó un JSON válido';
        }

        throw new ErrorIa("Groq no respondió bien después de ".self::INTENTOS." intentos: $ultimoError");
    }

    /** Algunos modelos envuelven el JSON en ```json … ``` aunque se les pida que no. */
    public static function sinMarkdown(string $texto): string
    {
        $texto = trim($texto);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $texto, $m)) {
            return $m[1];
        }

        return $texto;
    }

    private static function registrar(array $b, string $modelo, int $inicio, bool $ok, ?string $error, int $entrada = 0, int $salida = 0): void
    {
        IaLlamada::create([
            'despiece_id' => $b['despiece_id'] ?? null,
            'paso' => $b['paso'] ?? 'OTRO',
            'paginas' => $b['paginas'] ?? null,
            'modelo' => $modelo,
            'tokens_entrada' => $entrada,
            'tokens_salida' => $salida,
            'milisegundos' => (int) ((hrtime(true) - $inicio) / 1_000_000),
            'ok' => $ok,
            'error' => $error,
        ]);
    }
}
````

### `app/Planos/Prompts.php`

**NUEVO.**

- `FORMA`: el JSON exacto que esperamos. Darle un ejemplo al modelo es lo que más mejora la respuesta.
- `REGLAS`: lo que un dibujante de SULA sabe: renglones con "VARIOS" son subensambles, cantidad por subensamble, leyenda de materiales, mm, **null si no lo ves**.
- `resumenBorrador()`: en la pasada de dibujos no se reenvía todo el JSON, solo un resumen corto, para gastar menos tokens.
- Si la IA se equivoca seguido en algo, **aquí se ajusta** sin tocar la lógica.

````php
<?php

namespace App\Planos;

/**
 * Las instrucciones que se le dan a la IA. Están aparte para poder
 * mejorarlas sin tocar la lógica. Regla de oro: NO INVENTAR datos.
 */
final class Prompts
{
    /** Forma exacta del JSON que pedimos (la misma para texto y para dibujos). */
    private const FORMA = <<<'JSON'
{
  "plano": {"modelo": "", "descripcion": "", "cliente": "", "revision": "", "acabado": ""},
  "leyenda": [{"codigo": "ML2", "material": "MELAMINA ROBLE (SMA) 25mm"}],
  "subensambles": [
    {"nombre": "GABINETE", "cantidad": 3, "pagina": 1, "notas": "",
     "piezas": [
       {"descripcion": "COSTADO", "material": "ML1", "cantidad": 2, "largo_mm": 669, "ancho_mm": 333,
        "pintura": null, "pagina": 2, "notas": ""}
     ]}
  ],
  "herrajes": [{"descripcion": "BISAGRA RECTA CAZOLETA 35mm", "cantidad": 12, "material": ""}]
}
JSON;

    private const REGLAS = <<<'TXT'
Eres ingeniero de manufactura en SULA, fábrica mexicana de mobiliario comercial
(góndolas, exhibidores, vitrinas, mesas) de metal (tubo, lámina) y melamina.
Lees planos de SolidWorks y armas el DESPIECE: mueble → subensambles → piezas.

REGLAS
1. Responde SOLO con JSON válido con esta forma exacta (sin texto extra, sin markdown):
FORMA
2. NO INVENTES. Si un dato no aparece, pon null. Es mejor null que un número inventado.
3. "leyenda": la tabla MATERIALES / ACABADOS del cuadro de datos (código → nombre).
4. "subensambles": cada renglón de la tabla general (NO. | DESCRIPCION | MATERIAL | CANTIDAD | LARGO | ANCHO)
   cuyo material sea "VARIOS" es un subensamble; su "cantidad" es cuántos lleva UN mueble.
   Las piezas que van directo al mueble (con material concreto) van en el subensamble "ENSAMBLE GENERAL" con cantidad 1,
   y la cantidad de cada pieza es la del renglón.
5. La "cantidad" de una pieza es cuántas lleva UN subensamble (no el total del mueble).
6. "material": copia el código de la leyenda (ML2, LM1, TC1) o el texto exacto del plano.
7. Medidas en milímetros. Si la acotación del plano está en pulgadas, conviértelas (× 25.4).
   "largo_mm" es la medida mayor y "ancho_mm" la menor. Tubos y perfiles solo llevan largo.
8. "pintura": para tubo, el perímetro pintable en mm; para lámina, las caras pintadas (1 o 2);
   para melamina, vidrio y herrajes, null. Si no estás seguro, null.
9. "herrajes": tornillería, bisagras, jaladeras, soportes, niveladores, etc., con la cantidad POR MUEBLE.
10. Usa nombres en MAYÚSCULAS como vienen en el plano. Pon en "pagina" el número de página donde lo viste.
TXT;

    public static function sistema(): string
    {
        return str_replace('FORMA', self::FORMA, self::REGLAS);
    }

    /** Pasada 1: solo texto de un grupo de páginas. */
    public static function texto(string $paginas, string $texto, int $total): string
    {
        return "Texto extraído de las páginas {$paginas} (de {$total}) del plano. "
            ."Las columnas pueden venir desalineadas: usa el sentido común para juntar descripción, material, cantidad y medidas.\n\n"
            ."=== TEXTO ===\n{$texto}\n=== FIN ===";
    }

    /** Pasada 2: imágenes de páginas + lo que ya se tiene, para completar lo que falta. */
    public static function dibujos(string $paginas, string $texto, string $borrador): string
    {
        return "Te mando las imágenes de las páginas {$paginas} del plano y su texto.\n"
            ."Ya tenemos este despiece BORRADOR (resumido). Las piezas con null necesitan datos:\n"
            ."{$borrador}\n\n"
            ."Mira las vistas y cotas de las imágenes y regresa, con la misma forma JSON, SOLO:\n"
            ."- las piezas del borrador a las que les puedas completar datos (usa el MISMO nombre de subensamble y de pieza), y\n"
            ."- las piezas que se ven en estas páginas y faltan en el borrador.\n"
            ."No repitas piezas que ya están completas. Si no hay nada que agregar, regresa {\"subensambles\": []}.\n\n"
            ."=== TEXTO DE ESAS PÁGINAS ===\n{$texto}\n=== FIN ===";
    }

    /** Resumen compacto del borrador para no gastar tokens (solo lo que hace falta). */
    public static function resumenBorrador(array $doc, int $maxCaracteres = 3500): string
    {
        $lineas = [];
        foreach ($doc['subensambles'] as $s) {
            $lineas[] = "[{$s['nombre']} ×".($s['cantidad'] ?? 'null').']';
            foreach ($s['piezas'] as $p) {
                $lineas[] = "  - {$p['descripcion']} | mat: ".($p['material_texto'] ?: 'null')
                    .' | cant: '.($p['cantidad'] ?? 'null')
                    .' | largo: '.($p['largo_mm'] ?? 'null')
                    .' | ancho: '.($p['ancho_mm'] ?? 'null');
            }
        }
        $texto = implode("\n", $lineas);

        return mb_strlen($texto) > $maxCaracteres ? mb_substr($texto, 0, $maxCaracteres)."\n  …(recortado)" : $texto;
    }
}
````

### `app/Planos/Normalizador.php`

**NUEVO.**

Es la pieza que hace que "salga correcto". **No toca la base de datos**, por eso tiene pruebas unitarias.
- `normalizar()`: acepta variaciones de la IA (`piezas` o `componentes`, `"1,800"`, `"37 mm"`, `"VARIOS"` → null) y deja todo en una forma fija. Números como **texto** (nunca `float`).
- `fusionar()`: junta dos lecturas. **Lo primero manda**: de la segunda solo se toma lo que falta o lo nuevo. Compara nombres sin acentos ni mayúsculas ("Gabinete" = "GABINETE").
- `resolver()`: código de leyenda → nombre (ML2 → MELAMINA ROBLE (SMA) 25MM) → material del catálogo (por clave o alias; también tolera espacios: "C.11" = "C. 11"). Pone avisos:
  - 🔴 material que no está en el catálogo · falta largo (tubo) · faltan largo y ancho (lámina, melamina) · cantidad no leída
  - ⚪ pintura estimada: tubo cuadrado 1" → 4 × 25.4 = **101.6 mm** (igual que el Excel); lámina → 2 caras
- `explotar()`: subensamble × pieza = cantidad **por mueble**, con `BigDecimal` (sin errores de redondeo).

````php
<?php

namespace App\Planos;

use App\Costeo\Dec;

/**
 * Convierte lo que responde la IA en un despiece limpio y confiable.
 * NO toca la base de datos (por eso se puede probar sin Laravel).
 *
 *   normalizar() → acomoda el JSON de la IA a una forma fija (números como texto, nombres limpios)
 *   fusionar()   → junta dos lecturas (otro grupo de páginas, o la pasada con dibujos)
 *   resolver()   → material del plano → material del catálogo, estima pintura y pone avisos
 *   explotar()   → subensambles × piezas = renglones POR MUEBLE para la cotización
 */
final class Normalizador
{
    public const SIN_SUBENSAMBLE = 'ENSAMBLE GENERAL';

    // ------------------------------------------------------------------
    // 1. NORMALIZAR
    // ------------------------------------------------------------------

    public static function normalizar(array $crudo): array
    {
        $plano = (array) ($crudo['plano'] ?? []);
        $doc = [
            'plano' => [
                'modelo' => self::textoONulo($plano['modelo'] ?? null),
                'descripcion' => self::textoONulo($plano['descripcion'] ?? null),
                'cliente' => self::textoONulo($plano['cliente'] ?? null),
                'revision' => self::textoONulo($plano['revision'] ?? null),
                'acabado' => self::textoONulo($plano['acabado'] ?? null),
            ],
            'leyenda' => [],
            'subensambles' => [],
        ];

        // Leyenda de materiales del plano: "ML2" → "MELAMINA ROBLE (SMA) 25MM"
        foreach ((array) ($crudo['leyenda'] ?? []) as $k => $l) {
            if (is_array($l)) {
                $codigo = self::llaveMaterial($l['codigo'] ?? '');
                $texto = self::llaveMaterial($l['material'] ?? $l['nombre'] ?? '');
            } else {
                $codigo = self::llaveMaterial((string) $k);
                $texto = self::llaveMaterial((string) $l);
            }
            if ($codigo !== '' && $texto !== '' && $codigo !== $texto) {
                $doc['leyenda'][$codigo] = $texto;
            }
        }

        foreach ((array) ($crudo['subensambles'] ?? []) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $sub = [
                'nombre' => mb_strtoupper(self::texto($s['nombre'] ?? $s['descripcion'] ?? '')) ?: self::SIN_SUBENSAMBLE,
                'cantidad' => self::numero($s['cantidad'] ?? $s['cantidad_por_unidad'] ?? null),
                'pagina' => self::entero($s['pagina'] ?? $s['pagina_plano'] ?? null),
                'notas' => self::texto($s['notas'] ?? ''),
                'piezas' => [],
            ];
            foreach ((array) ($s['piezas'] ?? $s['componentes'] ?? []) as $p) {
                if (is_array($p) && ($pieza = self::pieza($p))) {
                    $sub['piezas'][] = $pieza;
                }
            }
            $doc = self::agregarSubensamble($doc, $sub);
        }

        // Herraje suelto (por mueble) → subensamble "HERRAJE DE ARMADO"
        $herrajes = [];
        foreach ((array) ($crudo['herrajes'] ?? $crudo['herraje_armado'] ?? []) as $h) {
            if (is_array($h) && ($pieza = self::pieza($h + ['material' => $h['material'] ?? $h['nombre'] ?? $h['descripcion'] ?? '']))) {
                $herrajes[] = $pieza;
            }
        }
        if ($herrajes) {
            $doc = self::agregarSubensamble($doc, ['nombre' => 'HERRAJE DE ARMADO', 'cantidad' => '1', 'pagina' => null, 'notas' => '', 'piezas' => $herrajes]);
        }

        return $doc;
    }

    private static function pieza(array $p): ?array
    {
        $descripcion = self::texto($p['descripcion'] ?? $p['nombre'] ?? '');
        if ($descripcion === '') {
            return null;
        }

        return [
            'descripcion' => $descripcion,
            'material_texto' => self::llaveMaterial($p['material'] ?? ''),
            'cantidad' => self::numero($p['cantidad'] ?? $p['cantidad_por_subensamble'] ?? null),
            'largo_mm' => self::numero($p['largo_mm'] ?? $p['largo'] ?? null),
            'ancho_mm' => self::numero($p['ancho_mm'] ?? $p['ancho'] ?? null),
            'pintura' => self::numero($p['pintura'] ?? null),
            'pagina' => self::entero($p['pagina'] ?? $p['pagina_plano'] ?? null),
            'notas' => self::texto($p['notas'] ?? ''),
        ];
    }

    // ------------------------------------------------------------------
    // 2. FUSIONAR: lo que ya teníamos ($a) + una lectura nueva ($b)
    //    Lo de $a manda; de $b solo se toma lo que a $a le falta o lo nuevo.
    // ------------------------------------------------------------------

    public static function fusionar(array $a, array $b): array
    {
        foreach ($b['plano'] ?? [] as $k => $v) {
            if (($a['plano'][$k] ?? null) === null && $v !== null) {
                $a['plano'][$k] = $v;
            }
        }
        $a['leyenda'] = ($a['leyenda'] ?? []) + ($b['leyenda'] ?? []);
        foreach ($b['subensambles'] ?? [] as $sub) {
            $a = self::agregarSubensamble($a, $sub);
        }

        return $a;
    }

    /** Agrega un subensamble; si ya existe uno con el mismo nombre, mezcla sus piezas. */
    private static function agregarSubensamble(array $doc, array $sub): array
    {
        foreach ($doc['subensambles'] as $i => $existente) {
            if (self::llave($existente['nombre']) !== self::llave($sub['nombre'])) {
                continue;
            }
            $e = &$doc['subensambles'][$i];
            $e['cantidad'] ??= $sub['cantidad'];
            $e['pagina'] ??= $sub['pagina'];
            if ($e['notas'] === '') {
                $e['notas'] = $sub['notas'];
            }
            foreach ($sub['piezas'] as $p) {
                $e['piezas'] = self::agregarPieza($e['piezas'], $p);
            }
            unset($e);

            return $doc;
        }
        // Subensamble nuevo (sin repetir piezas dentro de él)
        $piezas = [];
        foreach ($sub['piezas'] as $p) {
            $piezas = self::agregarPieza($piezas, $p);
        }
        $sub['piezas'] = $piezas;
        $doc['subensambles'][] = $sub;

        return $doc;
    }

    private static function agregarPieza(array $piezas, array $p): array
    {
        foreach ($piezas as $i => $e) {
            if (self::llave($e['descripcion']) === self::llave($p['descripcion'])) {
                foreach (['cantidad', 'largo_mm', 'ancho_mm', 'pintura', 'pagina'] as $k) {
                    $piezas[$i][$k] ??= $p[$k];
                }
                if ($e['material_texto'] === '') {
                    $piezas[$i]['material_texto'] = $p['material_texto'];
                }
                if ($p['notas'] !== '' && ! str_contains($e['notas'], $p['notas'])) {
                    $piezas[$i]['notas'] = trim($e['notas'].' · '.$p['notas'], ' ·');
                }

                return $piezas;
            }
        }
        $piezas[] = $p;

        return $piezas;
    }

    // ------------------------------------------------------------------
    // 3. RESOLVER contra el catálogo
    // ------------------------------------------------------------------

    /**
     * @param  array  $diccionario  llaveMaterial(texto) → material_id  (claves M01… y alias)
     * @param  array  $materiales  material_id → ['tipo' => 'LINEAL', 'categoria' => 'METAL', 'descripcion' => '…']
     */
    public static function resolver(array $doc, array $diccionario, array $materiales): array
    {
        // Versión "sin espacios" del diccionario: tolera "LAMINA CRS C.11" vs "LAMINA CRS C. 11"
        $compacto = [];
        foreach ($diccionario as $texto => $id) {
            $compacto[self::compacta($texto)] = $id;
        }

        foreach ($doc['subensambles'] as $si => $sub) {
            if ($sub['cantidad'] === null || (float) $sub['cantidad'] <= 0) {
                $doc['subensambles'][$si]['cantidad'] = '1';
            }
            foreach ($sub['piezas'] as $pi => $p) {
                $doc['subensambles'][$si]['piezas'][$pi] = self::resolverPieza($p, $doc['leyenda'] ?? [], $diccionario, $compacto, $materiales);
            }
        }

        return $doc;
    }

    private static function resolverPieza(array $p, array $leyenda, array $dic, array $compacto, array $materiales): array
    {
        $avisos = [];

        // Si viene el código de la leyenda (ML2) se cambia por el nombre completo
        $texto = $p['material_texto'];
        if (isset($leyenda[$texto])) {
            $texto = $leyenda[$texto];
        } elseif (preg_match('/^([A-Z]{1,4}\d{1,3})\s*[-–:]?\s+(.+)$/u', $texto, $m) && isset($leyenda[$m[1]])) {
            $texto = $m[2]; // "ML2 - MELAMINA…" → "MELAMINA…"
        }
        $p['material_texto'] = $texto;

        $id = $p['material_id'] ?? null;
        $id ??= $dic[$texto] ?? $compacto[self::compacta($texto)] ?? null;
        $p['material_id'] = $id;

        if ($p['cantidad'] === null || (float) $p['cantidad'] <= 0) {
            $p['cantidad'] = '1';
            $avisos[] = ['nivel' => 'error', 'texto' => 'No se leyó la cantidad: se puso 1.'];
        }

        $mat = $id ? ($materiales[$id] ?? null) : null;
        if (! $mat) {
            $avisos[] = ['nivel' => 'error', 'texto' => $texto === ''
                ? 'Sin material: elige uno del catálogo.'
                : "«{$texto}» no está en el catálogo: elige el material y se guardará como alias."];
        }

        $tipo = $mat['tipo'] ?? null;
        $largo = $p['largo_mm'] ?? null;
        $ancho = $p['ancho_mm'] ?? null;
        if ($tipo === 'LINEAL' && ! self::positivo($largo)) {
            $avisos[] = ['nivel' => 'error', 'texto' => 'Falta el largo.'];
        }
        if (in_array($tipo, ['AREA', 'AREA_KG'], true) && (! self::positivo($largo) || ! self::positivo($ancho))) {
            $avisos[] = ['nivel' => 'error', 'texto' => 'Faltan medidas: se necesitan largo y ancho.'];
        }

        // Pintura: perímetro (tubo) o caras (lámina). Si la IA no la dio, se estima.
        $perimetro = '0';
        $caras = '0';
        if ($tipo === 'LINEAL') {
            $perimetro = $p['pintura'] ?? null;
            if ($perimetro === null && ($mat['categoria'] ?? '') === 'METAL') {
                $perimetro = self::perimetroTubo($texto.' '.($mat['descripcion'] ?? ''));
                $avisos[] = $perimetro !== null
                    ? ['nivel' => 'info', 'texto' => "Pintura estimada: perímetro {$perimetro} mm."]
                    : ['nivel' => 'info', 'texto' => 'Sin dato de pintura (perímetro).'];
            }
        } elseif (in_array($tipo, ['AREA', 'AREA_KG'], true)) {
            $caras = $p['pintura'] ?? null;
            if ($caras === null && ($mat['categoria'] ?? '') === 'METAL') {
                $caras = '2';
                $avisos[] = ['nivel' => 'info', 'texto' => 'Pintura estimada: 2 caras.'];
            }
        }

        $p['largo_mm'] = $largo ?? '0';
        $p['ancho_mm'] = $ancho ?? '0';
        $p['perimetro_pintable_mm'] = $perimetro ?? '0';
        $p['caras_pintables'] = $caras ?? '0';
        $p['avisos'] = $avisos;
        unset($p['pintura']);

        return $p;
    }

    /** Perímetro de un tubo según su medida en pulgadas: 1" cuadrado = 101.6 mm. */
    public static function perimetroTubo(string $texto): ?string
    {
        $t = mb_strtoupper($texto);
        $num = '(\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:\.\d+)?)';
        if (preg_match("/{$num}\s*\"\s*[X×]\s*{$num}\s*\"/u", $t, $m)) {
            $mm = Dec::de(self::pulgadas($m[1]))->plus(Dec::de(self::pulgadas($m[2])))->multipliedBy(2)->multipliedBy('25.4');

            return Dec::d4($mm);
        }
        if (preg_match("/{$num}\s*\"/u", $t, $m)) {
            $lado = Dec::de(self::pulgadas($m[1]))->multipliedBy('25.4');
            if (preg_match('/REDOND|CIRCULAR|CEDULA|CÉDULA/u', $t)) {
                return Dec::d4($lado->multipliedBy('3.14159265'));
            }
            if (preg_match('/CUADRAD|PTR|T-CUAD/u', $t)) {
                return Dec::d4($lado->multipliedBy(4));
            }
        }

        return null;
    }

    /** "1" → 1, "3/4" → 0.75, "1 1/2" → 1.5 */
    private static function pulgadas(string $s): string
    {
        $s = trim($s);
        if (preg_match('/^(\d+)\s+(\d+)\/(\d+)$/', $s, $m)) {
            return (string) ((int) $m[1] + (int) $m[2] / (int) $m[3]);
        }
        if (preg_match('/^(\d+)\/(\d+)$/', $s, $m)) {
            return (string) ((int) $m[1] / (int) $m[2]);
        }

        return $s;
    }

    // ------------------------------------------------------------------
    // 4. EXPLOTAR: renglones POR MUEBLE (lo que espera la cotización)
    // ------------------------------------------------------------------

    /** @param  array  $subensambles  [['nombre', 'cantidad', 'piezas' => [pieza resuelta…]]] */
    public static function explotar(array $subensambles): array
    {
        $filas = [];
        foreach ($subensambles as $sub) {
            $veces = Dec::de($sub['cantidad'] ?? '1');
            foreach ($sub['piezas'] as $p) {
                $filas[] = [
                    'subensamble' => $sub['nombre'],
                    'descripcion' => $p['descripcion'],
                    'material_id' => $p['material_id'],
                    'cantidad' => Dec::d4(Dec::de($p['cantidad'])->multipliedBy($veces)),
                    'largo_mm' => (string) ($p['largo_mm'] ?? '0'),
                    'ancho_mm' => (string) ($p['ancho_mm'] ?? '0'),
                    'perimetro_pintable_mm' => (string) ($p['perimetro_pintable_mm'] ?? '0'),
                    'caras_pintables' => (string) ($p['caras_pintables'] ?? '0'),
                    'notas' => (string) ($p['notas'] ?? ''),
                ];
            }
        }

        return $filas;
    }

    // ------------------------------------------------------------------
    // Utilidades
    // ------------------------------------------------------------------

    /** "2,000" → "2000" · "1800 mm" → "1800" · "0,25" → "0.25" · null/"—"/"VARIOS" → null */
    public static function numero(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            return $v > 0 ? Dec::d4(Dec::de($v)) : ($v == 0 ? '0' : null);
        }
        if (! is_string($v)) {
            return null;
        }
        $s = str_replace(' ', '', trim($v));
        if (preg_match('/^[1-9]\d{0,2}(,\d{3})+(?!\d)/', $s)) {
            $s = str_replace(',', '', $s);   // 1,800.5
        } else {
            $s = str_replace(',', '.', $s);  // 0,25
        }
        if (! preg_match('/^\d+(\.\d+)?/', $s, $m)) {
            return null;
        }

        return Dec::d4(Dec::de($m[0]));
    }

    /** Llave para comparar nombres: mayúsculas, sin acentos, espacios simples. */
    public static function llave(string $s): string
    {
        $s = strtr(mb_strtoupper(trim($s)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/\s+/', ' ', $s);
    }

    /** Así se guardan los alias del catálogo: MAYÚSCULAS y espacios simples (con acentos). */
    public static function llaveMaterial(mixed $s): string
    {
        return is_scalar($s) ? preg_replace('/\s+/', ' ', mb_strtoupper(trim((string) $s))) : '';
    }

    private static function compacta(string $s): string
    {
        return preg_replace('/[\s.]+/', '', self::llave($s));
    }

    private static function texto(mixed $v): string
    {
        return is_scalar($v) ? preg_replace('/\s+/', ' ', trim((string) $v)) : '';
    }

    private static function textoONulo(mixed $v): ?string
    {
        $t = self::texto($v);

        return $t === '' ? null : $t;
    }

    private static function entero(mixed $v): ?int
    {
        return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
    }

    private static function positivo(?string $v): bool
    {
        return $v !== null && (float) $v > 0;
    }
}
````

<!-- SPEC SULA Core · MÓDULO 3 · PARTE 3 DE 5 -->
> **PARTE 3 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte3.md` y responde solo "recibida 3/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Planos/LectorPlano.php`

**NUEVO.**

- Paso a paso: baja el PDF → texto de cada página → **lotes** de páginas (~5,000 caracteres para caber en el plan gratis) → Groq → `fusionar` lote por lote → (opcional) imágenes, una página por llamada → `resolver` con el catálogo → guarda.
- `$avance(...)` escribe en `despieces.progreso` lo que va haciendo; la pantalla lo muestra en vivo.
- Si el PDF no trae texto (un escaneo), avisa que se use "revisar también los dibujos".
- `catalogo()`: arma el diccionario clave/alias → id. También lo usa `FlujoDespiece::guardar`.
- `guardarEstructura()`: reemplaza subensambles y piezas del despiece (borrar + insertar dentro de la transacción).

````php
<?php

namespace App\Planos;

use App\Ia\ErrorIa;
use App\Ia\Groq;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\MaterialAlias;
use Illuminate\Support\Facades\DB;

/**
 * Lee un plano con la IA y deja el resultado en el despiece (estado BORRADOR).
 *
 *   1. Baja el PDF (o usa la copia local) y saca el texto de cada página.
 *   2. PASADA DE TEXTO: manda el texto en grupos de páginas → arma el despiece.
 *   3. PASADA DE DIBUJOS (opcional): manda imágenes de las páginas → completa medidas.
 *   4. Normaliza, cruza materiales con el catálogo y guarda subensambles y piezas.
 *
 * Corre dentro de un Job (en segundo plano) porque puede tardar minutos.
 */
class LectorPlano
{
    public static function leer(Despiece $despiece): void
    {
        $plano = $despiece->plano;
        $avance = fn (string $msg) => $despiece->update(['progreso' => $msg]);

        $avance('Descargando el PDF…');
        $pdf = new ExtractorPdf(AlmacenPdf::ruta($plano));
        $total = $pdf->paginas();

        $textos = [];
        for ($i = 1; $i <= $total; $i++) {
            $textos[$i] = $pdf->texto($i);
        }

        // ---------- Pasada 1: texto ----------
        $doc = Normalizador::normalizar([]);
        $lotes = self::lotesDeTexto($textos, (int) config('sula.planos.caracteres_por_lote', 5000));
        if (! $lotes) {
            throw new ErrorIa('El PDF no trae texto (¿es un escaneo?). Vuelve a leerlo marcando "Revisar también los dibujos".');
        }

        foreach ($lotes as $n => $paginas) {
            $rango = self::rango($paginas);
            $avance('Leyendo texto, páginas '.$rango.' de '.$total.' ('.($n + 1).'/'.count($lotes).')…');
            $crudo = Groq::json(
                config('sula.groq.modelo_texto'),
                Prompts::sistema(),
                Prompts::texto($rango, self::unirTextos($textos, $paginas), $total),
                [],
                ['despiece_id' => $despiece->id, 'paso' => 'TEXTO', 'paginas' => $rango],
            );
            $doc = Normalizador::fusionar($doc, Normalizador::normalizar($crudo));
        }

        // ---------- Pasada 2: dibujos (opcional) ----------
        if ($despiece->con_dibujos) {
            $porLote = max(1, (int) config('sula.planos.imagenes_por_lote', 1));
            $grupos = array_chunk(range(1, $total), $porLote);
            foreach ($grupos as $n => $paginas) {
                $rango = self::rango($paginas);
                $avance('Revisando dibujos, páginas '.$rango.' de '.$total.' ('.($n + 1).'/'.count($grupos).')…');
                $crudo = Groq::json(
                    config('sula.groq.modelo_vision'),
                    Prompts::sistema(),
                    Prompts::dibujos($rango, mb_substr(self::unirTextos($textos, $paginas), 0, 1500), Prompts::resumenBorrador($doc, 2500)),
                    array_map(fn ($p) => $pdf->imagenBase64($p), $paginas),
                    ['despiece_id' => $despiece->id, 'paso' => 'DIBUJOS', 'paginas' => $rango],
                );
                $doc = Normalizador::fusionar($doc, Normalizador::normalizar($crudo));
            }
        }

        if (! $doc['subensambles']) {
            throw new ErrorIa('La IA no encontró subensambles ni piezas en este plano.');
        }

        // ---------- Cruce con el catálogo y guardado ----------
        $avance('Cruzando materiales con el catálogo…');
        [$diccionario, $materiales] = self::catalogo();
        $doc = Normalizador::resolver($doc, $diccionario, $materiales);

        DB::transaction(function () use ($despiece, $doc) {
            self::guardarEstructura($despiece, $doc['subensambles']);
            $despiece->update([
                'estado' => 'BORRADOR',
                'progreso' => null,
                'error' => null,
                'datos' => ['plano' => $doc['plano'], 'leyenda' => $doc['leyenda']],
            ]);
        });
    }

    /** Reemplaza los subensambles y piezas de un despiece. */
    public static function guardarEstructura(Despiece $despiece, array $subensambles): void
    {
        $despiece->subensambles()->delete(); // las piezas se borran en cascada
        foreach (array_values($subensambles) as $i => $s) {
            $sub = $despiece->subensambles()->create([
                'orden' => $i + 1,
                'nombre' => mb_strtoupper(trim($s['nombre'])) ?: Normalizador::SIN_SUBENSAMBLE,
                'cantidad' => $s['cantidad'] ?: 1,
                'pagina' => $s['pagina'] ?? null,
                'notas' => trim((string) ($s['notas'] ?? '')) ?: null,
            ]);
            foreach (array_values($s['piezas']) as $j => $p) {
                $sub->piezas()->create([
                    'orden' => $j + 1,
                    'descripcion' => trim($p['descripcion']),
                    'material_texto' => trim((string) ($p['material_texto'] ?? '')) ?: null,
                    'material_id' => $p['material_id'] ?: null,
                    'cantidad' => $p['cantidad'] ?: 1,
                    'largo_mm' => $p['largo_mm'] ?: 0,
                    'ancho_mm' => $p['ancho_mm'] ?: 0,
                    'perimetro_pintable_mm' => $p['perimetro_pintable_mm'] ?: 0,
                    'caras_pintables' => $p['caras_pintables'] ?: 0,
                    'pagina' => $p['pagina'] ?? null,
                    'notas' => trim((string) ($p['notas'] ?? '')) ?: null,
                    'avisos' => $p['avisos'] ?? [],
                ]);
            }
        }
    }

    /**
     * Diccionario para reconocer materiales: clave del catálogo (M05) y alias (MELAMINA ROBLE (SMA) 15MM).
     * @return array{0: array<string,int>, 1: array<int,array>}
     */
    public static function catalogo(): array
    {
        $mats = Material::where('activo', true)->get(['id', 'clave', 'descripcion', 'tipo_calculo', 'categoria']);
        $diccionario = [];
        $materiales = [];
        foreach ($mats as $m) {
            $diccionario[Normalizador::llaveMaterial($m->clave)] = $m->id;
            $materiales[$m->id] = ['tipo' => $m->tipo_calculo, 'categoria' => $m->categoria, 'descripcion' => $m->descripcion];
        }
        foreach (MaterialAlias::all(['alias', 'material_id']) as $a) {
            if (isset($materiales[$a->material_id])) {
                $diccionario[Normalizador::llaveMaterial($a->alias)] = $a->material_id;
            }
        }

        return [$diccionario, $materiales];
    }

    /** Agrupa páginas seguidas hasta juntar ~N caracteres de texto. Las páginas sin texto se saltan. */
    public static function lotesDeTexto(array $textos, int $maxCaracteres): array
    {
        $lotes = [];
        $actual = [];
        $tamano = 0;
        foreach ($textos as $pagina => $texto) {
            $n = mb_strlen($texto);
            if ($n === 0) {
                continue;
            }
            if ($actual && $tamano + $n > $maxCaracteres) {
                $lotes[] = $actual;
                $actual = [];
                $tamano = 0;
            }
            $actual[] = $pagina;
            $tamano += $n;
        }
        if ($actual) {
            $lotes[] = $actual;
        }

        return $lotes;
    }

    private static function unirTextos(array $textos, array $paginas): string
    {
        return implode("\n\n", array_map(fn ($p) => "--- PÁGINA $p ---\n".$textos[$p], $paginas));
    }

    private static function rango(array $paginas): string
    {
        return count($paginas) === 1 ? (string) $paginas[0] : reset($paginas).'-'.end($paginas);
    }
}
````

## 9. REGLAS, COLA Y COMANDO

### `app/Planos/ErrorDespiece.php`

**NUEVO.**

Igual que `ErrorCotizacion` del Módulo 2: errores de negocio con mensaje para el usuario.

````php
<?php

namespace App\Planos;

use RuntimeException;

/** Regla de negocio del despiece que no se cumple. El mensaje se le muestra al usuario. */
class ErrorDespiece extends RuntimeException {}
````

### `app/Planos/FlujoDespiece.php`

**NUEVO.**

- `leerConIa()`: crea la versión en `LEYENDO` y **manda el trabajo a la cola** (`LeerPlano::dispatch`). Regresa al instante.
- `guardar()`: limpia lo que capturaste, vuelve a calcular avisos con el catálogo y reemplaza la estructura.
- `aprobar()`: bloquea si hay rojos; **registra alias** de lo que elegiste a mano; pasa el aprobado anterior a `REEMPLAZADO` (en ese orden, si no el índice único de la BD truena).
- `cotizar()`: crea la cotización en BORRADOR y le mete las piezas **explotadas** usando `Flujo::guardarPiezas` del Módulo 2 (se reutiliza, no se duplica).
- `nuevaVersion()` usa `Despiece::where(...)->max('version')` sin `orderBy` (PostgreSQL no permite ORDER BY en un MAX sin GROUP BY).

````php
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
                if ($alias !== '' && $p->material_id && ! MaterialAlias::find($alias)) {
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
````

### `app/Jobs/LeerPlano.php`

**NUEVO.**

- `ShouldQueue` = no se ejecuta en la petición, sino en `php artisan queue:work`.
- `$timeout = 900` (15 min) y `$tries = 1`: si falla, tú decides si lo vuelves a leer (no se gasta IA sola).
- En la cola no corre el middleware del journal: por eso firma a mano como `ia-groq`.
- `failed()`: si el trabajo muere (por tiempo), deja el despiece en `ERROR` para que no se quede "Leyendo…" para siempre.

````php
<?php

namespace App\Jobs;

use App\Models\Despiece;
use App\Planos\LectorPlano;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Trabajo en segundo plano: leer un plano con la IA.
 * Lo ejecuta "php artisan queue:work" (otra terminal), así la pantalla
 * no se congela mientras Groq trabaja; la pantalla solo va preguntando el avance.
 */
class LeerPlano implements ShouldQueue
{
    use Queueable;

    /** Máximo 15 minutos (planos grandes + esperas por el límite de Groq). */
    public int $timeout = 900;

    /** Un solo intento: si falla, el usuario decide si lo vuelve a leer. */
    public int $tries = 1;

    public function __construct(public int $despieceId) {}

    public function handle(): void
    {
        // En la cola no pasa el middleware: firmamos el journal a mano
        DB::statement("SELECT set_config('app.usuario', ?, false)", ['ia-groq']);

        $despiece = Despiece::with('plano')->find($this->despieceId);
        if (! $despiece || $despiece->estado !== 'LEYENDO') {
            return;
        }

        try {
            LectorPlano::leer($despiece);
        } catch (Throwable $e) {
            $despiece->update(['estado' => 'ERROR', 'progreso' => null, 'error' => mb_substr($e->getMessage(), 0, 1000)]);
            report($e); // queda también en storage/logs/laravel.log
        }
    }

    /** Laravel lo llama si el trabajo muere (por ejemplo, se pasó del tiempo). */
    public function failed(?Throwable $e): void
    {
        Despiece::where('id', $this->despieceId)->where('estado', 'LEYENDO')->update([
            'estado' => 'ERROR',
            'progreso' => null,
            'error' => 'La lectura se detuvo: '.mb_substr($e?->getMessage() ?? 'tiempo agotado', 0, 900),
        ]);
    }
}
````

### `app/Console/Commands/SincronizarPlanos.php`

**NUEVO.**

`php artisan sula:planos` (o `--fuente=local`). Úsalo para la **primera** sincronización con Drive: en la terminal no hay límite de 30 s como en el navegador.

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

    protected $description = 'Sincroniza el índice de planos con Google Drive o la carpeta local';

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

        $this->table(['PDFs encontrados', 'Nuevos', 'Actualizados', 'Retirados'], [[$r['total'], $r['nuevos'], $r['actualizados'], $r['retirados']]]);

        return self::SUCCESS;
    }
}
````

### `app/Http/Controllers/PdfPlanoController.php`

**NUEVO.**

- `__invoke`: controlador de una sola acción.
- Solo sirve planos que están en la tabla (Laravel busca el `{plano}` por id; si no existe, 404). Nadie puede usar la ruta para bajar cualquier archivo de tu Drive.
- `inline` = el navegador lo muestra con su visor; `?descargar=1` = lo descarga.

````php
<?php

namespace App\Http\Controllers;

use App\Models\Plano;
use App\Planos\AlmacenPdf;
use Throwable;

/**
 * GET /planos/{plano}/pdf            → muestra el PDF en el navegador
 * GET /planos/{plano}/pdf?descargar=1 → lo descarga
 * Solo sirve planos que están en la tabla (nadie puede pedir cualquier archivo de Drive).
 */
class PdfPlanoController
{
    public function __invoke(Plano $plano)
    {
        try {
            $ruta = AlmacenPdf::ruta($plano);
        } catch (Throwable $e) {
            abort(502, 'No se pudo traer el plano: '.$e->getMessage());
        }

        $nombre = str_replace('"', '', $plano->nombre);
        $modo = request()->boolean('descargar') ? 'attachment' : 'inline';

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "$modo; filename=\"$nombre\"",
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
````

### `config/sula.php`

**REEMPLAZA** (versión 0.4.0).

Todo lo de planos y Groq sale del `.env` con valores por defecto seguros. **Nada secreto vive aquí**: solo lee variables.

````php
<?php

// Configuración propia de SULA Core
return [
    'version' => '0.4.0',

    // Quién firma los cambios en el journal (temporal, hasta tener login)
    'usuario' => env('SULA_USUARIO', 'desarrollo'),

    // Módulo 3: de dónde salen los planos
    'planos' => [
        'fuente' => env('PLANOS_FUENTE', 'local'),                         // drive o local
        'drive_credenciales' => env('PLANOS_DRIVE_CREDENCIALES', 'datos-privados/drive-service-account.json'),
        'drive_carpeta' => env('PLANOS_DRIVE_CARPETA'),                    // id de la carpeta raíz en Drive
        'carpeta_local' => env('PLANOS_CARPETA_LOCAL', base_path('datos-privados/planos')),
        'patron_clave' => env('PLANOS_PATRON_CLAVE'),                      // vacío = 2-1-0072-0003
        // Tamaño de cada envío a la IA. Los valores por defecto caben en el plan GRATIS de Groq
        // (6,000 tokens por minuto). Con plan de pago puedes subirlos (12000 y 3).
        'caracteres_por_lote' => (int) env('PLANOS_CARACTERES_POR_LOTE', 5000),
        'imagenes_por_lote' => (int) env('PLANOS_IMAGENES_POR_LOTE', 1),
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

### `routes/web.php`

**REEMPLAZA.**

Dos pantallas Livewire y una ruta normal para el PDF. `/planos/{plano}/pdf` no choca con `/planos/{plano}` porque tiene un segmento más.

````php
<?php

use App\Http\Controllers\PdfPlanoController;
use App\Livewire\Cotizaciones\Editor as EditorCotizacion;
use App\Livewire\Cotizaciones\Indice as Cotizaciones;
use App\Livewire\Inicio;
use App\Livewire\Journal;
use App\Livewire\Materiales\Indice as Materiales;
use App\Livewire\Parametros;
use App\Livewire\Planos\Indice as Planos;
use App\Livewire\Planos\Ver as VerPlano;
use Illuminate\Support\Facades\Route;

// Cada pantalla es un componente Livewire de página completa
Route::livewire('/', Inicio::class)->name('inicio');
Route::livewire('/journal', Journal::class)->name('journal');
Route::livewire('/materiales', Materiales::class)->name('materiales');
Route::livewire('/parametros', Parametros::class)->name('parametros');
Route::livewire('/cotizaciones', Cotizaciones::class)->name('cotizaciones');
Route::livewire('/cotizaciones/{cotizacion}', EditorCotizacion::class)->name('cotizaciones.editor');

// Módulo 3: planos y despiece con IA
Route::livewire('/planos', Planos::class)->name('planos');
Route::livewire('/planos/{plano}', VerPlano::class)->name('planos.ver');
Route::get('/planos/{plano}/pdf', PdfPlanoController::class)->name('planos.pdf');
````

## 10. PANTALLAS

### `resources/views/layouts/app.blade.php`

**CAMBIO PUNTUAL** (no reemplazar el archivo). En el arreglo `$menu`, agregar la sección **Ingeniería** antes de **Ingeniería de costos**:

**BUSCAR:**
````php
        'Ingeniería de costos' => [
````

**REEMPLAZAR:**
````php
        'Ingeniería' => [
            ['planos', 'Planos y despiece', 'PLN'],
        ],
        'Ingeniería de costos' => [
````

### `resources/views/components/chip-despiece.blade.php`

**NUEVO.**

Componente anónimo de Blade: `<x-chip-despiece :despiece="$d" />`. `match` elige texto y color según el estado.

````blade
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
````

### `app/Livewire/Planos/Indice.php`

**NUEVO.**

- `#[Url(as: 'q')]`: lo que buscas queda en la dirección (`/planos?q=0072`) y se puede compartir o recargar.
- Busca **cada palabra** por separado: "vitrina 0072" encuentra "2-1-0072-0003 VITRINA…".
- `limit(200)`: con miles de planos la tabla no se vuelve lenta; se afina escribiendo más.

````php
<?php

namespace App\Livewire\Planos;

use App\Models\IaLlamada;
use App\Models\Plano;
use App\Planos\Fuentes\Fuente;
use App\Planos\Sincronizador;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/** Buscador de planos por clave o nombre, con el estado de su despiece. */
#[Title('Planos')]
class Indice extends Component
{
    // #[Url] = el texto buscado queda en la dirección (?q=0072) y se puede compartir
    #[Url(as: 'q')]
    public string $buscar = '';

    #[Url]
    public string $filtro = '';   // '', SIN, BORRADOR, APROBADO

    public ?string $mensaje = null;

    public ?string $error = null;

    #[Computed]
    public function planos()
    {
        return Plano::query()
            ->where('activo', true)
            ->with(['aprobado', 'ultimo'])
            ->when(trim($this->buscar), function ($q) {
                // Busca cada palabra por separado: "vitrina 0072" encuentra "2-1-0072-0003 VITRINA…"
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
            ->limit(200)
            ->get();
    }

    #[Computed]
    public function kpis(): array
    {
        $activos = Plano::where('activo', true);

        return [
            'planos' => (clone $activos)->count(),
            'aprobados' => (clone $activos)->whereHas('aprobado')->count(),
            'tokens_mes' => (int) IaLlamada::where('creado_en', '>=', now()->startOfMonth())->sum(DB::raw('tokens_entrada + tokens_salida')),
            'ultima_sync' => Plano::max('sincronizado_en'),
        ];
    }

    public function sincronizar(): void
    {
        $this->mensaje = $this->error = null;
        try {
            $r = Sincronizador::sincronizar(Fuente::actual());
            $this->mensaje = "{$r['total']} PDFs revisados · {$r['nuevos']} nuevos · {$r['actualizados']} actualizados · {$r['retirados']} retirados.";
        } catch (Throwable $e) {
            $this->error = 'No se pudo sincronizar: '.$e->getMessage();
        }
        unset($this->planos, $this->kpis);
    }

    public function render()
    {
        return view('livewire.planos.indice', ['fuente' => strtoupper(config('sula.planos.fuente'))]);
    }
}
````

### `resources/views/livewire/planos/indice.blade.php`

**NUEVO.**

`wire:model.live.debounce.250ms`: busca mientras escribes, pero espera 250 ms de pausa para no consultar en cada tecla.

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="max-w-7xl">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="font-mono text-xs tracking-widest text-tenue">PLN · PLANOS</p>
            <h1 class="mt-1 text-3xl font-semibold">Planos y despiece</h1>
            <p class="mt-2 max-w-xl text-sm text-tenue">
                Busca la clave, abre el plano y arma su despiece (subensambles y piezas) con la IA.
                Un despiece aprobado se puede mandar a cotizar con un clic.
            </p>
        </div>
        <button class="boton" wire:click="sincronizar" wire:loading.attr="disabled" wire:target="sincronizar">
            <span wire:loading.remove wire:target="sincronizar">↻ Sincronizar {{ $fuente }}</span>
            <span wire:loading wire:target="sincronizar">Leyendo {{ $fuente }}…</span>
        </button>
    </div>

    @if ($mensaje)<div class="mt-4 border-l-2 border-ok bg-ok/10 px-4 py-3 text-sm">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mt-4">{{ $error }}</div>@endif

    @php($k = $this->kpis)
    <div class="mt-6 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Planos</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ number_format($k['planos']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Con despiece aprobado</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold text-ok">{{ number_format($k['aprobados']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Tokens de IA este mes</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ number_format($k['tokens_mes']) }}</p>
        </div>
        <div class="kpi">
            <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">Última sincronización</p>
            <p class="cifras mt-2 font-mono text-sm">{{ $k['ultima_sync'] ? F::fecha($k['ultima_sync']) : 'Nunca' }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-wrap items-center gap-3">
        <input class="campo max-w-md font-mono" placeholder="Clave o nombre: 0072, vitrina, exhibidor…" autofocus
               wire:model.live.debounce.250ms="buscar">
        <div class="flex flex-wrap gap-1">
            @foreach (['' => 'Todos', 'SIN' => 'Sin despiece', 'BORRADOR' => 'En revisión', 'APROBADO' => 'Aprobados'] as $valor => $nombre)
                <button wire:click="$set('filtro', '{{ $valor }}')"
                    @class(['chip py-1.5', 'border-sula text-texto' => $filtro === $valor, 'border-borde text-tenue hover:text-texto' => $filtro !== $valor])>
                    {{ $nombre }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-4 overflow-x-auto border border-borde">
        <table class="w-full text-left text-sm">
            <thead class="tabla-cabecera">
                <tr>
                    <th class="px-4 py-3">Clave</th>
                    <th class="px-4 py-3">Archivo</th>
                    <th class="px-4 py-3">Carpeta</th>
                    <th class="px-4 py-3">Rev.</th>
                    <th class="px-4 py-3">Despiece</th>
                    <th class="px-4 py-3">Modificado</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-borde">
                @forelse ($this->planos as $p)
                    <tr wire:key="plano-{{ $p->id }}" class="hover:bg-panel">
                        <td class="px-4 py-3 font-mono font-semibold whitespace-nowrap">
                            <a href="{{ route('planos.ver', $p) }}" wire:navigate class="hover:text-sula">{{ $p->clave }}</a>
                        </td>
                        <td class="min-w-64 px-4 py-3">
                            <a href="{{ route('planos.ver', $p) }}" wire:navigate class="line-clamp-1 hover:text-sula">{{ $p->nombre }}</a>
                        </td>
                        <td class="px-4 py-3 text-xs text-tenue">{{ $p->carpeta ?? '—' }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $p->revision }}</td>
                        <td class="px-4 py-3">
                            <x-chip-despiece :despiece="$p->aprobado ?? $p->ultimo" />
                        </td>
                        <td class="px-4 py-3 font-mono text-xs whitespace-nowrap text-tenue">
                            {{ $p->modificado_en_origen ? F::fecha($p->modificado_en_origen) : '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-tenue">
                            @if ($k['planos'] === 0)
                                Todavía no hay planos. Dale a <span class="font-mono">Sincronizar</span> o corre
                                <span class="font-mono">php artisan sula:planos</span>.
                            @else
                                Ningún plano coincide con la búsqueda.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($this->planos->count() === 200)
        <p class="mt-2 font-mono text-xs text-tenue">Se muestran los primeros 200: escribe más de la clave para afinar.</p>
    @endif
</section>
</div>
````

<!-- SPEC SULA Core · MÓDULO 3 · PARTE 4 DE 5 -->
> **PARTE 4 DE 5 del spec del Módulo 3.** Guárdala tal cual en `docs/SPEC-modulo3-parte4.md` y responde solo "recibida 4/5". **No ejecutes nada** hasta recibir la parte 5/5.

### `app/Livewire/Planos/Ver.php`

**NUEVO.**

- `$subs`: **copia editable** del despiece en memoria. Lo que escribes no se guarda hasta "Guardar" (igual que el despiece de cotizaciones).
- `despiece()` (computed): la versión pedida (`?v=2`), o la aprobada, o la última.
- `explosion()`: totales por material **por mueble** (piezas, metros lineales, m²), calculados en vivo con `BigDecimal`.
- `refrescar()`: lo llama `wire:poll.3s` mientras la IA lee; cuando cambia el estado, recarga todo.
- `cancelarLectura()`: si la cola estaba apagada y algo se quedó "Leyendo" más de 20 min, permite soltarlo.
- `aprobar()` primero **guarda** lo que tengas en pantalla y luego aprueba (para que no se pierdan cambios).
- La pintura se edita en **una columna**: perímetro (tubo) o caras (lámina), como en cotizaciones.

````php
<?php

namespace App\Livewire\Planos;

use App\Costeo\Dec;
use App\Models\Cliente;
use App\Models\Despiece;
use App\Models\Material;
use App\Models\Plano;
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
class Ver extends Component
{
    public Plano $plano;

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

    public function mount(Plano $plano): void
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

        return view('livewire.planos.ver', [
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

### `resources/views/livewire/planos/ver.blade.php`

**NUEVO.**

- Dos columnas en pantalla grande: plano a la izquierda (fijo al hacer scroll) y despiece a la derecha. "Ocultar plano" da todo el ancho a la tabla.
- `<iframe>` + visor del navegador: zoom, páginas e imprimir sin instalar nada. `wire:ignore` evita que Livewire recargue el PDF en cada clic.
- Estados: sin despiece → botones "Leer con IA" / "Capturar a mano"; `LEYENDO` → avance en vivo; `ERROR` → mensaje; `BORRADOR` → editable; `APROBADO` → "Cotizar" / "Nueva versión".
- `wire:confirm`: pide confirmación antes de aprobar o volver a gastar IA.

````blade
@use('App\Support\Formato', 'F')

<div>
<section class="max-w-[100rem]">
    {{-- Encabezado --}}
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('planos') }}" wire:navigate class="font-mono text-xs tracking-widest text-tenue hover:text-texto">← PLANOS</a>
            <h1 class="mt-1 font-mono text-3xl font-semibold">{{ $plano->clave }}</h1>
            <p class="mt-1 truncate text-sm text-tenue">
                {{ $plano->nombre }} · <span class="font-mono">{{ $plano->revision }}</span>
                @if ($plano->carpeta) · {{ $plano->carpeta }} @endif
                @unless ($plano->activo) · <span class="text-sula">ya no está en {{ $plano->origen }}</span> @endunless
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button class="boton" wire:click="$toggle('verPdf')">{{ $verPdf ? 'Ocultar plano' : 'Ver plano' }}</button>
            <a class="boton" href="{{ route('planos.pdf', $plano) }}?descargar=1">Descargar PDF</a>
        </div>
    </div>

    @if ($mensaje)<div class="mt-4 border-l-2 border-ok bg-ok/10 px-4 py-3 text-sm">{{ $mensaje }}</div>@endif
    @if ($error)<div class="aviso-error mt-4">{{ $error }}</div>@endif

    <div @class(['mt-6 grid gap-6', 'xl:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]' => $verPdf])>
        {{-- Visor del PDF: el navegador trae su propio visor (zoom, páginas, imprimir) --}}
        @if ($verPdf)
            <div class="h-[70vh] border border-borde bg-panel xl:sticky xl:top-6 xl:h-[85vh]" wire:ignore>
                <iframe src="{{ route('planos.pdf', $plano) }}" class="h-full w-full" title="Plano {{ $plano->clave }}"></iframe>
            </div>
        @endif

        {{-- Despiece --}}
        <div class="min-w-0 space-y-4">
            {{-- Versiones --}}
            <div class="flex flex-wrap items-center gap-2">
                <p class="etiqueta mb-0 mr-2">Despiece</p>
                @foreach ($this->versiones as $v)
                    <button wire:click="verVersion({{ $v->version }})"
                        @class(['chip py-1', 'border-sula text-texto' => $d?->id === $v->id, 'border-borde text-tenue hover:text-texto' => $d?->id !== $v->id])>
                        v{{ $v->version }} · {{ $v->estado }}
                    </button>
                @endforeach
            </div>

            @if (! $d)
                {{-- Sin despiece todavía --}}
                <div class="border border-borde bg-panel p-6">
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
                        <button class="boton-primario" wire:click="leerConIa">Leer con IA</button>
                        <button class="boton" wire:click="capturarManual">Capturar a mano</button>
                    </div>
                </div>
            @elseif ($d->estado === 'LEYENDO')
                {{-- La IA está trabajando: se pregunta el avance cada 3 s --}}
                <div class="border border-alerta/50 bg-alerta/5 p-6" wire:poll.3s="refrescar">
                    <p class="font-mono text-xs tracking-widest text-alerta">LEYENDO CON IA · v{{ $d->version }}</p>
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
                    <div class="grid gap-px border border-borde bg-borde text-sm sm:grid-cols-2">
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

                @include('livewire.planos._despiece')

                {{-- Explosión por mueble --}}
                @if ($this->explosion)
                    <div>
                        <p class="etiqueta">Explosión: total por mueble</p>
                        <div class="overflow-x-auto border border-borde">
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
    <button class="fixed inset-0 z-30 bg-black/60" aria-label="Cerrar" wire:click="$set('cotizando', false)"></button>
    <div class="fixed inset-x-4 top-24 z-40 mx-auto max-w-md border border-borde bg-panel p-6 shadow-2xl">
        <p class="font-mono text-xs tracking-widest text-tenue">COTIZAR · {{ $plano->clave }}</p>
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

### `resources/views/livewire/planos/_despiece.blade.php`

**NUEVO.**

- Un bloque por subensamble (nombre × cantidad por mueble) con su tabla de piezas.
- Debajo del selector de material se ve **cómo venía en el plano** ("plano: MELAMINA ROBLE 25MM"), para que sepas qué elegir.
- Rojo = bloquea aprobar; gris = informativo (pintura estimada).
- Lleva su propio `@use(...)` porque cada archivo Blade necesita el suyo.

````blade
@use('App\Support\Formato', 'F')
{{-- Árbol del despiece: subensamble (× cantidad por mueble) → piezas (cantidad por subensamble).
     Variables: $subs, $mats (catálogo), $editable --}}
<div class="space-y-4">
    @foreach ($subs as $i => $s)
        <div wire:key="sub-{{ $i }}" class="border border-borde">
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

### `app/Livewire/Inicio.php`

**REEMPLAZA.**

Agrega el conteo de planos activos.

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

/** Pantalla de inicio: estado del sistema. Se refresca sola cada 10 s (wire:poll). */
#[Title('Inicio')]
class Inicio extends Component
{
    public function render()
    {
        try {
            DB::select('SELECT 1');
            $bd = 'OK';
        } catch (\Throwable) {
            $bd = 'SIN CONEXIÓN';
        }

        return view('livewire.inicio', [
            'bd' => $bd,
            'materiales' => $bd === 'OK' ? Material::where('activo', true)->count() : 0,
            'borradores' => $bd === 'OK' ? Cotizacion::where('estado', 'BORRADOR')->count() : 0,
            'planos' => $bd === 'OK' ? Plano::where('activo', true)->count() : 0,
            'eventosHoy' => $bd === 'OK' ? Evento::whereDate('ocurrido_en', today())->count() : 0,
        ]);
    }
}
````

### `resources/views/livewire/inicio.blade.php`

**REEMPLAZA.**

Cuatro tarjetas abajo: Planos, Materiales, Cotizaciones en borrador, Journal.

````blade
<section class="max-w-5xl" wire:poll.10s>
    <p class="font-mono text-xs tracking-widest text-tenue">INI · ESTADO DEL SISTEMA</p>
    <h1 class="mt-1 text-3xl font-semibold">SULA Core</h1>
    <p class="mt-2 max-w-xl text-sm text-tenue">
        El sistema nervioso de la planta: costeo, despiece, inventario y producción en una sola fuente de verdad.
    </p>

    <div class="mt-8 grid grid-cols-2 gap-px border border-borde bg-borde lg:grid-cols-4">
        @foreach ([
            ['Base de datos', $bd, $bd === 'OK'],
            ['Versión', config('sula.version'), true],
            ['Entorno', strtoupper(app()->environment()), true],
            ['Usuario', config('sula.usuario'), true],
        ] as [$etiqueta, $valor, $ok])
            <div class="kpi">
                <p class="font-mono text-[10px] tracking-widest text-tenue uppercase">{{ $etiqueta }}</p>
                <p class="mt-3 flex items-center gap-2 font-mono text-xl font-semibold">
                    <span @class(['h-2 w-2 rounded-full', 'bg-ok' => $ok, 'bg-sula' => ! $ok])></span>{{ $valor }}
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-px grid grid-cols-2 gap-px lg:grid-cols-4 border border-t-0 border-borde bg-borde">
        <a href="{{ route('planos') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">PLANOS</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $planos }}</p>
        </a>
        <a href="{{ route('materiales') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">MATERIALES ACTIVOS</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $materiales }}</p>
        </a>
        <a href="{{ route('cotizaciones') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">COTIZACIONES EN BORRADOR</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $borradores }}</p>
        </a>
        <a href="{{ route('journal') }}" wire:navigate class="kpi hover:bg-panel-alto">
            <p class="font-mono text-[10px] tracking-widest text-tenue">CAMBIOS HOY (JOURNAL)</p>
            <p class="cifras mt-2 font-mono text-3xl font-semibold">{{ $eventosHoy }}</p>
        </a>
    </div>

    <p class="cifras mt-4 font-mono text-xs text-tenue">Última revisión: {{ now()->format('H:i:s') }}</p>
</section>
````

## 11. PRUEBAS

### `tests/Unit/NombreArchivoTest.php`

**NUEVO.**

4 pruebas: clave SULA, nombre sin clave (el exhibidor), revisiones y patrón propio.

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
}
````

<!-- SPEC SULA Core · MÓDULO 3 · PARTE 5 DE 5 -->
> **PARTE 5 DE 5 (ÚLTIMA) del spec del Módulo 3.** Guárdala en `docs/SPEC-modulo3-parte5.md`. Ya con las 5 partes: júntalas en orden en `docs/SPEC-modulo3.md`, léelo completo y **muéstrame la lista de archivos que vas a tocar antes de empezar** (sección 0).

### `tests/Unit/NormalizadorTest.php`

**NUEVO.**

8 pruebas con una respuesta **de mentiras** parecida a la que daría Groq con el exhibidor: no gastan IA ni necesitan internet.
- Lo más importante: que **no se pisen** datos al juntar lecturas, que el tubo de 1" dé **101.6 mm** de pintura (igual que el Excel) y que la explosión multiplique bien.

````php
<?php

namespace Tests\Unit;

use App\Planos\Normalizador;
use App\Planos\Prompts;
use PHPUnit\Framework\TestCase;

/**
 * El Normalizador limpia lo que responde la IA. Se prueba con una respuesta
 * "de mentiras" parecida a la que daría Groq con el plano del Módulo Exhibidor.
 */
class NormalizadorTest extends TestCase
{
    /** Catálogo mínimo: id → tipo/categoría (como la semilla) */
    private const MATERIALES = [
        1 => ['tipo' => 'LINEAL', 'categoria' => 'METAL', 'descripcion' => 'Tubo cuadrado (PTR) 1" × 1" cal 16, tramo 6 m'],
        2 => ['tipo' => 'AREA_KG', 'categoria' => 'METAL', 'descripcion' => 'Lámina CRS cal 11'],
        5 => ['tipo' => 'AREA', 'categoria' => 'MADERA', 'descripcion' => 'Melamina roble 15 mm'],
        6 => ['tipo' => 'AREA', 'categoria' => 'MADERA', 'descripcion' => 'Melamina roble 28 mm'],
        9 => ['tipo' => 'PIEZA', 'categoria' => 'HERRAJES', 'descripcion' => 'Bisagra Ducasse 35 mm'],
    ];

    /** Claves y alias → id (como LectorPlano::catalogo()) */
    private const DICCIONARIO = [
        'M01' => 1, 'M02' => 2, 'M05' => 5, 'M06' => 6, 'M09' => 9,
        'T-CUADRADO 1" C.16' => 1,
        'LAMINA CRS C. 11' => 2,
        'MELAMINA ROBLE (SMA) 15MM' => 5,
        'MELAMINA ROBLE (SMA) 25MM' => 6,
    ];

    private static function respuestaIa(): array
    {
        return [
            'plano' => ['modelo' => 'S/M', 'descripcion' => 'MODULO EXHIBIDOR', 'cliente' => 'SULA', 'revision' => 'REV-00', 'acabado' => ''],
            'leyenda' => [
                ['codigo' => 'TC1', 'material' => 'T-CUADRADO 1" C.16'],
                ['codigo' => 'LM1', 'material' => 'LAMINA CRS C. 11'],
                ['codigo' => 'ML1', 'material' => 'MELAMINA ROBLE (SMA) 15mm'],
                ['codigo' => 'ML2', 'material' => 'MELAMINA ROBLE (SMA) 25mm'],
            ],
            'subensambles' => [
                ['nombre' => 'Poste final', 'cantidad' => 2, 'pagina' => 1, 'piezas' => [
                    ['descripcion' => 'Vertical', 'material' => 'TC1', 'cantidad' => 2, 'largo_mm' => '1,800', 'ancho_mm' => null, 'pintura' => null],
                    ['descripcion' => 'Ángulo de amarre', 'material' => 'LM1', 'cantidad' => '3', 'largo_mm' => 344, 'ancho_mm' => '37 mm', 'pintura' => null],
                ]],
                ['nombre' => 'GABINETE', 'cantidad' => 3, 'piezas' => [
                    ['descripcion' => 'Costado', 'material' => 'ML1', 'cantidad' => 2, 'largo_mm' => 669, 'ancho_mm' => null],
                ]],
                ['nombre' => 'ENSAMBLE GENERAL', 'cantidad' => 1, 'piezas' => [
                    ['descripcion' => 'Cubierta superior', 'material' => 'MELAMINA ROBLE 25mm', 'cantidad' => 1, 'largo_mm' => 2000, 'ancho_mm' => 350],
                ]],
            ],
            'herrajes' => [['descripcion' => 'BISAGRA RECTA CAZOLETA 35mm', 'cantidad' => 12]],
        ];
    }

    public function test_numeros_como_vienen_en_planos(): void
    {
        $this->assertSame('1800', Normalizador::numero('1,800'));
        $this->assertSame('1800', Normalizador::numero('1800 mm'));
        $this->assertSame('0.25', Normalizador::numero('0,25'));
        $this->assertSame('12.5', Normalizador::numero(12.5));
        $this->assertSame(null, Normalizador::numero('VARIOS'));
        $this->assertSame(null, Normalizador::numero(null));
        $this->assertSame('1800.5', Normalizador::numero('1,800.5'));
    }

    public function test_normaliza_leyenda_subensambles_y_herrajes(): void
    {
        $doc = Normalizador::normalizar(self::respuestaIa());

        $this->assertSame('MELAMINA ROBLE (SMA) 25MM', $doc['leyenda']['ML2']);
        $this->assertSame('POSTE FINAL', $doc['subensambles'][0]['nombre']);
        $this->assertSame('1800', $doc['subensambles'][0]['piezas'][0]['largo_mm']);
        $this->assertSame('37', $doc['subensambles'][0]['piezas'][1]['ancho_mm']);
        $this->assertSame('HERRAJE DE ARMADO', $doc['subensambles'][3]['nombre']);
        $this->assertSame('BISAGRA RECTA CAZOLETA 35MM', $doc['subensambles'][3]['piezas'][0]['material_texto']);
    }

    public function test_fusionar_completa_lo_que_falta_sin_pisar(): void
    {
        $a = Normalizador::normalizar(self::respuestaIa());
        // Pasada con dibujos: trae el ancho del costado (faltaba) y un largo distinto del vertical (no debe pisar)
        $b = Normalizador::normalizar(['subensambles' => [
            ['nombre' => 'gabinete', 'piezas' => [
                ['descripcion' => 'COSTADO', 'ancho_mm' => 333, 'notas' => 'veta vertical'],
                ['descripcion' => 'Puerta', 'material' => 'ML1', 'cantidad' => 2, 'largo_mm' => 665, 'ancho_mm' => 312],
            ]],
            ['nombre' => 'POSTE FINAL', 'piezas' => [['descripcion' => 'VERTICAL', 'largo_mm' => 1799]]],
        ]]);
        $doc = Normalizador::fusionar($a, $b);

        $gabinete = $doc['subensambles'][1];
        $this->assertCount(4, $doc['subensambles']);                       // no se duplicó GABINETE
        $this->assertSame('333', $gabinete['piezas'][0]['ancho_mm']);      // se completó
        $this->assertSame('veta vertical', $gabinete['piezas'][0]['notas']);
        $this->assertCount(2, $gabinete['piezas']);                        // se agregó la puerta
        $this->assertSame('1800', $doc['subensambles'][0]['piezas'][0]['largo_mm']); // no se pisó
    }

    public function test_resolver_materiales_pintura_y_avisos(): void
    {
        $doc = Normalizador::resolver(Normalizador::normalizar(self::respuestaIa()), self::DICCIONARIO, self::MATERIALES);
        [$poste, $gabinete, $general, $herraje] = $doc['subensambles'];

        // Código de leyenda → material del catálogo
        $vertical = $poste['piezas'][0];
        $this->assertSame(1, $vertical['material_id']);
        $this->assertSame('T-CUADRADO 1" C.16', $vertical['material_texto']);
        $this->assertSame('101.6', $vertical['perimetro_pintable_mm']);     // 4 × 25.4: igual que el Excel
        $this->assertSame('info', $vertical['avisos'][0]['nivel']);

        // Lámina: 2 caras estimadas
        $this->assertSame(2, $poste['piezas'][1]['material_id']);
        $this->assertSame('2', $poste['piezas'][1]['caras_pintables']);

        // Melamina sin ancho → error
        $costado = $gabinete['piezas'][0];
        $this->assertSame(5, $costado['material_id']);
        $this->assertSame('error', $costado['avisos'][0]['nivel']);

        // "MELAMINA ROBLE 25MM" no está como alias en este diccionario → error para que el usuario elija
        $this->assertSame(null, $general['piezas'][0]['material_id']);
        $this->assertSame('error', $general['piezas'][0]['avisos'][0]['nivel']);

        // Herraje sin alias → error; melamina no lleva pintura
        $this->assertSame(null, $herraje['piezas'][0]['material_id']);
        $this->assertSame('0', $costado['caras_pintables']);
    }

    public function test_respeta_material_elegido_a_mano(): void
    {
        $doc = Normalizador::normalizar(['subensambles' => [['nombre' => 'X', 'cantidad' => 1, 'piezas' => [
            ['descripcion' => 'Bisagra', 'material' => 'BISAGRA RARA', 'cantidad' => 4],
        ]]]]);
        $doc['subensambles'][0]['piezas'][0]['material_id'] = 9;
        $r = Normalizador::resolver($doc, self::DICCIONARIO, self::MATERIALES);

        $this->assertSame(9, $r['subensambles'][0]['piezas'][0]['material_id']);
        $this->assertSame([], $r['subensambles'][0]['piezas'][0]['avisos']);
    }

    public function test_perimetros_de_tubo(): void
    {
        $this->assertSame('101.6', Normalizador::perimetroTubo('T-CUADRADO 1" C.16'));
        $this->assertSame('203.2', Normalizador::perimetroTubo('PTR 2" CAL 14'));
        $this->assertSame('152.4', Normalizador::perimetroTubo('TUBO RECTANGULAR 1" X 2" C.18'));
        $this->assertSame('76.2', Normalizador::perimetroTubo('PTR 3/4" C.18'));
        $this->assertSame(null, Normalizador::perimetroTubo('SOLERA 1/8'));
    }

    public function test_explosion_multiplica_por_subensamble(): void
    {
        $doc = Normalizador::resolver(Normalizador::normalizar(self::respuestaIa()), self::DICCIONARIO, self::MATERIALES);
        $filas = Normalizador::explotar($doc['subensambles']);

        $this->assertSame('POSTE FINAL', $filas[0]['subensamble']);
        $this->assertSame('4', $filas[0]['cantidad']);   // 2 verticales × 2 postes
        $this->assertSame('6', $filas[1]['cantidad']);   // 3 ángulos × 2 postes
        $this->assertSame('6', $filas[2]['cantidad']);   // 2 costados × 3 gabinetes
        $this->assertSame('12', $filas[4]['cantidad']);  // herraje por mueble
    }

    public function test_resumen_para_la_ia_es_corto(): void
    {
        $doc = Normalizador::normalizar(self::respuestaIa());
        $r = Prompts::resumenBorrador($doc, 200);
        $this->assertLessThan(230, mb_strlen($r));
        $this->assertNotEmpty($r);
    }
}
````

## 12. ARRANCAR

Terminal 1 (base de datos y migración):
```bash
cd ~/dev/sula-core-laravel
docker compose up -d --wait
php artisan migrate
php artisan test --testsuite=Unit        # 21 pruebas en verde
php artisan sula:planos                  # primera sincronización
```

Terminal 2 (la **cola**: aquí trabaja la IA; déjala abierta):
```bash
cd ~/dev/sula-core-laravel
php artisan queue:work --timeout=900
```

Terminal 3 y 4 (como siempre):
```bash
php artisan serve
npm run dev
```

**Si cambias código del Job o del lector**, reinicia la terminal 2 (Ctrl+C y vuelve a correr `queue:work`): el trabajador carga el código una vez y no ve cambios.

---

## 13. CRITERIOS DE ACEPTACIÓN

| # | Prueba | Esperado |
|---|---|---|
| 1 | `php artisan test --testsuite=Unit` | **21** pruebas OK (9 motor + 4 nombre + 8 normalizador) |
| 2 | `php artisan migrate:status` | `2026_10_30_100004_crear_planos` en "Ran" |
| 3 | `php artisan sula:planos` con `PLANOS_FUENTE=local` y el PDF del exhibidor en `datos-privados/planos/` | tabla: 1 encontrado, 1 nuevo |
| 4 | Volver a correr `sula:planos` | 0 nuevos, 0 actualizados |
| 5 | Inicio | versión **0.4.0**; tarjeta PLANOS = 1 |
| 6 | Menú | sección "Ingeniería" → "Planos y despiece" |
| 7 | `/planos`, escribir `exhibidor` | aparece clave `SULA - MODULO EXHIBIDOR PL - FT`, REV-00, chip "Sin despiece" |
| 8 | Abrir el plano | el PDF se ve a la izquierda (3 páginas); "Descargar PDF" lo baja |
| 9 | "Leer con IA" **sin** la terminal 2 | se queda "En la fila…" (correcto: nadie atiende la cola) |
| 10 | Encender `queue:work` | en segundos: "Leyendo texto, páginas 1-2…", luego despiece v1 en BORRADOR |
| 11 | Revisar v1 | subensambles tipo POSTE FINAL, POSTE ADICIONAL, GABINETE ×3, cubiertas, herraje; leyenda LM1, LM2, TC1, ML1, ML2, TM1 |
| 12 | Piezas con ML1/ML2/TC1/LM1 | material del catálogo ya elegido (M05, M06, M01, M02) gracias a los alias |
| 13 | Herrajes (bisagra, jaladera, Kintai) | en rojo "no está en el catálogo" |
| 14 | "Aprobar" con rojos | mensaje "Hay N pieza(s) con avisos en rojo" y **no** aprueba |
| 15 | Elegir M09/M10/M11 en los herrajes, completar medidas en rojo, "Guardar" | los rojos desaparecen |
| 16 | "Aprobar" | chip Aprobado v1; en Materiales → M09 aparece el alias "BISAGRA RECTA CAZOLETA 35MM" |
| 17 | "Volver a leer con IA" | v2 nueva; al terminar, la bisagra **ya sale reconocida** (aprendió) |
| 18 | "Cotizar este plano", cliente vacío, cantidad 10 | abre una cotización BORRADOR con las piezas multiplicadas (ej. 2 costados × GABINETE ×3 = 6) |
| 19 | Journal | eventos de `despieces`, `despiece_piezas`, `material_alias` firmados por `ia-groq` y `gutz` |
| 20 | Tabla `ia_llamadas` (`docker exec -it sula-laravel-db psql -U sula -d sula_core -c "select paso, modelo, tokens_entrada, tokens_salida, ok from ia_llamadas"`) | una fila por llamada con tokens > 0 |
| 21 | Poner un `GROQ_API_KEY` falso y leer | despiece en ERROR con el mensaje de Groq (401), sin tronar la pantalla |
| 22 | "Leer con IA" marcando "revisar también los dibujos" | progreso "Revisando dibujos, página 1 de 3…" y luego BORRADOR con más medidas completas |
| 23 | Con `PLANOS_FUENTE=drive` y la carpeta de SULA MOB | `sula:planos` lista los PDFs con su carpeta; la clave sale del nombre (2-1-0072-0003) |
| 24 | Borrar un PDF de la carpeta local y sincronizar | "1 retirado"; ya no sale en la búsqueda |

**Sobre el criterio 11:** la IA no es determinista. Lo importante no es que acierte todo, sino que **lo que no sepa salga en rojo** y no inventado. Si se equivoca siempre en lo mismo, se ajusta `Prompts.php`.

---

## 14. GIT

```bash
git status                     # datos-privados/ y .env NO deben aparecer
git add -A
git commit -m "Módulo 3: planos desde Drive y despiece con IA (Groq)"
```
(El push lo hago yo.)

---

## 15. LÍMITES CONOCIDOS Y SIGUIENTE

- **Groq gratis** es lento en planos grandes con dibujos (6,000 tokens por minuto; cada imagen ≈ 2,000). Para uso diario en SULA, el plan de pago.
- Los modelos de Groq cambian: si uno desaparece, solo se cambia en el `.env`.
- El despiece de **cantos** (cubrecanto) y **consumibles** (microalambre, gas, pijas) no viene en los planos: se agregan a mano en el despiece o en la cotización.
- **Siguiente:** lo de ClickBalance que conviene meter (clientes/CRM ligero, inventario, cotización → orden) y, al final, el importador de **listas de precios**.
