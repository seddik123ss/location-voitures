<?php

namespace App\Providers;

use App\Eureka\EurekaRegistry;
use App\Eureka\ServiceRegistry;
use App\Events\LogReservationEventPublisher;
use App\Events\ReservationEventPublisher;
use App\Integration\Demo\DemoUserLookup;
use App\Integration\Demo\DemoVehicleLookup;
use App\Integration\Http\HttpUserLookup;
use App\Integration\Http\HttpVehicleLookup;
use App\Integration\Http\KeycloakServiceTokenProvider;
use App\Integration\Http\ServiceTokenProvider;
use App\Integration\UserLookup;
use App\Integration\VehicleLookup;
use App\Security\KeycloakJwksProvider;
use App\Security\KeySetProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public const LOOKUP_MODES = ['demo', 'eureka'];

    public function register(): void
    {
        // Les tests remplacent ce fournisseur par une clé de test (tests/Support/TestKeySetProvider).
        $this->app->singleton(KeySetProvider::class, KeycloakJwksProvider::class);
        $this->app->singleton(ServiceRegistry::class, static fn () => EurekaRegistry::fromConfig());
        $this->app->singleton(ServiceTokenProvider::class, KeycloakServiceTokenProvider::class);
        $this->app->singleton(ReservationEventPublisher::class, LogReservationEventPublisher::class);

        // LOOKUP_MODE=demo (données de docs/demo-ids.md) ou eureka (vrais appels).
        $this->app->bind(UserLookup::class, fn ($app) => $app->make(self::lookupMode() === 'demo' ? DemoUserLookup::class : HttpUserLookup::class));
        $this->app->bind(VehicleLookup::class, fn ($app) => $app->make(self::lookupMode() === 'demo' ? DemoVehicleLookup::class : HttpVehicleLookup::class));
    }

    public function boot(): void
    {
        // JSON des ressources sans enveloppe "data" : les formats de l'équipe sont des objets à plat.
        JsonResource::withoutWrapping();
    }

    public static function lookupMode(): string
    {
        $mode = (string) config('reservation.lookup_mode');
        if (! in_array($mode, self::LOOKUP_MODES, true)) {
            throw new \LogicException(sprintf('LOOKUP_MODE « %s » inconnu : valeurs possibles %s', $mode, implode(', ', self::LOOKUP_MODES)));
        }

        return $mode;
    }
}
