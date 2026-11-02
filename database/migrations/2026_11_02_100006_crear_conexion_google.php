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
