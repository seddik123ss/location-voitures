<?php

namespace App\Integration\Http;

use App\Exception\ApiException;
use App\Http\CorrelationId;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Appels GET vers les routes /internal/** des autres services (docs/internal-api.md) :
 * adresse trouvée dans Eureka, jeton de service, X-Correlation-Id retransmis.
 *
 * Traduction des erreurs (docs/conventions.md) :
 * - 404 du service appelé -> null (l'appelant décide : 404 « introuvable »)
 * - Eureka injoignable / aucune instance UP / service injoignable / 502, 503, 504 du service -> 503
 * - réponse illisible, 401 / 403 persistants (jeton refusé), autre code inattendu -> 502
 */
final class InternalApiClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ServiceResolver $resolver,
        private readonly ServiceTokenProvider $tokens,
        private readonly CorrelationId $correlationId,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>|null corps JSON (objet), ou null si le service répond 404
     *
     * @throws ApiException 502 ou 503
     */
    public function getJson(string $app, string $path): ?array
    {
        $url = $this->resolver->baseUrl($app) . $path;

        $response = $this->send($app, $url);
        if (in_array($response->getStatusCode(), [401, 403], true)) {
            // Jeton expiré ou révoqué côté Keycloak : un nouvel essai avec un jeton neuf.
            $this->tokens->invalidate();
            $response = $this->send($app, $url);
        }

        try {
            $status = $response->getStatusCode();
            if ($status === 404) {
                return null;
            }
            if (in_array($status, [502, 503, 504], true)) {
                throw ApiException::serviceUnavailable(sprintf('Service %s indisponible (HTTP %d)', $app, $status));
            }
            if (in_array($status, [401, 403], true)) {
                $this->logger->error(sprintf('%s a refusé le jeton de service (HTTP %d) pour GET %s', $app, $status, $path));
                throw ApiException::badGateway(sprintf('Le service %s a refusé le jeton de service (HTTP %d)', $app, $status));
            }
            if ($status !== 200) {
                throw ApiException::badGateway(sprintf('Réponse inattendue du service %s (HTTP %d)', $app, $status));
            }
            $content = $response->getContent(false);
        } catch (HttpException $e) {
            $this->logger->warning(sprintf('Service %s interrompu pendant la lecture (%s) : %s', $app, $url, $e->getMessage()));
            throw ApiException::serviceUnavailable(sprintf('Service %s injoignable', $app), $e);
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw ApiException::badGateway(sprintf('Réponse illisible du service %s (JSON invalide)', $app), $e);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw ApiException::badGateway(sprintf('Réponse illisible du service %s (objet JSON attendu)', $app));
        }

        return $data;
    }

    private function send(string $app, string $url): ResponseInterface
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $this->tokens->token(),
                    CorrelationId::HEADER => $this->correlationId->current(),
                ],
                'timeout' => 3,
                'max_duration' => 5,
            ]);
            $response->getStatusCode(); // force la connexion : erreurs réseau traitées ici

            return $response;
        } catch (HttpException $e) {
            // Détail réseau (adresse, message du système) dans le log seulement, pas dans la réponse.
            $this->logger->warning(sprintf('Service %s injoignable (%s) : %s', $app, $url, $e->getMessage()));
            throw ApiException::serviceUnavailable(sprintf('Service %s injoignable', $app), $e);
        }
    }
}
