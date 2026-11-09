<?php

namespace App\Integration;

use App\Integration\Demo\DemoContractLookup;
use App\Integration\Demo\DemoReservationLookup;
use App\Integration\Http\HttpContractLookup;
use App\Integration\Http\HttpReservationLookup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Choisit l'implémentation selon LOOKUP_MODE :
 * - demo (par défaut) : données de docs/demo-ids.md (tests, et dev tant que les autres services ne sont pas prêts) ;
 * - eureka : appels HTTP vers RENTAL-RESERVATION et RENTAL-CONTRACT.
 */
final class LookupFactory
{
    public const MODES = ['eureka', 'demo'];

    /** @param ServiceProviderInterface<object> $lookups */
    public function __construct(
        #[Autowire(env: 'LOOKUP_MODE')] private readonly string $mode,
        #[AutowireLocator([HttpReservationLookup::class, DemoReservationLookup::class, HttpContractLookup::class, DemoContractLookup::class])]
        private readonly ServiceProviderInterface $lookups,
    ) {
        if (!in_array($mode, self::MODES, true)) {
            throw new \LogicException(sprintf('LOOKUP_MODE « %s » inconnu : valeurs possibles %s', $mode, implode(', ', self::MODES)));
        }
    }

    public function reservations(): ReservationLookup
    {
        return $this->lookups->get($this->mode === 'demo' ? DemoReservationLookup::class : HttpReservationLookup::class);
    }

    public function contracts(): ContractLookup
    {
        return $this->lookups->get($this->mode === 'demo' ? DemoContractLookup::class : HttpContractLookup::class);
    }

    public function mode(): string
    {
        return $this->mode;
    }
}
