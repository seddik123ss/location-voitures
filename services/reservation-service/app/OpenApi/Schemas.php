<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/** Schémas du contrat OpenAPI (components/schemas). Classe séparée : swagger-php imbriquerait sinon les schémas dans les paramètres. */
#[OA\Schema(
    schema: 'Error',
    required: ['status', 'error', 'message'],
    properties: [
        new OA\Property(property: 'status', type: 'integer', example: 409),
        new OA\Property(property: 'error', type: 'string', example: 'CONFLICT'),
        new OA\Property(property: 'message', type: 'string', example: 'Ce véhicule est déjà réservé sur ces dates'),
        new OA\Property(property: 'details', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'field', type: 'string', example: 'startDate'),
            new OA\Property(property: 'message', type: 'string', example: 'Déjà réservé du 2026-12-10 au 2026-12-13'),
        ], type: 'object')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ReservationStatus',
    type: 'string',
    enum: ['PENDING', 'CONFIRMED', 'ONGOING', 'COMPLETED', 'CANCELLED'],
    example: 'CONFIRMED',
)]
#[OA\Schema(
    schema: 'InternalReservation',
    description: 'Format de docs/internal-api.md',
    required: ['id', 'userId', 'vehicleId', 'pickupAgencyId', 'returnAgencyId', 'startDate', 'endDate', 'status', 'totalPrice', 'depositAmount', 'currency'],
    properties: [
        new OA\Property(property: 'id', type: 'string', format: 'uuid', example: '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8'),
        new OA\Property(property: 'userId', type: 'string', format: 'uuid', example: 'f4d1bab3-468d-428d-b02c-b44341430bfd'),
        new OA\Property(property: 'vehicleId', type: 'string', format: 'uuid', example: '9f03ab2d-97a5-4c08-a952-dc6e446370d1'),
        new OA\Property(property: 'pickupAgencyId', type: 'string', format: 'uuid', example: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e'),
        new OA\Property(property: 'returnAgencyId', type: 'string', format: 'uuid', example: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date', example: '2026-11-20'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', example: '2026-11-25'),
        new OA\Property(property: 'status', ref: '#/components/schemas/ReservationStatus'),
        new OA\Property(property: 'totalPrice', description: 'jours x dailyRate de la catégorie, 3 décimales', type: 'number', example: 450.0),
        new OA\Property(property: 'depositAmount', description: 'caution de la catégorie, copiée à la création', type: 'number', example: 500.0),
        new OA\Property(property: 'currency', type: 'string', example: 'TND'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'StatusHistoryEntry',
    properties: [
        new OA\Property(property: 'fromStatus', ref: '#/components/schemas/ReservationStatus', nullable: true, description: 'null pour la création'),
        new OA\Property(property: 'toStatus', ref: '#/components/schemas/ReservationStatus'),
        new OA\Property(property: 'changedAt', type: 'string', format: 'date-time', example: '2026-11-08T14:30:00Z'),
        new OA\Property(property: 'changedBy', type: 'string', format: 'uuid', nullable: true, description: 'null pour un changement automatique (message RabbitMQ)', example: null),
        new OA\Property(property: 'comment', type: 'string', nullable: true, example: 'Paiement RENTAL reçu'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Reservation',
    description: 'Format de l\'API publique : celui de /internal plus categoryId, allowedTransitions, createdAt, updatedAt ; statusHistory dans le détail seulement.',
    allOf: [
        new OA\Schema(ref: '#/components/schemas/InternalReservation'),
        new OA\Schema(properties: [
            new OA\Property(property: 'categoryId', type: 'string', format: 'uuid', example: '94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc'),
            new OA\Property(property: 'allowedTransitions', type: 'array', items: new OA\Items(ref: '#/components/schemas/ReservationStatus'), example: ['ONGOING', 'CANCELLED']),
            new OA\Property(property: 'createdAt', type: 'string', format: 'date-time', example: '2026-11-08T14:00:00Z'),
            new OA\Property(property: 'updatedAt', type: 'string', format: 'date-time', example: '2026-11-08T14:30:00Z'),
            new OA\Property(property: 'statusHistory', type: 'array', items: new OA\Items(ref: '#/components/schemas/StatusHistoryEntry')),
        ], type: 'object'),
    ],
)]
#[OA\Schema(
    schema: 'ReservationPage',
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/Reservation')),
        new OA\Property(property: 'page', type: 'integer', example: 0),
        new OA\Property(property: 'size', type: 'integer', example: 20),
        new OA\Property(property: 'total', type: 'integer', example: 2),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'InternalReservationPage',
    properties: [
        new OA\Property(property: 'items', type: 'array', items: new OA\Items(ref: '#/components/schemas/InternalReservation')),
        new OA\Property(property: 'page', type: 'integer', example: 0),
        new OA\Property(property: 'size', type: 'integer', example: 20),
        new OA\Property(property: 'total', type: 'integer', example: 2),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ActiveReservations',
    properties: [
        new OA\Property(property: 'vehicleId', type: 'string', format: 'uuid', example: 'ecdcc60f-ed6a-4007-8622-2b568338ef7a'),
        new OA\Property(property: 'active', type: 'boolean', example: true),
        new OA\Property(property: 'reservationIds', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), example: ['01cb68ad-4eec-4bdd-8971-ce35381c59d1']),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CreateReservation',
    required: ['vehicleId', 'startDate', 'endDate'],
    properties: [
        new OA\Property(property: 'vehicleId', type: 'string', format: 'uuid', example: '2657e174-f016-4f0c-9485-0c63141432e3'),
        new OA\Property(property: 'startDate', description: "Au plus tôt aujourd'hui (heure de Tunis)", type: 'string', format: 'date', example: '2026-12-01'),
        new OA\Property(property: 'endDate', description: 'Après startDate ; jours facturés = endDate - startDate', type: 'string', format: 'date', example: '2026-12-04'),
        new OA\Property(property: 'pickupAgencyId', description: "Facultatif ; doit être l'agence du véhicule", type: 'string', format: 'uuid', nullable: true, example: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e'),
        new OA\Property(property: 'returnAgencyId', description: "Facultatif ; par défaut l'agence de départ", type: 'string', format: 'uuid', nullable: true, example: '1deb1dea-ab54-436d-8ed7-c3322f782c88'),
        new OA\Property(property: 'userId', description: "Client concerné : obligatoire pour un AGENT ou un ADMIN ; pour un CLIENT, absent ou égal à son propre id", type: 'string', format: 'uuid', nullable: true, example: null),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'UpdateReservation',
    required: ['startDate', 'endDate'],
    properties: [
        new OA\Property(property: 'startDate', type: 'string', format: 'date', example: '2026-12-10'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', example: '2026-12-15'),
        new OA\Property(property: 'returnAgencyId', type: 'string', format: 'uuid', nullable: true, example: '1deb1dea-ab54-436d-8ed7-c3322f782c88'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'ChangeStatus',
    required: ['status'],
    properties: [
        new OA\Property(property: 'status', ref: '#/components/schemas/ReservationStatus'),
        new OA\Property(property: 'comment', type: 'string', maxLength: 1000, nullable: true, example: 'Véhicule remis au client'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'CancelReservation',
    properties: [
        new OA\Property(property: 'reason', type: 'string', maxLength: 500, nullable: true, example: 'Changement de programme'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'Quote',
    properties: [
        new OA\Property(property: 'vehicleId', type: 'string', format: 'uuid', example: '2657e174-f016-4f0c-9485-0c63141432e3'),
        new OA\Property(property: 'categoryId', type: 'string', format: 'uuid', example: 'a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c'),
        new OA\Property(property: 'categoryName', type: 'string', example: 'SUV'),
        new OA\Property(property: 'pickupAgencyId', type: 'string', format: 'uuid', example: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e'),
        new OA\Property(property: 'returnAgencyId', type: 'string', format: 'uuid', example: 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e'),
        new OA\Property(property: 'startDate', type: 'string', format: 'date', example: '2026-12-01'),
        new OA\Property(property: 'endDate', type: 'string', format: 'date', example: '2026-12-04'),
        new OA\Property(property: 'days', type: 'integer', example: 3),
        new OA\Property(property: 'dailyRate', type: 'number', example: 160.0),
        new OA\Property(property: 'totalPrice', description: 'Prix facturé (prix fixe)', type: 'number', example: 480.0),
        new OA\Property(property: 'depositAmount', type: 'number', example: 1000.0),
        new OA\Property(property: 'currency', type: 'string', example: 'TND'),
        new OA\Property(property: 'suggestedPrice', description: 'Prix conseillé par ml-service, null si le modèle est indisponible (le prix fixe s\'applique)', properties: [
            new OA\Property(property: 'dailyPrice', type: 'number', example: 152.5),
            new OA\Property(property: 'totalPrice', type: 'number', example: 457.5),
            new OA\Property(property: 'lowerBound', type: 'number', example: 140.0),
            new OA\Property(property: 'upperBound', type: 'number', example: 171.25),
            new OA\Property(property: 'modelVersion', type: 'string', example: 'v1-2026-11'),
        ], type: 'object', nullable: true),
    ],
    type: 'object',
)]
final class Schemas
{
}
