<?php

namespace App\Integration;

use Carbon\CarbonImmutable;

/** Prix conseillé par ml-service (RENTAL-ML). Jamais bloquant : null si le modèle est indisponible. */
interface PricePredictor
{
    public function predict(string $categoryId, string $agencyId, CarbonImmutable $startDate, CarbonImmutable $endDate): ?PricePrediction;
}
