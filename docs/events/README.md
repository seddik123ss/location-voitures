# Messages RabbitMQ

Chaque message est un JSON persistant avec la même enveloppe :

```json
{ "eventId": "<uuid>", "type": "<NomDuMessage>", "occurredAt": "<instant ISO 8601 UTC>", "data": { ... } }
```

Les fichiers `<NomDuMessage>.json` de ce dossier sont des exemples complets, avec les UUID de `docs/demo-ids.md`.

## Files

| File (déclarée par le consommateur) | Producteur -> consommateur | Message(s) | Effet chez le consommateur | Publication |
| --- | --- | --- | --- | --- |
| reservation.payment_succeeded | payment -> reservation | PaymentSucceeded | si `type` = RENTAL : réservation PENDING -> CONFIRMED (les autres types sont ignorés) | dans la transaction du paiement (503 si RabbitMQ absent) |
| payment.reservation_cancelled | reservation -> payment | ReservationCancelled | un REFUND par paiement SUCCEEDED de la réservation, l'original passe en REFUNDED | dans la transaction de l'annulation |
| vehicle.contract_events | contract -> vehicle | VehicleHandedOver, VehicleReturned | statut RENTED / AVAILABLE + kilométrage | dans la transaction |
| payment.return_settled | contract -> payment | ReturnSettled | un EXTRA_FEE en PENDING par frais ; REFUND de la caution (`depositToRelease`), DEPOSIT -> REFUNDED | dans la transaction |
| reservation.contract_closed | contract -> reservation | ContractClosed | réservation -> COMPLETED, en passant par ONGOING si elle est encore CONFIRMED. Aucun message ne met une réservation en ONGOING : un AGENT ou un ADMIN le fait via l'API (`PATCH /api/reservations/{id}/status`) à la remise du véhicule | best effort (log si RabbitMQ absent) |
| ml.reservation_completed | reservation -> ml | ReservationCompleted | ligne ajoutée au jeu d'entraînement | best effort |

## Contenu de `data`

| Message | Champs |
| --- | --- |
| PaymentSucceeded | paymentId, reservationId, userId, amount, currency, type (RENTAL, DEPOSIT, EXTRA_FEE) |
| ReservationCancelled | reservationId, userId, cancelledAt, reason (facultatif) |
| VehicleHandedOver | contractId, reservationId, vehicleId, mileage, handedOverAt |
| VehicleReturned | contractId, reservationId, vehicleId, mileage, fuelLevel (0 à 1), returnedAt |
| ReturnSettled | contractId, reservationId, userId, extraFees [ {type: LATE, FUEL, MILEAGE ou DAMAGE, amount} ], depositToRelease |
| ContractClosed | contractId, reservationId, closedAt |
| ReservationCompleted | reservationId, vehicleId, categoryId, agencyId, startDate, endDate, totalPrice, bookedAt |

Montants : nombres décimaux en TND (au plus 3 décimales, par exemple 160.0 ou 12.345). Dates : `YYYY-MM-DD` ; instants : ISO 8601 en UTC.

## Règles

- Le consommateur déclare sa file (durable) au démarrage ; le producteur publie avec le nom seulement (exchange par défaut, routing key = nom de la file).
- Messages persistants (delivery mode 2), `content_type` = `application/json`.
- Consommateur : ack manuel, prefetch 1 ; en cas d'erreur, une seule remise en file, puis envoi dans `<nom>.dlq`.
- Idempotence : le consommateur garde les `eventId` déjà traités et ignore un doublon.

## Tester un consommateur sans son producteur

1. Lancer le consommateur une fois : il crée sa file.
2. Console `http://localhost:15672` (guest / guest) -> Queues and Streams -> la file -> Publish message.
3. Properties : `content_type` = `application/json` ; Delivery mode : 2 - Persistent.
4. Coller le contenu du fichier d'exemple dans Payload -> Publish message, puis vérifier le traitement (logs, base) et que la file revient à 0.
