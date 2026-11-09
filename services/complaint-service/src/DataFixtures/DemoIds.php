<?php

namespace App\DataFixtures;

/**
 * UUID communs à toutes les bases (docs/demo-ids.md).
 * Les réclamations EXTRA_* sont propres à ce service (tests des filtres).
 */
final class DemoIds
{
    // Utilisateurs (ID Keycloak, mot de passe <nom>_pwd)
    public const CLIENT1 = 'f4d1bab3-468d-428d-b02c-b44341430bfd';
    public const CLIENT2 = '611f32de-ce7b-4927-b1c4-d113b6479d59';
    public const AGENT1 = '11c9b774-201e-4a8c-be43-6b084b52f4c7';
    public const ADMIN1 = 'fd797399-4d3a-433d-afdd-37b0dd3437cb';

    // Réservations
    public const R_PENDING = '01cb68ad-4eec-4bdd-8971-ce35381c59d1';    // client1, PENDING
    public const R_CONFIRMED = '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8';  // client1, CONFIRMED
    public const R_COMPLETED = '30eeff32-a413-42fb-9845-8ede4b7f98e0';  // client2, COMPLETED

    // Contrats
    public const C_COMPLETED = '6804f1c2-925a-4604-94d4-487853bc1cac';  // r-completed, CLOSED

    // Réclamations de docs/demo-ids.md
    public const REC_1 = 'b15d796a-8360-46da-a5e1-f9c580aac77f';        // client2, BILLING, OPEN
    public const REC_2 = 'a24608c0-180d-466c-a841-f071269b4f38';        // client2, VEHICLE, RESOLVED

    // Réclamations supplémentaires
    public const EXTRA_SERVICE = 'c3a1e5f2-7b4d-4e19-9a62-5d8f0b3c7e14';  // client1, SERVICE, IN_PROGRESS
    public const EXTRA_BILLING = 'd9f2b6a8-1c3e-4f57-8b04-6a2e9d1f5c83';  // client1, BILLING, REJECTED
    public const EXTRA_DAMAGE = 'e5b8c1d4-2f6a-4a93-b7e0-3c9d8f2a6b51';   // client2, DAMAGE_DISPUTE (contrat), OPEN

    private function __construct()
    {
    }
}
