<?php

namespace App\Integration\Demo;

use App\Integration\PricePrediction;
use App\Integration\PricePredictor;
use Carbon\CarbonImmutable;

/** LOOKUP_MODE=demo : pas de modèle de prix, le prix fixe s'applique (repli prévu par la règle 7). */
final class NoPricePredictor implements PricePredictor
{
    public function predict(string $categoryId, string $agencyId, CarbonImmutable $startDate, CarbonImmutable $endDate): ?PricePrediction
    {
        return null;
    }
}
