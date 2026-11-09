<?php

namespace App\Integration\Demo;

use App\Integration\UserInfo;
use App\Integration\UserLookup;
use App\Support\DemoIds;
use Illuminate\Support\Facades\Log;

/**
 * Simulation de user-service (LOOKUP_MODE=demo) : utilisateurs de docs/demo-ids.md, au format exact de
 * GET /internal/users/{id} (données reprises du jeu de démo de user-service), lus par le même code que les vraies réponses.
 */
final class DemoUserLookup implements UserLookup
{
    public function find(string $userId): ?UserInfo
    {
        Log::info("[SIMULATION] utilisateur $userId (LOOKUP_MODE=demo)");
        $payload = self::payloads()[strtolower($userId)] ?? null;

        return $payload === null ? null : UserInfo::fromPayload($payload);
    }

    /** @return array<string, array<string, mixed>> */
    public static function payloads(): array
    {
        return [
            // client1 : permis vérifié, valide jusqu'en 2030
            DemoIds::CLIENT1 => [
                'id' => DemoIds::CLIENT1, 'firstname' => 'Client', 'lastname' => 'Un', 'email' => 'client1@test.tn',
                'phone' => '+21620111111', 'role' => 'CLIENT', 'active' => true,
                'license' => ['number' => 'TN-2020-000111', 'category' => 'B', 'issueDate' => '2020-03-15', 'expiryDate' => '2030-03-15', 'verified' => true],
            ],
            // client2 : permis expiré le 2026-06-30 (pour tester le 422)
            DemoIds::CLIENT2 => [
                'id' => DemoIds::CLIENT2, 'firstname' => 'Client', 'lastname' => 'Deux', 'email' => 'client2@test.tn',
                'phone' => '+21620222222', 'role' => 'CLIENT', 'active' => true,
                'license' => ['number' => 'TN-2016-000222', 'category' => 'B', 'issueDate' => '2016-06-30', 'expiryDate' => '2026-06-30', 'verified' => true],
            ],
            DemoIds::AGENT1 => [
                'id' => DemoIds::AGENT1, 'firstname' => 'Agent', 'lastname' => 'Un', 'email' => 'agent1@test.tn',
                'phone' => '+21671333333', 'role' => 'AGENT', 'active' => true, 'license' => null,
            ],
            DemoIds::ADMIN1 => [
                'id' => DemoIds::ADMIN1, 'firstname' => 'Admin', 'lastname' => 'Un', 'email' => 'admin1@test.tn',
                'phone' => '+21671444444', 'role' => 'ADMIN', 'active' => true, 'license' => null,
            ],
        ];
    }
}
