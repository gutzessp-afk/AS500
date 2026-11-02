<?php

use App\Http\Middleware\FirmarJournal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Cada petición web (y cada acción de Livewire) firma el journal con el usuario
        $middleware->web(append: [FirmarJournal::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
