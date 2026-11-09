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

/**
 * Routes entre services (docs/internal-api.md) : jamais routées par la Gateway,
 * appelées avec un token de compte de service portant le rôle INTERNAL.
 */
final class InternalReservationController
{
    /** GET /internal/reservations/{id} : payment, contract, complaint. */
    public function show(string $id): JsonResponse
    {
        $reservation = Str::isUuid($id) ? Reservation::query()->find(strtolower($id)) : null;
        if ($reservation === null) {
            throw ApiException::notFound('Réservation introuvable');
        }

        return (new InternalReservationResource($reservation))->response();
    }

    /** GET /internal/reservations?userId= : user-service ; plus récentes d'abord. */
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
