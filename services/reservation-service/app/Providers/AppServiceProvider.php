<?php

namespace App\Providers;

use App\Security\KeycloakJwksProvider;
use App\Security\KeySetProvider;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Les tests remplacent ce fournisseur par une clé de test (tests/Support/TestKeySetProvider).
        $this->app->singleton(KeySetProvider::class, KeycloakJwksProvider::class);
    }

    public function boot(): void
    {
        // JSON des ressources sans enveloppe "data" : les formats de l'équipe sont des objets à plat.
        JsonResource::withoutWrapping();
    }
}
