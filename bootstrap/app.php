<?php

use App\Http\Middleware\CheckMarchandActive;
use App\Http\Middleware\CustomVerifyCsrfToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'api/*'
        ]);

        $middleware->alias([
            'marchand.active' => CheckMarchandActive::class,
        ]);

        // Retourne un tableau contenant ton middleware personnalisé
        return [
            CustomVerifyCsrfToken::class,
            // Ajoute d'autres middlewares si besoin ici
        ];

        $middleware->appendToGroup('api', [
            EnsureFrontendRequestsAreStateful::class,
            CheckMarchandActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
