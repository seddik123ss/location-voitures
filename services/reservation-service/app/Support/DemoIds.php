<?php

namespace App\Support;

/** UUID de docs/demo-ids.md, partagés par toutes les bases de l'équipe. */
final class DemoIds
{
    // Utilisateurs (user-service, Keycloak)
    public const CLIENT1 = 'f4d1bab3-468d-428d-b02c-b44341430bfd';
    public const CLIENT2 = '611f32de-ce7b-4927-b1c4-d113b6479d59';
    public const AGENT1 = '11c9b774-201e-4a8c-be43-6b084b52f4c7';
    public const ADMIN1 = 'fd797399-4d3a-433d-afdd-37b0dd3437cb';

    // Agences et catégories (vehicle-service)
    public const AG_TUNIS = 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e';
    public const AG_SOUSSE = '1deb1dea-ab54-436d-8ed7-c3322f782c88';
    public const CAT_ECONOMIQUE = '94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc';
    public const CAT_SUV = 'a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c';
    public const CAT_LUXE = '98d95d25-4112-4e52-8fbf-d2d908239b34';

    // Véhicules (vehicle-service)
    public const V1 = 'ecdcc60f-ed6a-4007-8622-2b568338ef7a';
    public const V2 = '9f03ab2d-97a5-4c08-a952-dc6e446370d1';
    public const V3 = 'a1154300-bea1-4072-b750-2b00cf1f0bcd';
    public const V4 = '2657e174-f016-4f0c-9485-0c63141432e3';
    public const V5 = '15073301-d2df-45b0-9055-6b34fe40c07e';
    public const V6 = '4d23ae40-9d0d-47e0-a5bc-60dc9690242e';

    // Réservations (ce service)
    public const R_PENDING = '01cb68ad-4eec-4bdd-8971-ce35381c59d1';
    public const R_CONFIRMED = '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8';
    public const R_COMPLETED = '30eeff32-a413-42fb-9845-8ede4b7f98e0';
}
