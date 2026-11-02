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
