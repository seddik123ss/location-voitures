<?php

namespace App\Integration\Http;

use App\Exceptions\ApiException;
use App\Integration\InvalidPayload;
use App\Integration\UserInfo;
use App\Integration\UserLookup;

/** GET /internal/users/{id} sur RENTAL-USER (LOOKUP_MODE=eureka). */
final class HttpUserLookup implements UserLookup
{
    public const APP = 'RENTAL-USER';

    public function __construct(private readonly InternalApiClient $client)
    {
    }

    public function find(string $userId): ?UserInfo
    {
        $data = $this->client->getJson(self::APP, '/internal/users/'.rawurlencode(strtolower($userId)));
        if ($data === null) {
            return null;
        }
        try {
            return UserInfo::fromPayload($data);
        } catch (InvalidPayload $e) {
            throw ApiException::badGateway('Réponse illisible du service '.self::APP.' : '.$e->getMessage(), $e);
        }
    }
}
