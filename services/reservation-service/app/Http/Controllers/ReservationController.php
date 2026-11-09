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
use OpenApi\Attributes as OA;

/** API publique /api/reservations (via la Gateway), rôles CLIENT, AGENT, ADMIN. */
final class ReservationController
{
    private const EXAMPLE = [
        'id' => '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8',
        'userId' => 'f4d1bab3-468d-428d-b02c-b44341430bfd',
        'vehicleId' => '9f03ab2d-97a5-4c08-a952-dc6e446370d1',
        'pickupAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e',
        'returnAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e',
        'startDate' => '2026-11-20',
        'endDate' => '2026-11-25',
        'status' => 'CONFIRMED',
        'totalPrice' => 450.0,
        'depositAmount' => 500.0,
        'currency' => 'TND',
        'categoryId' => '94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc',
        'allowedTransitions' => ['ONGOING', 'CANCELLED'],
        'createdAt' => '2026-11-08T14:00:00Z',
        'updatedAt' => '2026-11-08T14:30:00Z',
        'statusHistory' => [
            ['fromStatus' => null, 'toStatus' => 'PENDING', 'changedAt' => '2026-11-08T14:00:00Z', 'changedBy' => 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'comment' => null],
            ['fromStatus' => 'PENDING', 'toStatus' => 'CONFIRMED', 'changedAt' => '2026-11-08T14:30:00Z', 'changedBy' => null, 'comment' => 'Paiement RENTAL reçu'],
        ],
    ];

    private const CREATED = [
        'id' => '85269ec7-71f4-4963-a863-426b7b1318c6',
        'userId' => 'f4d1bab3-468d-428d-b02c-b44341430bfd',
        'vehicleId' => '2657e174-f016-4f0c-9485-0c63141432e3',
        'pickupAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e',
        'returnAgencyId' => '1deb1dea-ab54-436d-8ed7-c3322f782c88',
        'startDate' => '2026-12-01',
        'endDate' => '2026-12-04',
        'status' => 'PENDING',
        'totalPrice' => 480.0,
        'depositAmount' => 1000.0,
        'currency' => 'TND',
        'categoryId' => 'a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c',
        'allowedTransitions' => ['CONFIRMED', 'CANCELLED'],
        'createdAt' => '2026-11-09T10:00:00Z',
        'updatedAt' => '2026-11-09T10:00:00Z',
        'statusHistory' => [
            ['fromStatus' => null, 'toStatus' => 'PENDING', 'changedAt' => '2026-11-09T10:00:00Z', 'changedBy' => 'f4d1bab3-468d-428d-b02c-b44341430bfd', 'comment' => null],
        ],
    ];

    public function __construct(private readonly ReservationService $service)
    {
    }

