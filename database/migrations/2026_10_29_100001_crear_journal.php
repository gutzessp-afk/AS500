<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Journal (eventos inmutables), auditoría genérica y configuración.
 * Se escribe en SQL puro porque usa funciones de PostgreSQL (triggers,
 * columnas generadas, vistas, UNIQUE NULLS NOT DISTINCT) que el Schema
 * Builder de Laravel no cubre. Es el mismo SQL ya probado.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
-- =====================================================================
-- 1. JOURNAL: tabla de eventos (solo se escribe, nunca se edita)
-- =====================================================================
CREATE TABLE eventos (
    id           BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ocurrido_en  TIMESTAMPTZ NOT NULL DEFAULT now(),
    tabla        TEXT        NOT NULL,
    operacion    TEXT        NOT NULL CHECK (operacion IN ('INSERT', 'UPDATE', 'DELETE')),
    registro_id  TEXT,
    usuario      TEXT,
    antes        JSONB,
    despues      JSONB
);

CREATE INDEX idx_eventos_registro ON eventos (tabla, registro_id);
CREATE INDEX idx_eventos_fecha    ON eventos (ocurrido_en DESC);

-- Bloquea cualquier intento de editar o borrar el journal
CREATE FUNCTION eventos_inmutable() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'La tabla eventos es de solo escritura: no se puede hacer %', TG_OP;
END;
$$;

CREATE TRIGGER eventos_no_editar
    BEFORE UPDATE OR DELETE ON eventos
    FOR EACH ROW EXECUTE FUNCTION eventos_inmutable();

CREATE TRIGGER eventos_no_vaciar
    BEFORE TRUNCATE ON eventos
    FOR EACH STATEMENT EXECUTE FUNCTION eventos_inmutable();

-- =====================================================================
-- 2. AUDITORÍA: función genérica que escribe en eventos
-- =====================================================================
CREATE FUNCTION registrar_evento() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    v_antes   JSONB;
    v_despues JSONB;
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        v_antes := to_jsonb(OLD);
    END IF;
    IF TG_OP IN ('INSERT', 'UPDATE') THEN
        v_despues := to_jsonb(NEW);
    END IF;

    -- Si en un UPDATE no cambió nada, no se registra
    IF TG_OP = 'UPDATE' AND v_antes = v_despues THEN
        RETURN NULL;
    END IF;

    INSERT INTO eventos (tabla, operacion, registro_id, usuario, antes, despues)
    VALUES (
        TG_TABLE_NAME,
        TG_OP,
        COALESCE(v_despues, v_antes) ->> (TG_ARGV[0]),
        NULLIF(current_setting('app.usuario', true), ''),
        v_antes,
        v_despues
    );
    RETURN NULL;
END;
$$;

-- Atajo para activar la auditoría en una tabla:
--   SELECT auditar('materiales');          -- usa la columna "id"
--   SELECT auditar('configuracion', 'clave');
CREATE FUNCTION auditar(p_tabla regclass, p_columna_id TEXT DEFAULT 'id') RETURNS void
LANGUAGE plpgsql AS $$
BEGIN
    EXECUTE format(
        'CREATE TRIGGER auditoria AFTER INSERT OR UPDATE OR DELETE ON %s
         FOR EACH ROW EXECUTE FUNCTION registrar_evento(%L)',
        p_tabla, p_columna_id
    );
END;
$$;

-- =====================================================================
-- 3. CONFIGURACIÓN GENERAL (primera tabla auditada)
-- =====================================================================
CREATE TABLE configuracion (
    clave          TEXT PRIMARY KEY,
    valor          TEXT        NOT NULL,
    descripcion    TEXT,
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now()
);

SELECT auditar('configuracion', 'clave');

INSERT INTO configuracion (clave, valor, descripcion) VALUES
    ('empresa', 'SULA',  'Nombre de la empresa'),
    ('moneda',  'MXN',   'Moneda de todos los costos'),
    ('iva',     '0.16',  'Tasa de IVA (solo referencia)');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS configuracion;
DROP FUNCTION IF EXISTS auditar(regclass, TEXT);
DROP FUNCTION IF EXISTS registrar_evento();
DROP TABLE IF EXISTS eventos;
DROP FUNCTION IF EXISTS eventos_inmutable();
SQL);
    }
};
