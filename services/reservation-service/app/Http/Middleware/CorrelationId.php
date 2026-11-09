<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reprend l'en-tête X-Correlation-Id reçu (ou en génère un), le rend disponible pour les appels
 * sortants et les messages RabbitMQ, et le renvoie dans la réponse.
 */
final class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = self::sanitize($request->headers->get(self::HEADER)) ?? (string) Str::uuid();
        $request->attributes->set('correlationId', $id);
        app()->instance('correlationId', $id);
        logger()->withContext(['correlationId' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** Identifiant courant : celui de la requête en cours, sinon un nouveau. */
    public static function current(): string
    {
        return app()->bound('correlationId') ? app('correlationId') : (string) Str::uuid();
    }

    private static function sanitize(?string $value): ?string
    {
        $value = trim((string) $value);

        // Valeur reprise telle quelle, mais bornée pour ne pas polluer les logs.
        return $value !== '' && strlen($value) <= 128 && preg_match('/^[\w.:-]+$/', $value) ? $value : null;
    }
}
