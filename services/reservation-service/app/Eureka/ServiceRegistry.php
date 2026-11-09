<?php

namespace App\Eureka;

use Rental\Eureka\EurekaUnavailable;

/**
 * Annuaire des services (Eureka). Interface du service au-dessus de shared/php-eureka,
 * pour pouvoir la remplacer dans les tests.
 */
interface ServiceRegistry
{
    /** Identifiant de cette instance, ex. host.docker.internal:rental-reservation:8103. */
    public function instanceId(): string;

    /** @throws EurekaUnavailable */
    public function register(): void;

    /** @throws EurekaUnavailable Eureka injoignable ou instance oubliée (à réenregistrer) */
    public function heartbeat(): void;

    /** @throws EurekaUnavailable */
    public function deregister(): void;

    /**
     * Adresse d'une instance UP d'un autre service, ex. http://host.docker.internal:8102.
     *
     * @throws EurekaUnavailable Eureka injoignable, service inconnu ou sans instance UP
     */
    public function resolve(string $app): string;
}
