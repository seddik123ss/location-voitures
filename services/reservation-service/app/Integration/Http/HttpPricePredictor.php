<?php

namespace App\Integration\Http;

use App\Integration\InvalidPayload;
use App\Integration\PricePrediction;
use App\Integration\PricePredictor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * POST /internal/ml/price-predictions sur RENTAL-ML (LOOKUP_MODE=eureka), entrée {categoryId, agencyId, startDate, endDate}.
 * Le prix conseillé n'est qu'une information : toute erreur (service absent, réponse illisible) donne null
 * et le prix fixe s'applique.
 */
final class HttpPricePredictor implements PricePredictor
{
    public const APP = 'RENTAL-ML';

    public function __construct(private readonly InternalApiClient $client)
    {
    }

    public function predict(string $categoryId, string $agencyId, CarbonImmutable $startDate, CarbonImmutable $endDate): ?PricePrediction
    {
        try {
            $data = $this->client->postJson(self::APP, '/internal/ml/price-predictions', [
                'categoryId' => $categoryId,
                'agencyId' => $agencyId,
                'startDate' => $startDate->toDateString(),
                'endDate' => $endDate->toDateString(),
            ]);

            return $data === null ? null : PricePrediction::fromPayload($data);
        } catch (InvalidPayload|\App\Exceptions\ApiException $e) {
            Log::warning('Prix conseillé indisponible, repli sur le prix fixe : '.$e->getMessage());

            return null;
        }
    }
}
