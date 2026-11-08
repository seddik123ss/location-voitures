<?php

namespace App\EventListener;

use App\Exception\ApiException;
use App\Exception\InvalidStatusTransition;
use App\Http\ApiResponse;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Toutes les erreurs au format commun {status, error, message, details}.
 * Priorité 0 : passe après le listener de Security (priorité 1), qui transforme
 * les refus d'accès en 401 (entry point) ou en AccessDeniedHttpException (403).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.debug%')] private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $request = $event->getRequest();

        $response = match (true) {
            $e instanceof ApiException => ApiResponse::error($e->status, $e->getMessage(), $e->details),
            $e instanceof InvalidStatusTransition => ApiResponse::error(409, $e->getMessage(), [['field' => 'status', 'message' => sprintf('Transitions possibles depuis %s : %s', $e->from->value, implode(', ', array_map(fn ($s) => $s->value, $e->from->allowedTransitions())) ?: 'aucune (état final)')]]),
            $e instanceof HttpExceptionInterface => $this->fromHttpException($e, $request->getMethod(), $request->getPathInfo()),
            default => null,
        };

        if ($response === null) {
            $this->logger->error('Erreur non gérée : ' . $e->getMessage(), ['exception' => $e]);
            $response = ApiResponse::error(500, $this->debug ? sprintf('Erreur interne : %s (%s)', $e->getMessage(), $e::class) : 'Erreur interne du serveur');
        }

        $event->setResponse($response);
    }

    private function fromHttpException(HttpExceptionInterface $e, string $method, string $path): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $status = $e->getStatusCode();
        $previous = $e instanceof \Throwable ? $e->getPrevious() : null;
        $headers = $e->getHeaders();

        if ($previous instanceof ValidationFailedException) {
            return ApiResponse::error($status, 'Requête invalide', $this->violations($previous), $headers);
        }
        if ($previous instanceof NotEncodableValueException || ($status === 400 && $previous instanceof \UnexpectedValueException)) {
            return ApiResponse::error(400, 'Corps JSON invalide', [], $headers);
        }
        if ($e instanceof NotFoundHttpException && $previous instanceof ResourceNotFoundException) {
            return ApiResponse::error(404, sprintf('Route inconnue : %s %s', $method, $path), [], $headers);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return ApiResponse::error(405, sprintf('Méthode %s non autorisée sur %s (autorisées : %s)', $method, $path, $headers['Allow'] ?? ''), [], $headers);
        }
        if ($status === 415) {
            return ApiResponse::error(415, 'Content-Type attendu : application/json', [], $headers);
        }
        if ($status === 403) {
            $message = $e instanceof \Throwable ? $e->getMessage() : '';

            return ApiResponse::error(403, $message !== '' && $message !== 'Access Denied.' ? $message : 'Accès refusé', [], $headers);
        }

        return ApiResponse::error($status, $e instanceof \Throwable && $e->getMessage() !== '' ? $e->getMessage() : 'Erreur', [], $headers);
    }

    /** @return list<array{field: string, message: string}> un seul message par champ (le premier) */
    private function violations(ValidationFailedException $e): array
    {
        $details = [];
        $seen = [];
        /** @var ConstraintViolationInterface $v */
        foreach ($e->getViolations() as $v) {
            $field = $v->getPropertyPath() !== '' ? $v->getPropertyPath() : 'body';
            if (isset($seen[$field])) {
                continue;
            }
            $seen[$field] = true;
            $message = (string) $v->getMessage();
            // Erreurs de type remontées par le serializer (en anglais) : traduites.
            if ($v->getMessageTemplate() === 'This value should be of type {{ type }}.') {
                $message = sprintf('Type invalide, attendu : %s', $v->getParameters()['{{ type }}'] ?? '?');
            }
            $details[] = ['field' => $field, 'message' => $message];
        }

        return $details;
    }
}
