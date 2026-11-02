<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Cotizaciones por plano: piezas, ajustes de compra y versiones congeladas.
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
-- COTIZACIONES: una cotización = un plano (con su revisión) × cantidad
-- Estados: BORRADOR → ENVIADA → APROBADA / RECHAZADA
-- Al ENVIAR se congela una "foto" (entrada + resultado) en cotizacion_versiones.
-- Para cambiar una enviada se abre una nueva revisión (R1, R2…) en BORRADOR.
-- =====================================================================
CREATE SEQUENCE cotizacion_folio_seq;

CREATE TABLE cotizaciones (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    folio               TEXT NOT NULL UNIQUE DEFAULT
                          'COT-' || to_char(now(), 'YYYY') || '-' || lpad(nextval('cotizacion_folio_seq')::text, 4, '0'),
    cliente_id          BIGINT REFERENCES clientes (id),
    plano_codigo        TEXT NOT NULL,
    descripcion         TEXT NOT NULL,
    plano_rev           TEXT NOT NULL DEFAULT 'REV-00',
    dibujo              TEXT,
    cantidad            INT  NOT NULL DEFAULT 1 CHECK (cantidad > 0),
    frente_m            NUMERIC(10,3) CHECK (frente_m IS NULL OR frente_m > 0),
    pintura_material_id BIGINT REFERENCES materiales (id),
    estado              TEXT NOT NULL DEFAULT 'BORRADOR'
                          CHECK (estado IN ('BORRADOR', 'ENVIADA', 'APROBADA', 'RECHAZADA')),
    revision            INT  NOT NULL DEFAULT 0 CHECK (revision >= 0),
    vigencia_dias       INT  NOT NULL DEFAULT 15 CHECK (vigencia_dias > 0),
    notas               TEXT,
    enviada_en          TIMESTAMPTZ,
    decidida_en         TIMESTAMPTZ,
    creado_en           TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en      TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_cotizaciones_estado ON cotizaciones (estado);
CREATE INDEX idx_cotizaciones_cliente ON cotizaciones (cliente_id);
SELECT auditar('cotizaciones');

-- Despiece del plano, POR UN MUEBLE. Cotas en mm como vienen en el plano.
CREATE TABLE cotizacion_piezas (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cotizacion_id         BIGINT NOT NULL REFERENCES cotizaciones (id) ON DELETE CASCADE,
    orden                 INT    NOT NULL DEFAULT 0,
    subensamble           TEXT,
    descripcion           TEXT   NOT NULL,
    material_id           BIGINT NOT NULL REFERENCES materiales (id),
    cantidad              NUMERIC(12,4) NOT NULL CHECK (cantidad > 0),
    largo_mm              NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (largo_mm >= 0),
    ancho_mm              NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (ancho_mm >= 0),
    perimetro_pintable_mm NUMERIC(10,2) NOT NULL DEFAULT 0 CHECK (perimetro_pintable_mm >= 0),
    caras_pintables       NUMERIC(4,1)  NOT NULL DEFAULT 0 CHECK (caras_pintables >= 0),
    notas                 TEXT
);
CREATE INDEX idx_piezas_cotizacion ON cotizacion_piezas (cotizacion_id, orden);
SELECT auditar('cotizacion_piezas');

-- Decisiones de compra por material dentro de la cotización
CREATE TABLE cotizacion_ajustes (
    cotizacion_id BIGINT NOT NULL REFERENCES cotizaciones (id) ON DELETE CASCADE,
    material_id   BIGINT NOT NULL REFERENCES materiales (id),
    plan_corte    NUMERIC(10,4) CHECK (plan_corte IS NULL OR plan_corte > 0),
    merma_pct     NUMERIC(6,4)  NOT NULL DEFAULT 0 CHECK (merma_pct >= 0 AND merma_pct < 5),
    modo_carga    TEXT CHECK (modo_carga IS NULL OR modo_carga IN ('CONSUMIDO', 'COMPLETA')),
    nota          TEXT,
    PRIMARY KEY (cotizacion_id, material_id)
);
SELECT auditar('cotizacion_ajustes', 'cotizacion_id');

-- Fotos congeladas: lo que se le mandó al cliente en cada revisión
CREATE TABLE cotizacion_versiones (
    id             BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cotizacion_id  BIGINT NOT NULL REFERENCES cotizaciones (id) ON DELETE CASCADE,
    revision       INT    NOT NULL,
    entrada        JSONB  NOT NULL,   -- todo lo que entró al motor (materiales, precios, parámetros, despiece)
    resultado      JSONB  NOT NULL,   -- lo que salió del motor
    precio_venta   NUMERIC(14,2) NOT NULL,
    total_pedido   NUMERIC(14,2) NOT NULL,
    creado_en      TIMESTAMPTZ NOT NULL DEFAULT now(),
    creado_por     TEXT,
    UNIQUE (cotizacion_id, revision)
);
SELECT auditar('cotizacion_versiones');
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP TABLE IF EXISTS cotizacion_versiones;
DROP TABLE IF EXISTS cotizacion_ajustes;
DROP TABLE IF EXISTS cotizacion_piezas;
DROP TABLE IF EXISTS cotizaciones;
DROP SEQUENCE IF EXISTS cotizacion_folio_seq;
SQL);
    }
};
