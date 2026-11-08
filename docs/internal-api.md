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
