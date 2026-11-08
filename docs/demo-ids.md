# Identifiants de démonstration

Les mêmes UUID dans toutes les bases : chaque membre les reprend tels quels dans ses données de démo (fixtures, seeders, migrations).
Les utilisateurs reprennent les ID de Keycloak (realm location-voitures). Mot de passe = `<nom>_pwd` (ex. `client1_pwd`).

## Utilisateurs (Montassar, user-service)

| Clé | UUID | Rôle | Notes |
| --- | --- | --- | --- |
| client1 | f4d1bab3-468d-428d-b02c-b44341430bfd | CLIENT | permis valide jusqu'en 2030 |
| client2 | 611f32de-ce7b-4927-b1c4-d113b6479d59 | CLIENT | permis expiré le 2026-06-30 (pour tester le 422) |
| agent1 | 11c9b774-201e-4a8c-be43-6b084b52f4c7 | AGENT | agence de Tunis |
| admin1 | fd797399-4d3a-433d-afdd-37b0dd3437cb | ADMIN | |

## Agences et catégories (Yosra, vehicle-service)

| Clé | UUID | Détails |
| --- | --- | --- |
| ag-tunis | eb825ac4-855b-499b-b37a-b4c37bbd6b4e | Agence Tunis, Avenue Habib Bourguiba |
| ag-sousse | 1deb1dea-ab54-436d-8ed7-c3322f782c88 | Agence Sousse, Boulevard du 14 Janvier |
| cat-economique | 94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc | Économique : dailyRate 90.000 TND, deposit 500.000 TND |
| cat-suv | a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c | SUV : dailyRate 160.000 TND, deposit 1000.000 TND |
| cat-luxe | 98d95d25-4112-4e52-8fbf-d2d908239b34 | Luxe : dailyRate 300.000 TND, deposit 2000.000 TND |

## Véhicules (Yosra, vehicle-service)

| Clé | UUID | Véhicule | Catégorie | Agence | Statut |
| --- | --- | --- | --- | --- | --- |
| v1 | ecdcc60f-ed6a-4007-8622-2b568338ef7a | Peugeot 208, 2024, essence, manuelle, 123 TU 4501 | économique | Tunis | AVAILABLE |
| v2 | 9f03ab2d-97a5-4c08-a952-dc6e446370d1 | Renault Clio, 2023, diesel, manuelle, 124 TU 4502 | économique | Tunis | AVAILABLE |
| v3 | a1154300-bea1-4072-b750-2b00cf1f0bcd | Kia Sportage, 2024, diesel, automatique, 125 TU 4503 | SUV | Sousse | AVAILABLE |
| v4 | 2657e174-f016-4f0c-9485-0c63141432e3 | Hyundai Tucson, 2023, essence, automatique, 126 TU 4504 | SUV | Tunis | AVAILABLE |
| v5 | 15073301-d2df-45b0-9055-6b34fe40c07e | Mercedes Classe C, 2024, diesel, automatique, 127 TU 4505 | luxe | Tunis | AVAILABLE |
| v6 | 4d23ae40-9d0d-47e0-a5bc-60dc9690242e | Volkswagen Polo, 2022, essence, manuelle, 128 TU 4506 | économique | Sousse | MAINTENANCE |

## Réservations (Aziz, reservation-service)

| Clé | UUID | Client | Véhicule | Dates | Statut | totalPrice | depositAmount |
| --- | --- | --- | --- | --- | --- | --- | --- |
| r-pending | 01cb68ad-4eec-4bdd-8971-ce35381c59d1 | client1 | v1 | 2026-12-10 au 2026-12-13 | PENDING | 270.000 | 500.000 |
| r-confirmed | 33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8 | client1 | v2 | 2026-11-20 au 2026-11-25 | CONFIRMED | 450.000 | 500.000 |
| r-completed | 30eeff32-a413-42fb-9845-8ede4b7f98e0 | client2 | v3 | 2026-10-01 au 2026-10-05 | COMPLETED | 640.000 | 1000.000 |

## Paiements (Fadi, payment-service)

| Clé | UUID | Réservation | Type | Montant | Statut |
| --- | --- | --- | --- | --- | --- |
| p-confirmed | d76b538e-e367-4bb3-966b-98a4d9dc93a5 | r-confirmed | RENTAL | 450.000 | SUCCEEDED |
| p-completed | 918e07dc-4995-4985-8373-aad04e13636f | r-completed | RENTAL | 640.000 | SUCCEEDED |

## Contrats (Rania, contract-service)

| Clé | UUID | Réservation | Statut |
| --- | --- | --- | --- |
| c-completed | 6804f1c2-925a-4604-94d4-487853bc1cac | r-completed | CLOSED |

## Réclamations (Seddik, complaint-service)

| Clé | UUID | Client | Réservation | Catégorie | Statut |
| --- | --- | --- | --- | --- | --- |
| rec-1 | b15d796a-8360-46da-a5e1-f9c580aac77f | client2 | r-completed | BILLING | OPEN |
| rec-2 | a24608c0-180d-466c-a841-f071269b4f38 | client2 | r-completed | VEHICLE | RESOLVED |
