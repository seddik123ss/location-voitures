<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\AuthenticateKeycloak;
use App\Http\Middleware\CorrelationId;
use App\Http\Middleware\RequireRole;
use App\Support\ConfigServer;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        // Routes sans préfixe : /health, /api/reservations/** (Gateway), /internal/reservations/** (entre services).
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(CorrelationId::class);
        $middleware->alias([
            'keycloak' => AuthenticateKeycloak::class,
            'role' => RequireRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Erreurs prévues (règles métier, 502 / 503 d'un autre service) : pas de trace dans le log,
        // leur cause y est déjà écrite là où elles sont levées. Laravel journalise les autres.
        $exceptions->dontReport(ApiException::class);
        // Toutes les erreurs au format commun {status, error, message, details}.
        $exceptions->render(fn (Throwable $e) => ApiExceptionRenderer::render($e));
    })->create();

// Config Server (infra/config-repo/rental-reservation.yml) : juste après le .env, avant la lecture de config/*.php,
// remplit SERVICE_PORT, SERVICE_HOST, EUREKA_URL et KEYCLOAK_ISSUER s'ils ne sont pas déjà définis.
$app->afterBootstrapping(LoadEnvironmentVariables::class, function (Application $app): void {
    ConfigServer::fillEnvironment($app->bootstrapPath('cache/config-server.json'));
});

return $app;
