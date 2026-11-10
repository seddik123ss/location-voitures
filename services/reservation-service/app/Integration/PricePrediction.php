<?php

namespace App\Integration;

/** POST /internal/ml/price-predictions de ml-service : {dailyPrice, lowerBound, upperBound, modelVersion}. */
final class PricePrediction
{
    public function __construct(
        public readonly string $dailyPrice,
        public readonly string $lowerBound,
        public readonly string $upperBound,
        public readonly string $modelVersion,
    ) {
    }

    /** @throws InvalidPayload */
    public static function fromPayload(array $data): self
    {
        return new self(
            Payload::amount($data, 'dailyPrice'),
            Payload::amount($data, 'lowerBound'),
            Payload::amount($data, 'upperBound'),
            is_int($data['modelVersion'] ?? null) ? (string) $data['modelVersion'] : Payload::string($data, 'modelVersion'),
        );
    }
}
