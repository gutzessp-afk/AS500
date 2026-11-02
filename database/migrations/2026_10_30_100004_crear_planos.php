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
