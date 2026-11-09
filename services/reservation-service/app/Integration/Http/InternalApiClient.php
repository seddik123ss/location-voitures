<?php

namespace App\Integration\Http;

use App\Eureka\ServiceRegistry;
use App\Exceptions\ApiException;
use App\Http\Middleware\CorrelationId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Rental\Eureka\EurekaUnavailable;

/**
 * GET vers une route /internal d'un autre service, trouvé par son nom Eureka (jamais d'URL en dur),
 * avec le token du compte de service et l'en-tête X-Correlation-Id.
 *
 * Traduction (docs/conventions.md) :
 * - 404 du service appelé -> null (l'appelant renvoie son propre 404) ;
 * - absent d'Eureka, injoignable, délai dépassé, 502 / 503 / 504, Keycloak indisponible -> 503 ;
 * - JSON illisible, autre chose qu'un objet, 401 / 403 après un nouvel essai avec un token neuf, autre code -> 502.
 */
final class InternalApiClient
{
    public function __construct(
        private readonly ServiceRegistry $registry,
        private readonly ServiceTokenProvider $tokens,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function getJson(string $app, string $path): ?array
    {
        try {
            $url = rtrim($this->registry->resolve($app), '/').$path;
        } catch (EurekaUnavailable $e) {
            Log::warning("Service $app introuvable dans Eureka : ".$e->getMessage());
            throw ApiException::serviceUnavailable("Service $app indisponible", $e);
        }

        $response = $this->send($app, $url);
        if (in_array($response->status(), [401, 403], true)) {
            // Token expiré ou révoqué côté Keycloak : un nouvel essai avec un token neuf.
            $this->tokens->invalidate();
            $response = $this->send($app, $url);
        }

        $status = $response->status();
        if ($status === 404) {
            return null;
        }
        if (in_array($status, [502, 503, 504], true)) {
            throw ApiException::serviceUnavailable("Service $app indisponible (HTTP $status)");
        }
        if (in_array($status, [401, 403], true)) {
            Log::error("$app a refusé le token de service (HTTP $status) pour GET $path");
            throw ApiException::badGateway("Le service $app a refusé le token de service (HTTP $status)");
        }
        if ($status !== 200) {
            throw ApiException::badGateway("Réponse inattendue du service $app (HTTP $status)");
        }

        try {
            $data = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::badGateway("Réponse illisible du service $app (JSON invalide)", $e);
        }
        if (! is_array($data) || array_is_list($data)) {
            throw ApiException::badGateway("Réponse illisible du service $app (objet JSON attendu)");
        }

        return $data;
    }

    private function send(string $app, string $url): Response
    {
        try {
            return Http::acceptJson()
                ->withToken($this->tokens->token())
                ->withHeaders([CorrelationId::HEADER => CorrelationId::current()])
                ->connectTimeout((int) config('reservation.http_connect_timeout'))
                ->timeout((int) config('reservation.http_timeout'))
                ->get($url);
        } catch (ConnectionException $e) {
            // Détail réseau (adresse, message du système) dans le log seulement.
            Log::warning("Service $app injoignable ($url) : ".$e->getMessage());
            throw ApiException::serviceUnavailable("Service $app injoignable", $e);
        }
    }
}
