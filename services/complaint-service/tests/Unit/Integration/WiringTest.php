<?php

namespace App\Tests\Unit\Integration;

use App\Integration\Demo\DemoContractLookup;
use App\Integration\Demo\DemoReservationLookup;
use App\Integration\Http\EurekaServiceResolver;
use App\Integration\Http\HttpContractLookup;
use App\Integration\Http\HttpReservationLookup;
use App\Integration\LookupFactory;
use Rental\Eureka\EurekaClient;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;

/** Choix de l'implémentation (LOOKUP_MODE) et traduction des erreurs Eureka. */
final class WiringTest extends IntegrationTestCase
{
    public function testDemoModeUsesSimulatedLookups(): void
    {
        $factory = $this->factory('demo');

        self::assertInstanceOf(DemoReservationLookup::class, $factory->reservations());
        self::assertInstanceOf(DemoContractLookup::class, $factory->contracts());
    }

    public function testEurekaModeUsesHttpLookups(): void
    {
        $factory = $this->factory('eureka');

        self::assertInstanceOf(HttpReservationLookup::class, $factory->reservations());
        self::assertInstanceOf(HttpContractLookup::class, $factory->contracts());
    }

    public function testUnknownModeIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('LOOKUP_MODE « mock » inconnu');
        $this->factory('mock');
    }

    public function testEurekaUnreachableIs503(): void
    {
        // Port 9 (discard) : connexion refusée immédiatement.
        $resolver = new EurekaServiceResolver(new EurekaClient('http://127.0.0.1:9/eureka', 'RENTAL-COMPLAINT', 8106, 'localhost'));

        self::assertApiException(503, 'Service RENTAL-RESERVATION indisponible : Eureka injoignable', fn () => $resolver->baseUrl('RENTAL-RESERVATION'));
    }

    private function factory(string $mode): LookupFactory
    {
        $client = self::internalClient(new MockHttpClient());

        return new LookupFactory($mode, new ServiceLocator([
            HttpReservationLookup::class => fn () => new HttpReservationLookup($client),
            DemoReservationLookup::class => fn () => new DemoReservationLookup(),
            HttpContractLookup::class => fn () => new HttpContractLookup($client),
            DemoContractLookup::class => fn () => new DemoContractLookup(),
        ]));
    }
}