    #[OA\Get(
        path: '/api/reservations',
        operationId: 'listReservations',
        summary: 'Lister les réservations',
        description: 'Liste paginée, plus récentes d\'abord (date de début décroissante). Un CLIENT ne voit que les siennes '
            .'(filtrer sur un autre userId : 403) ; AGENT et ADMIN voient tout et peuvent filtrer.',
        security: [['bearerAuth' => []]],
        tags: ['Réservations'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/Page'),
            new OA\Parameter(ref: '#/components/parameters/Size'),
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(ref: '#/components/schemas/ReservationStatus')),
            new OA\Parameter(name: 'userId', description: 'Client (AGENT, ADMIN ; un CLIENT ne peut donner que son propre id)', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'vehicleId', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(ref: '#/components/parameters/CorrelationId'),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Page de réservations (sans statusHistory)', content: new OA\JsonContent(ref: '#/components/schemas/ReservationPage', example: [
                'items' => [[
                    'id' => '01cb68ad-4eec-4bdd-8971-ce35381c59d1', 'userId' => 'f4d1bab3-468d-428d-b02c-b44341430bfd',
                    'vehicleId' => 'ecdcc60f-ed6a-4007-8622-2b568338ef7a', 'pickupAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e',
                    'returnAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e', 'startDate' => '2026-12-10', 'endDate' => '2026-12-13',
                    'status' => 'PENDING', 'totalPrice' => 270.0, 'depositAmount' => 500.0, 'currency' => 'TND',
                    'categoryId' => '94236cd0-16fc-41e0-bfc2-c8b1d2c8b1fc', 'allowedTransitions' => ['CONFIRMED', 'CANCELLED'],
                    'createdAt' => '2026-11-09T08:00:00Z', 'updatedAt' => '2026-11-09T08:00:00Z',
                ]],
                'page' => 0, 'size' => 1, 'total' => 2,
            ])),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Rôle absent, ou CLIENT qui filtre sur un autre client', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Un client ne peut voir que ses propres réservations',
            ])),
        ],
    )]
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

    #[OA\Get(
        path: '/api/reservations/{id}',
        operationId: 'getReservation',
        summary: 'Détail d\'une réservation',
        description: 'Avec l\'historique des statuts et les transitions possibles. Propriétaire, AGENT ou ADMIN.',
        security: [['bearerAuth' => []]],
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Réservation', content: new OA\JsonContent(ref: '#/components/schemas/Reservation', example: self::EXAMPLE)),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
        ],
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $reservation = $this->service->findForViewer($id, self::principal($request))->load('statusHistory');

        return (new ReservationResource($reservation))->toResponse($request);
    }

    #[OA\Post(
        path: '/api/reservations/quote',
        operationId: 'quoteReservation',
        summary: 'Calculer le prix d\'une réservation (devis)',
        description: 'Applique les mêmes contrôles que la création (dates, client, permis, véhicule, chevauchement) sans rien créer. '
            .'Renvoie le prix fixe facturé (jours x dailyRate) et le prix conseillé par ml-service (RENTAL-ML), '
            .'ou null si le modèle est indisponible.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateReservation', example: [
            'vehicleId' => '2657e174-f016-4f0c-9485-0c63141432e3', 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
        ])),
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Devis', content: new OA\JsonContent(ref: '#/components/schemas/Quote', example: [
                'vehicleId' => '2657e174-f016-4f0c-9485-0c63141432e3', 'categoryId' => 'a6ec349d-0ff0-4cb1-bfb9-453dd8e2ee8c', 'categoryName' => 'SUV',
                'pickupAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e', 'returnAgencyId' => 'eb825ac4-855b-499b-b37a-b4c37bbd6b4e',
                'startDate' => '2026-12-01', 'endDate' => '2026-12-04', 'days' => 3, 'dailyRate' => 160.0, 'totalPrice' => 480.0,
                'depositAmount' => 1000.0, 'currency' => 'TND',
                'suggestedPrice' => ['dailyPrice' => 152.5, 'totalPrice' => 457.5, 'lowerBound' => 140.0, 'upperBound' => 171.25, 'modelVersion' => 'v1-2026-11'],
            ])),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 404, description: 'Client ou véhicule introuvable', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Véhicule introuvable'])),
            new OA\Response(response: 409, description: 'Véhicule hors service ou déjà réservé', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 409, 'error' => 'CONFLICT', 'message' => 'Ce véhicule est déjà réservé sur ces dates',
                'details' => [['field' => 'startDate', 'message' => 'Déjà réservé du 2026-12-10 au 2026-12-13']],
            ])),
            new OA\Response(response: 422, description: 'Permis absent, non vérifié ou expiré', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 422, 'error' => 'UNPROCESSABLE_ENTITY', 'message' => 'Le permis du client expire le 2026-06-30, avant la fin de la location (2026-12-04)',
                'details' => [['field' => 'endDate', 'message' => "Permis valide jusqu'au 2026-06-30"]],
            ])),
            new OA\Response(ref: '#/components/responses/BadGateway', response: 502),
            new OA\Response(ref: '#/components/responses/ServiceUnavailable', response: 503),
        ],
    )]
    public function quote(CreateReservationRequest $request): JsonResponse
    {
        return Json::response($this->service->quote($request->booking()));
    }

    #[OA\Post(
        path: '/api/reservations',
        operationId: 'createReservation',
        summary: 'Réserver un véhicule',
        description: "Crée une réservation PENDING. Contrôles, dans l'ordre : dates (400) ; client existant (404) ; compte actif, "
            ."permis vérifié et valide jusqu'à endDate (422) ; véhicule existant (404), ni MAINTENANCE ni OUT_OF_SERVICE (409) ; "
            ."agence de départ = agence du véhicule (422) ; pas de chevauchement avec une réservation PENDING, CONFIRMED ou ONGOING "
            ."du véhicule (409). totalPrice = (endDate - startDate) jours x dailyRate de la catégorie ; depositAmount = caution de la catégorie.",
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CreateReservation', examples: [
            new OA\Examples(example: 'client', summary: 'Un CLIENT réserve pour lui-même', value: [
                'vehicleId' => '2657e174-f016-4f0c-9485-0c63141432e3', 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
                'returnAgencyId' => '1deb1dea-ab54-436d-8ed7-c3322f782c88',
            ]),
            new OA\Examples(example: 'agent', summary: 'Un AGENT réserve pour un client', value: [
                'vehicleId' => '2657e174-f016-4f0c-9485-0c63141432e3', 'startDate' => '2026-12-01', 'endDate' => '2026-12-04',
                'userId' => 'f4d1bab3-468d-428d-b02c-b44341430bfd',
            ]),
        ])),
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(
                response: 201,
                description: 'Réservation créée',
                headers: [new OA\Header(header: 'Location', description: 'URL de la réservation', schema: new OA\Schema(type: 'string', example: '/api/reservations/85269ec7-71f4-4963-a863-426b7b1318c6'))],
                content: new OA\JsonContent(ref: '#/components/schemas/Reservation', example: self::CREATED),
            ),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'CLIENT qui réserve pour un autre', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Un client ne peut réserver que pour lui-même'])),
            new OA\Response(response: 404, description: 'Client ou véhicule introuvable', content: new OA\JsonContent(ref: '#/components/schemas/Error', examples: [
                new OA\Examples(example: 'client', summary: 'Client inconnu de user-service', value: ['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Client introuvable']),
                new OA\Examples(example: 'vehicule', summary: 'Véhicule inconnu de vehicle-service', value: ['status' => 404, 'error' => 'NOT_FOUND', 'message' => 'Véhicule introuvable']),
            ])),
            new OA\Response(response: 409, description: 'Véhicule hors service ou déjà réservé', content: new OA\JsonContent(ref: '#/components/schemas/Error', examples: [
                new OA\Examples(example: 'chevauchement', summary: 'Véhicule déjà réservé', value: [
                    'status' => 409, 'error' => 'CONFLICT', 'message' => 'Ce véhicule est déjà réservé sur ces dates',
                    'details' => [['field' => 'startDate', 'message' => 'Déjà réservé du 2026-12-10 au 2026-12-13']],
                ]),
                new OA\Examples(example: 'maintenance', summary: 'Véhicule en maintenance', value: [
                    'status' => 409, 'error' => 'CONFLICT', 'message' => "Ce véhicule n'est pas réservable (statut MAINTENANCE)",
                    'details' => [['field' => 'vehicleId', 'message' => 'Véhicule en MAINTENANCE']],
                ]),
            ])),
            new OA\Response(response: 422, description: 'Permis ou compte du client, agence de départ', content: new OA\JsonContent(ref: '#/components/schemas/Error', examples: [
                new OA\Examples(example: 'permisExpire', summary: 'Permis expiré (client2)', value: [
                    'status' => 422, 'error' => 'UNPROCESSABLE_ENTITY', 'message' => 'Le permis du client expire le 2026-06-30, avant la fin de la location (2026-12-04)',
                    'details' => [['field' => 'endDate', 'message' => "Permis valide jusqu'au 2026-06-30"]],
                ]),
                new OA\Examples(example: 'sansPermis', summary: 'Aucun permis enregistré', value: [
                    'status' => 422, 'error' => 'UNPROCESSABLE_ENTITY', 'message' => "Le client n'a pas de permis de conduire enregistré",
                    'details' => [['field' => 'license', 'message' => 'Permis absent']],
                ]),
            ])),
            new OA\Response(ref: '#/components/responses/BadGateway', response: 502),
            new OA\Response(ref: '#/components/responses/ServiceUnavailable', response: 503),
        ],
    )]
    public function store(CreateReservationRequest $request): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->create($request->booking(), $principal)->load('statusHistory');

        return (new ReservationResource($reservation))->toResponse($request)
            ->setStatusCode(201)
            ->header('Location', '/api/reservations/'.$reservation->id);
    }

    #[OA\Put(
        path: '/api/reservations/{id}',
        operationId: 'updateReservation',
        summary: 'Modifier les dates d\'une réservation',
        description: 'Remplace les dates et l\'agence de retour d\'une réservation PENDING (409 sinon). Le permis, le véhicule et le '
            .'chevauchement sont contrôlés à nouveau et le prix est recalculé. Le client, le véhicule et l\'agence de départ ne changent pas.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/UpdateReservation')),
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Réservation modifiée', content: new OA\JsonContent(ref: '#/components/schemas/Reservation')),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(response: 409, description: 'Réservation qui n\'est plus PENDING, ou nouvelles dates déjà prises', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 409, 'error' => 'CONFLICT', 'message' => 'Seule une réservation PENDING peut être modifiée (statut actuel : CONFIRMED)',
                'details' => [['field' => 'status', 'message' => 'Annulez la réservation puis créez-en une nouvelle']],
            ])),
            new OA\Response(response: 422, description: 'Permis expiré avant la nouvelle date de retour', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(ref: '#/components/responses/BadGateway', response: 502),
            new OA\Response(ref: '#/components/responses/ServiceUnavailable', response: 503),
        ],
    )]
    public function update(UpdateReservationRequest $request, string $id): JsonResponse
    {
        $reservation = $this->service->findForViewer($id, self::principal($request));
        $reservation = $this->service->update($reservation, $request->booking($reservation), self::principal($request));

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    #[OA\Post(
        path: '/api/reservations/{id}/cancel',
        operationId: 'cancelReservation',
        summary: 'Annuler une réservation',
        description: 'Possible seulement en PENDING ou CONFIRMED (409 sinon). Le message ReservationCancelled est publié vers '
            .'payment-service (remboursements) dans la même transaction : si RabbitMQ ne le confirme pas, rien n\'est annulé (503).',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/CancelReservation')),
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Réservation annulée', content: new OA\JsonContent(ref: '#/components/schemas/Reservation')),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(ref: '#/components/responses/Forbidden', response: 403),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(response: 409, description: 'Réservation ONGOING, COMPLETED ou déjà annulée', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 409, 'error' => 'CONFLICT', 'message' => 'Annulation impossible : la réservation est COMPLETED (seulement en PENDING ou CONFIRMED)',
                'details' => [['field' => 'status', 'message' => 'Statut COMPLETED final : aucune transition possible']],
            ])),
            new OA\Response(response: 503, description: 'RabbitMQ indisponible ou file de payment-service absente', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 503, 'error' => 'SERVICE_UNAVAILABLE',
                'message' => "Annulation impossible pour le moment : La file payment.reservation_cancelled n'existe pas encore dans RabbitMQ (son consommateur ne l'a pas déclarée) : ReservationCancelled non publié",
            ])),
        ],
    )]
    public function cancel(CancelReservationRequest $request, string $id): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->findForViewer($id, $principal);
        $reservation = $this->service->cancel($reservation, $request->reason(), $principal->id);

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    #[OA\Patch(
        path: '/api/reservations/{id}/status',
        operationId: 'changeReservationStatus',
        summary: 'Changer le statut (AGENT, ADMIN)',
        description: 'Transitions autorisées : PENDING -> CONFIRMED ou CANCELLED ; CONFIRMED -> ONGOING (remise du véhicule) ou CANCELLED ; '
            .'ONGOING -> COMPLETED. COMPLETED et CANCELLED sont finaux. CANCELLED publie ReservationCancelled, COMPLETED publie ReservationCompleted.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ChangeStatus', example: ['status' => 'ONGOING', 'comment' => 'Véhicule remis au client'])),
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 200, description: 'Statut changé', content: new OA\JsonContent(ref: '#/components/schemas/Reservation')),
            new OA\Response(ref: '#/components/responses/BadRequest', response: 400),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Rôle AGENT ou ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Rôle requis : AGENT ou ADMIN'])),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(ref: '#/components/responses/TransitionConflict', response: 409),
            new OA\Response(ref: '#/components/responses/ServiceUnavailable', response: 503),
        ],
    )]
    public function changeStatus(ChangeStatusRequest $request, string $id): JsonResponse
    {
        $principal = self::principal($request);
        $reservation = $this->service->findForViewer($id, $principal);
        $reservation = $this->service->changeStatus($reservation, $request->status(), $principal->id, $request->comment());

        return (new ReservationResource($reservation->load('statusHistory')))->toResponse($request);
    }

    #[OA\Delete(
        path: '/api/reservations/{id}',
        operationId: 'deleteReservation',
        summary: 'Supprimer une réservation annulée (ADMIN)',
        description: 'Suppression définitive, avec son historique. Seulement une réservation CANCELLED : les autres sont référencées '
            .'par les paiements, contrats et réclamations.',
        security: [['bearerAuth' => []]],
        tags: ['Réservations'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/ReservationId'), new OA\Parameter(ref: '#/components/parameters/CorrelationId')],
        responses: [
            new OA\Response(response: 204, description: 'Supprimée'),
            new OA\Response(ref: '#/components/responses/Unauthorized', response: 401),
            new OA\Response(response: 403, description: 'Rôle ADMIN requis', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: ['status' => 403, 'error' => 'FORBIDDEN', 'message' => 'Rôle requis : ADMIN'])),
            new OA\Response(ref: '#/components/responses/NotFound', response: 404),
            new OA\Response(response: 409, description: 'Réservation non annulée', content: new OA\JsonContent(ref: '#/components/schemas/Error', example: [
                'status' => 409, 'error' => 'CONFLICT', 'message' => 'Seule une réservation CANCELLED peut être supprimée (statut actuel : COMPLETED)',
                'details' => [['field' => 'status', 'message' => "Les paiements, contrats et réclamations s'y réfèrent encore"]],
            ])),
        ],
    )]
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
