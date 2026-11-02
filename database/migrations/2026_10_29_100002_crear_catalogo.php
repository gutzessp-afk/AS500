<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Catálogo: calibres, materiales, historial de precios, alias, clientes, parámetros y vistas.
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
-- 1. CALIBRES DE LÁMINA (tabla estándar SULA)
-- =====================================================================
CREATE TABLE calibres (
    calibre        INT PRIMARY KEY CHECK (calibre > 0),
    kg_m2          NUMERIC(8,3) NOT NULL CHECK (kg_m2 > 0),
    peso_hoja_4x8  NUMERIC(8,2) NOT NULL CHECK (peso_hoja_4x8 > 0)
);
SELECT auditar('calibres', 'calibre');

INSERT INTO calibres (calibre, kg_m2, peso_hoja_4x8) VALUES
    (11, 24.41, 72.57),
    (12, 21.36, 62.70),
    (13, 18.30, 54.43),
    (14, 15.26, 45.36),
    (16, 12.20, 36.29),
    (18,  9.76, 29.03),
    (20,  7.32, 21.77),
    (22,  6.10, 18.14),
    (24,  4.88, 14.54),
    (26,  3.66, 10.94);

-- =====================================================================
-- 2. MATERIALES (catálogo)
-- =====================================================================
CREATE TABLE materiales (
    id            BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    clave         TEXT NOT NULL UNIQUE,
    descripcion   TEXT NOT NULL,
    categoria     TEXT NOT NULL CHECK (categoria IN
                    ('METAL', 'MADERA', 'ACABADO', 'HERRAJES', 'CONSUMIBLES', 'EMPAQUE')),
    tipo_calculo  TEXT NOT NULL CHECK (tipo_calculo IN
                    ('LINEAL', 'AREA', 'AREA_KG', 'PESO', 'PIEZA')),
    unidad        TEXT NOT NULL CHECK (unidad IN ('m', 'm2', 'kg', 'pza')),
    presentacion  TEXT NOT NULL,
    contenido     NUMERIC(12,4) NOT NULL CHECK (contenido > 0),
    calibre       INT REFERENCES calibres (calibre),
    modo_carga    TEXT NOT NULL DEFAULT 'CONSUMIDO' CHECK (modo_carga IN ('CONSUMIDO', 'COMPLETA')),
    proveedor     TEXT,
    liga          TEXT,
    notas         TEXT,
    activo        BOOLEAN NOT NULL DEFAULT true,
    creado_en      TIMESTAMPTZ NOT NULL DEFAULT now(),
    actualizado_en TIMESTAMPTZ NOT NULL DEFAULT now(),

    -- La lámina por peso necesita calibre; los demás no lo llevan
    CONSTRAINT calibre_solo_en_area_kg CHECK (
        (tipo_calculo = 'AREA_KG' AND calibre IS NOT NULL) OR
        (tipo_calculo <> 'AREA_KG' AND calibre IS NULL)
    )
);
CREATE INDEX idx_materiales_categoria ON materiales (categoria) WHERE activo;
SELECT auditar('materiales');

-- =====================================================================
-- 3. HISTORIAL DE PRECIOS (nunca se sobreescribe un precio: se agrega uno nuevo)
-- =====================================================================
CREATE TABLE material_precios (
    id               BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    material_id      BIGINT NOT NULL REFERENCES materiales (id),
    precio_publicado NUMERIC(14,4) NOT NULL CHECK (precio_publicado >= 0),
    precio_por       TEXT NOT NULL CHECK (precio_por IN ('PRESENTACION', 'UNIDAD')),
    incluye_iva      BOOLEAN NOT NULL,
    tasa_iva         NUMERIC(5,4) NOT NULL DEFAULT 0.16 CHECK (tasa_iva >= 0 AND tasa_iva < 1),
    -- Se calcula solo: el precio sin IVA tal como se capturó (por presentación o por unidad)
    precio_sin_iva   NUMERIC(14,4) GENERATED ALWAYS AS (
                        CASE WHEN incluye_iva THEN round(precio_publicado / (1 + tasa_iva), 4)
                             ELSE precio_publicado END
                     ) STORED,
    confianza        TEXT NOT NULL CHECK (confianza IN ('ALTA', 'MEDIA', 'BAJA')),
    fuente           TEXT,
    vigente_desde    TIMESTAMPTZ NOT NULL DEFAULT now(),
    registrado_por   TEXT
);
CREATE INDEX idx_precios_material ON material_precios (material_id, vigente_desde DESC, id DESC);
SELECT auditar('material_precios');

