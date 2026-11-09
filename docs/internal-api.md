# Routes entre services (/internal)

Ces routes ne passent jamais par la Gateway : un service appelle l'autre en trouvant son adresse dans Eureka.
Chaque appel porte un token de compte de service (client credentials avec le client Keycloak du service appelant, rôle INTERNAL).
Réponses en JSON camelCase ; objet introuvable -> 404 au format d'erreur commun.

Obtenir un token de service :
`POST http://localhost:8180/realms/location-voitures/protocol/openid-connect/token`
corps x-www-form-urlencoded : `grant_type=client_credentials`, `client_id=<mon-service>`, `client_secret=<son secret>`.
Le secret de chaque client est dans `infra/keycloak/location-voitures-realm.json` (champ `secret` du client) ou dans la console Keycloak, onglet Credentials.

| Service | Route | Réponse | Appelée par |
| --- | --- | --- | --- |
| user-service | GET /internal/users/{id} | {id, firstname, lastname, email, phone, role, active, license: {number, category, issueDate, expiryDate, verified} ou null} | reservation |
| vehicle-service | GET /internal/vehicles/{id} | {id, plate, brand, model, status, mileage, agencyId, category: {id, name, dailyRate, deposit}} | reservation, contract |
| reservation-service | GET /internal/reservations/{id} | {id, userId, vehicleId, pickupAgencyId, returnAgencyId, startDate, endDate, status, totalPrice, depositAmount, currency} | payment, contract, complaint |
| reservation-service | GET /internal/reservations?userId={id} | liste paginée de réservations (même format) | user (OpenFeign) |
| reservation-service | GET /internal/reservations/active?vehicleId={id} | {vehicleId, active: true/false, reservationIds: [...]} (réservations PENDING, CONFIRMED ou ONGOING) | vehicle |
| payment-service | GET /internal/payments?reservationId={id} | liste de {id, reservationId, userId, amount, currency, method, type, status, createdAt} | contract |
| contract-service | GET /internal/contracts/{id} | {id, number, reservationId, userId, vehicleId, status, signedAt} | complaint |
| ml-service | POST /internal/ml/price-predictions | entrée {categoryId, agencyId, startDate, endDate} ; sortie {dailyPrice, lowerBound, upperBound, modelVersion} | reservation |

Statuts de réservation : PENDING, CONFIRMED, ONGOING, COMPLETED, CANCELLED.
Statuts de véhicule : AVAILABLE, RENTED, MAINTENANCE, OUT_OF_SERVICE.
Statuts de paiement : PENDING, SUCCEEDED, FAILED, REFUNDED ; types : RENTAL, DEPOSIT, EXTRA_FEE, REFUND.
Statuts de contrat : ACTIVE, CLOSED.

`depositAmount` = la caution de la catégorie, copiée dans la réservation au moment où elle est créée.

## Appels de complaint-service

À la création d'une réclamation (`POST /api/complaints`), complaint-service vérifie que la réservation ou le contrat existe et appartient au client.
Il trouve l'adresse dans Eureka (`RENTAL-RESERVATION`, `RENTAL-CONTRACT`), puis appelle la route avec un token de service
(client `complaint-service`, rôle INTERNAL, gardé en cache jusqu'à 30 s avant son expiration).

Requête envoyée :

```
GET /internal/reservations/{id}      (ou /internal/contracts/{id})
Accept: application/json
Authorization: Bearer <token de service>
X-Correlation-Id: <repris de la requête reçue, ou généré>
```

Réponses attendues (les autres champs du tableau ci-dessus sont acceptés et ignorés) :

| Route | Code | Corps | Champs lus par complaint-service |
| --- | --- | --- | --- |
| GET /internal/reservations/{id} | 200 | `{"id": "<uuid>", "userId": "<uuid>", "status": "COMPLETED", ...}` | `id` (UUID), `userId` (UUID), `status` (texte non vide) |
| GET /internal/contracts/{id} | 200 | `{"id": "<uuid>", "number": "CT-2026-0001", "reservationId": "<uuid>", "userId": "<uuid>", "status": "CLOSED", ...}` | `id` (UUID), `userId` (UUID), `status` (texte non vide) |
| les deux | 404 | format d'erreur commun : `{"status": 404, "error": "NOT_FOUND", "message": "..."}` | aucun |

Ce que complaint-service répond à son appelant :

| Situation | Réponse de complaint-service |
| --- | --- |
| 200 et `userId` = l'appelant | la réclamation est créée (201) |
| 200 et `userId` = un autre client | 403 `Cette réservation appartient à un autre client` (ou `Ce contrat ...`) |
| 404 du service appelé | 404 `Réservation introuvable` (ou `Contrat introuvable`) |
| service absent d'Eureka, sans instance UP, Eureka injoignable | 503 `Service RENTAL-... indisponible : ...` |
| service injoignable (connexion refusée, délai dépassé : 3 s de connexion, 5 s au total), ou 502 / 503 / 504 du service | 503 |
| Keycloak injoignable ou client refusé (token de service impossible à obtenir) | 503 |
| JSON invalide, tableau au lieu d'un objet, champ ci-dessus absent ou mal formé | 502 `Réponse illisible du service RENTAL-...` |
| 401 / 403 du service appelé (après un nouvel essai avec un token neuf), ou autre code inattendu | 502 |

Toutes ces erreurs suivent le format commun (`docs/conventions.md`) et portent l'en-tête `X-Correlation-Id`.
Le détail réseau (adresse, message du système) reste dans le log de complaint-service.

Tant que reservation-service et contract-service n'ont pas ces routes, complaint-service tourne par défaut avec `LOOKUP_MODE=demo`
(réservations et contrats de `docs/demo-ids.md`, sans appel). `LOOKUP_MODE=eureka` active les vrais appels.
