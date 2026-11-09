<?php

namespace App\EventListener;

use App\Http\CorrelationId;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Fixe l'identifiant de corrélation dès l'arrivée de la requête et le renvoie dans toutes les réponses, erreurs comprises. */
final class CorrelationIdListener
{
    public function __construct(private readonly CorrelationId $correlationId)
    {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
    public function onRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->correlationId->current();
        }
    }

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(CorrelationId::HEADER, $this->correlationId->current());
        }
    }
}
