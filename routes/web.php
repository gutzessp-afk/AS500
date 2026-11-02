<?php

use App\Http\Controllers\DespiecePdfController;
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
Route::get('/produccion/planos/{plano}/despiece.pdf', DespiecePdfController::class)->name('produccion.despiece');

// "Conectar Google": para crear carpetas y subir PDFs en el Drive de una persona
Route::get('/google/conectar', [GoogleController::class, 'conectar'])->name('google.conectar');
Route::get('/google/callback', [GoogleController::class, 'callback'])->name('google.callback');
Route::post('/google/desconectar', [GoogleController::class, 'desconectar'])->name('google.desconectar');

// Direcciones viejas del Módulo 3 → las nuevas (por si alguien guardó el enlace)
Route::redirect('/planos', '/produccion');
Route::get('/planos/{plano}', fn (string $plano) => redirect()->route('produccion.plano', $plano));
