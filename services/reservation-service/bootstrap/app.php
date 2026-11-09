<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\CorrelationId;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Routes sans préfixe : /health, /api/reservations/** (Gateway), /internal/reservations/** (entre services).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CorrelationId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Toutes les erreurs au format commun {status, error, message, details}.
        $exceptions->render(fn (Throwable $e) => ApiExceptionRenderer::render($e));
    })->create();
