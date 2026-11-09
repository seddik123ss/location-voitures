<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Exceptions\ApiException;
use App\Http\Json;
use App\Http\Requests\ActiveReservationsRequest;
use App\Http\Requests\InternalReservationsByUserRequest;
use App\Http\Resources\InternalReservationResource;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * Routes entre services (docs/internal-api.md) : jamais routées par la Gateway,
 * appelées avec un token de compte de service portant le rôle INTERNAL.
 */
final class InternalReservationController
{
    /** GET /internal/reservations/{id} : payment, contract, complaint. */
    #[OA\Get(
        path: '/internal/reservations/{id}',
        operationId: 'internalGetReservation',
        summary: 'Réservation pour un autre service',
        description: 'Appelée par payment-service (avant chaque paiement : totalPrice = paiement RENTAL, depositAmount = paiement DEPOSIT), contract-service et complaint-service. Jamais exposée par la Gateway.',
        security: [['bearerAuth' => []]],
        tags: ['Interne'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Réservation (format de docs/internal-api.md)', content: new OA\JsonContent(ref: '#/components/schemas/InternalReservation')),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Token sans le rôle INTERNAL', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Rôle requis : INTERNAL'])),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function show(string $id): JsonResponse
    {
        $reservation = Str::isUuid($id) ? Reservation::query()->find(strtolower($id)) : null;
        if ($reservation === null) {
            throw ApiException::notFound('Réservation introuvable');
        }

        return (new InternalReservationResource($reservation))->response();
    }

    /** GET /internal/reservations?userId= : user-service ; plus récentes d'abord. */
    #[OA\Get(
        path: '/internal/reservations',
        operationId: 'internalListReservationsByUser',
        summary: 'Réservations d\x27un utilisateur',
        description: 'Liste paginée, plus récentes d\x27abord. Appelée par user-service (OpenFeign) pour l\x27historique d\x27un client.',
        security: [['bearerAuth' => []]],
        tags: ['Interne'],
        parameters: [
            new OA\Parameter(name: 'userId', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'f4d1bab3-468d-428d-b02c-b44341430bfd'),
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/Size'),
            new OA\Parameter(ref: '#/components/parameters/CorrelationId'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page de réservations', content: new OA\JsonContent(ref: '#/components/schemas/InternalReservationPage')),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Token sans le rôle INTERNAL', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Rôle requis : INTERNAL'])),
        ],
    )]
    public function byUser(InternalReservationsByUserRequest $request): JsonResponse
    {
        $query = Reservation::query()->where('user_id', $request->userId());
        $total = (clone $query)->count();
        $items = $query
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->offset($request->page() * $request->size())
            ->limit($request->size())
            ->get();

        return Json::page(
            InternalReservationResource::collection($items)->resolve($request),
            $request->page(),
            $request->size(),
            $total,
        );
    }

    /** GET /internal/reservations/active?vehicleId= : vehicle-service ; PENDING, CONFIRMED ou ONGOING. */
    #[OA\Get(
        path: '/internal/reservations/active',
        operationId: 'internalActiveReservations',
        summary: 'Réservations actives d\x27un véhicule',
        description: 'Réservations PENDING, CONFIRMED ou ONGOING du véhicule (elles le bloquent). Appelée par vehicle-service avant de retirer un véhicule ou de le passer hors service.',
        security: [['bearerAuth' => []]],
        tags: ['Interne'],
        parameters: [
            new OA\Parameter(name: 'vehicleId', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'), example: 'ecdcc60f-ed6a-4007-8622-2b568338ef7a'),
            new OA\Parameter(ref: '#/components/parameters/CorrelationId'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Réservations actives', content: new OA\JsonContent(ref: '#/components/schemas/ActiveReservations')),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Token sans le rôle INTERNAL', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Rôle requis : INTERNAL'])),
        ],
    )]
    public function active(ActiveReservationsRequest $request): JsonResponse
    {
        $ids = Reservation::query()
            ->where('vehicle_id', $request->vehicleId())
            ->whereIn('status', ReservationStatus::activeValues())
            ->orderBy('start_date')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return Json::response([
            'vehicleId' => $request->vehicleId(),
            'active' => $ids !== [],
            'reservationIds' => $ids,
        ]);
    }
}
