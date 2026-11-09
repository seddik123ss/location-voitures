<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * X-Correlation-Id (docs/conventions.md) : repris tel quel s'il arrive avec la requête, sinon généré.
 * Renvoyé dans la réponse (CorrelationIdListener) et retransmis aux appels entre services.
 */
final class CorrelationId
{
    public const HEADER = 'X-Correlation-Id';
    public const ATTRIBUTE = '_correlation_id';

    private ?string $outsideRequest = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function current(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            // Commande console : un identifiant pour toute l'exécution.
            return $this->outsideRequest ??= Uuid::v4()->toRfc4122();
        }
        if (!$request->attributes->has(self::ATTRIBUTE)) {
            $received = trim((string) $request->headers->get(self::HEADER));
            // Repris tel quel ; borné pour ne pas retransmettre n'importe quoi.
            $request->attributes->set(self::ATTRIBUTE, $received !== '' && strlen($received) <= 128 && preg_match('/^[\x21-\x7E]+$/', $received) === 1 ? $received : Uuid::v4()->toRfc4122());
        }

        return $request->attributes->get(self::ATTRIBUTE);
    }
}
