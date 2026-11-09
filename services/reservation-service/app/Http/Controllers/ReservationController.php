<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Json;
use App\Http\Requests\CancelReservationRequest;
use App\Http\Requests\ChangeStatusRequest;
use App\Http\Requests\CreateReservationRequest;
use App\Http\Requests\ListReservationsRequest;
use App\Http\Requests\UpdateReservationRequest;
use App\Http\Resources\ReservationResource;
use App\Security\Principal;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** API publique /api/reservations (via la Gateway), rôles CLIENT, AGENT, ADMIN. */
final class ReservationController
{
    public function __construct(private readonly ReservationService $service)
    {
    }

    /** GET /api/reservations : un CLIENT ne voit que les siennes. */
    public function index(ListReservationsRequest $request): JsonResponse
    {
        $page = $this->service->list(self::principal($request), $request->filters(), $request->page(), $request->size());

        return Json::page(
            ReservationResource::collection($page->items())->resolve($request),
            $request->page(),
            $request->size(),
            $page->total(),
        );
    }

    /** GET /api/reservations/{id} : propriétaire, AGENT ou ADMIN. */
    public function show(Request $request, string $id): JsonResponse
    {
        $reservation = $this->service->findForViewer($id, self::principal($request))->load('statusHistory');

        return (new ReservationResource($reservation))->toResponse($request);
    }

    /** POST /api/reservations : 201 + Location. */
    public function store(CreateReservationRequest $request): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->create($request->booking(), $principal)->load('statusHistory');

        return (new ReservationResource($reservation))->toResponse($request)
            ->setStatusCode(201)
            ->header('Location', '/api/reservations/'.$reservation->id);
    }

    /** PUT /api/reservations/{id} : nouvelles dates ou agence de retour, en PENDING ; prix recalculé. */
    public function update(UpdateReservationRequest $request, string $id): JsonResponse
    {
        $reservation = $this->service->findForViewer($id, self::principal($request));
        $reservation = $this->service->update($reservation, $request->booking($reservation), self::principal($request));

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    /** POST /api/reservations/{id}/cancel : propriétaire, AGENT ou ADMIN ; seulement PENDING ou CONFIRMED. */
    public function cancel(CancelReservationRequest $request, string $id): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->findForViewer($id, $principal);
        $reservation = $this->service->cancel($reservation, $request->reason(), $principal->id);

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    /** PATCH /api/reservations/{id}/status : AGENT, ADMIN ; transitions autorisées seulement. */
    public function changeStatus(ChangeStatusRequest $request, string $id): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->findForViewer($id, $principal);
        $reservation = $this->service->changeStatus($reservation, $request->status(), $principal->id, $request->comment());

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    /** DELETE /api/reservations/{id} : ADMIN, réservation CANCELLED seulement ; 204. */
    public function destroy(Request $request, string $id): Response
    {
        $this->service->delete($this->service->findForViewer($id, self::principal($request)));

        return response()->noContent();
    }

    private static function principal(Request $request): Principal
    {
        return $request->attributes->get(Principal::class) ?? throw ApiException::unauthorized();
    }
}