-- =====================================================================
-- 4. ALIAS: cómo se llama el material en los planos → material del catálogo
-- =====================================================================
CREATE TABLE material_alias (
    alias        TEXT PRIMARY KEY CHECK (alias = upper(btrim(alias)) AND alias <> ''),
    material_id  BIGINT NOT NULL REFERENCES materiales (id),
    creado_en    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_alias_material ON material_alias (material_id);
SELECT auditar('material_alias', 'alias');

-- =====================================================================
-- 5. CLIENTES Y PARÁMETROS DE COSTEO
--    cliente_id NULL = parámetros por defecto de SULA
-- =====================================================================
CREATE TABLE clientes (
    id         BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    nombre     TEXT NOT NULL UNIQUE,
    activo     BOOLEAN NOT NULL DEFAULT true,
    creado_en  TIMESTAMPTZ NOT NULL DEFAULT now()
);
SELECT auditar('clientes');

CREATE TABLE costeo_parametros (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    cliente_id               BIGINT REFERENCES clientes (id),
    mano_obra_pct            NUMERIC(6,4) NOT NULL CHECK (mano_obra_pct >= 0),
    overhead_pct             NUMERIC(6,4) NOT NULL CHECK (overhead_pct >= 0),
    overhead_incluye_mo      BOOLEAN NOT NULL DEFAULT false,
    margen_pct               NUMERIC(6,4) NOT NULL CHECK (margen_pct >= 0 AND margen_pct < 1),
    rendimiento_pintura_m2kg NUMERIC(8,3) NOT NULL DEFAULT 5 CHECK (rendimiento_pintura_m2kg > 0),
    vigencia_precios_dias    INT NOT NULL DEFAULT 15 CHECK (vigencia_precios_dias > 0),
    notas                    TEXT,
    actualizado_en           TIMESTAMPTZ NOT NULL DEFAULT now(),
    -- Un solo renglón por cliente, y un solo renglón por defecto (NULL)
    CONSTRAINT un_parametro_por_cliente UNIQUE NULLS NOT DISTINCT (cliente_id)
);
SELECT auditar('costeo_parametros');

-- Parámetros por defecto (método SULA, oct-2026)
INSERT INTO costeo_parametros (cliente_id, mano_obra_pct, overhead_pct, overhead_incluye_mo,
                               margen_pct, rendimiento_pintura_m2kg, vigencia_precios_dias, notas)
VALUES (NULL, 0.25, 0.15, false, 0.30, 5, 15, 'Método SULA: M.O. 25% + overhead 15% sobre materiales + margen 30% sobre precio');

-- =====================================================================
-- 6. VISTAS
-- =====================================================================
-- 6a. El precio vigente de cada material (el más reciente), ya convertido
--     a precio por unidad y por presentación. En lámina, la "presentación"
--     pesa lo que dice la tabla de calibres (por eso el COALESCE).
CREATE VIEW v_precio_vigente AS
SELECT DISTINCT ON (mp.material_id)
    mp.material_id,
    mp.id AS precio_id,
    mp.precio_publicado,
    mp.precio_por,
    mp.incluye_iva,
    mp.tasa_iva,
    mp.confianza,
    mp.fuente,
    mp.vigente_desde,
    mp.registrado_por,
    (CASE WHEN mp.precio_por = 'UNIDAD'
          THEN mp.precio_sin_iva
          ELSE round(mp.precio_sin_iva / COALESCE(c.peso_hoja_4x8, m.contenido), 4)
     END)::NUMERIC(14,4) AS precio_unitario_sin_iva,
    (CASE WHEN mp.precio_por = 'PRESENTACION'
          THEN mp.precio_sin_iva
          ELSE round(mp.precio_sin_iva * COALESCE(c.peso_hoja_4x8, m.contenido), 4)
     END)::NUMERIC(14,4) AS precio_presentacion_sin_iva,
    (current_date - mp.vigente_desde::date)::INT AS dias_antiguedad
FROM material_precios mp
JOIN materiales m     ON m.id = mp.material_id
LEFT JOIN calibres c  ON c.calibre = m.calibre
ORDER BY mp.material_id, mp.vigente_desde DESC, mp.id DESC;

-- 6b. Cada material con su precio vigente (o sin precio, si no tiene)
CREATE VIEW v_materiales AS
SELECT
    m.id, m.clave, m.descripcion, m.categoria, m.tipo_calculo, m.unidad,
    m.presentacion, m.calibre, m.modo_carga, m.proveedor, m.liga, m.notas, m.activo,
    COALESCE(c.peso_hoja_4x8, m.contenido)::NUMERIC(12,4) AS contenido,
    c.kg_m2,
    p.precio_id,
    p.precio_publicado,
    p.precio_por,
    p.incluye_iva,
    p.confianza,
    p.fuente,
    p.vigente_desde,
    p.precio_unitario_sin_iva,
    p.precio_presentacion_sin_iva,
    p.dias_antiguedad
FROM materiales m
LEFT JOIN calibres c          ON c.calibre = m.calibre
LEFT JOIN v_precio_vigente p  ON p.material_id = m.id;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP VIEW IF EXISTS v_materiales;
DROP VIEW IF EXISTS v_precio_vigente;
DROP TABLE IF EXISTS costeo_parametros;
DROP TABLE IF EXISTS clientes;
DROP TABLE IF EXISTS material_alias;
DROP TABLE IF EXISTS material_precios;
DROP TABLE IF EXISTS materiales;
DROP TABLE IF EXISTS calibres;
SQL);
    }
};
