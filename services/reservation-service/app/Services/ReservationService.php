<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Events\ReservationEventPublisher;
use App\Exceptions\ApiException;
use App\Exceptions\InvalidStatusTransition;
use App\Http\Json;
use App\Integration\PricePredictor;
use App\Integration\UserLookup;
use App\Integration\VehicleInfo;
use App\Integration\VehicleLookup;
use App\Models\Reservation;
use App\Security\Principal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Règles métier des réservations. Ordre des contrôles à la création :
 * 400 (dates, dans la Form Request) -> 404 client -> 422 compte / permis -> 404 véhicule -> 409 véhicule hors service
 * -> 422 agence de départ -> 409 chevauchement. Les appels aux autres services sont faits avant la transaction,
 * pour ne pas garder de verrou pendant un appel réseau.
 */
final class ReservationService
{
    public function __construct(
        private readonly UserLookup $users,
        private readonly VehicleLookup $vehicles,
        private readonly ReservationEventPublisher $events,
        private readonly PricePredictor $prices,
    ) {
    }

    /** Réservation visible par l'appelant : 404 si elle n'existe pas, 403 si elle appartient à un autre client. */
    public function findForViewer(string $id, Principal $viewer): Reservation
    {
        $reservation = Str::isUuid($id) ? Reservation::query()->find(strtolower($id)) : null;
        if ($reservation === null) {
            throw ApiException::notFound('Réservation introuvable');
        }
        if (! $viewer->hasAnyRole('AGENT', 'ADMIN') && $reservation->user_id !== $viewer->id) {
            throw ApiException::forbidden('Cette réservation appartient à un autre client');
        }

        return $reservation;
    }

    /** @param array{status?: string, userId?: string, vehicleId?: string} $filters */
    public function list(Principal $viewer, array $filters, int $page, int $size): LengthAwarePaginator
    {
        $userId = $filters['userId'] ?? null;
        if (! $viewer->hasAnyRole('AGENT', 'ADMIN')) {
            if ($userId !== null && $userId !== $viewer->id) {
                throw ApiException::forbidden('Un client ne peut voir que ses propres réservations');
            }
            $userId = $viewer->id;
        }

        return Reservation::query()
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when(isset($filters['vehicleId']), fn ($q) => $q->where('vehicle_id', $filters['vehicleId']))
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->orderByDesc('start_date')
            ->orderBy('id')
            ->paginate(perPage: $size, page: $page + 1);
    }

    /**
     * Devis sans création (formulaire de réservation) : mêmes contrôles que create(), prix fixe facturé
     * et prix conseillé par ml-service (null si le modèle est indisponible : le prix fixe s'applique).
     *
     * @return array<string, mixed>
     */
    public function quote(BookingRequest $request): array
    {
        $this->checkCustomer($request);
        $vehicle = $this->findBookableVehicle($request->vehicleId);
        $pickup = $this->pickupAgency($request, $vehicle);
        $this->checkNoOverlap($request, lock: false);

        $prediction = $this->prices->predict($vehicle->categoryId, $pickup, $request->startDate, $request->endDate);
        $days = $request->days();

        return [
            'vehicleId' => $vehicle->id,
            'categoryId' => $vehicle->categoryId,
            'categoryName' => $vehicle->categoryName,
            'pickupAgencyId' => $pickup,
            'returnAgencyId' => $request->returnAgencyId ?? $pickup,
            'startDate' => $request->startDate->toDateString(),
            'endDate' => $request->endDate->toDateString(),
            'days' => $days,
            'dailyRate' => Json::amount($vehicle->dailyRate),
            'totalPrice' => Json::amount(self::price($request, $vehicle)),
            'depositAmount' => Json::amount($vehicle->deposit),
            'currency' => config('reservation.currency'),
            'suggestedPrice' => $prediction === null ? null : [
                'dailyPrice' => Json::amount($prediction->dailyPrice),
                'totalPrice' => Json::amount(bcmul((string) $days, $prediction->dailyPrice, 3)),
                'lowerBound' => Json::amount($prediction->lowerBound),
                'upperBound' => Json::amount($prediction->upperBound),
                'modelVersion' => $prediction->modelVersion,
            ],
        ];
    }

    public function create(BookingRequest $request, Principal $by): Reservation
    {
        $this->checkCustomer($request);
        $vehicle = $this->findBookableVehicle($request->vehicleId);
        $pickup = $this->pickupAgency($request, $vehicle);

        return DB::transaction(function () use ($request, $vehicle, $pickup, $by): Reservation {
            $this->checkNoOverlap($request);

            $reservation = Reservation::query()->create([
                'user_id' => $request->userId,
                'vehicle_id' => $vehicle->id,
                'category_id' => $vehicle->categoryId,
                'pickup_agency_id' => $pickup,
                'return_agency_id' => $request->returnAgencyId ?? $pickup,
                'start_date' => $request->startDate->toDateString(),
                'end_date' => $request->endDate->toDateString(),
                'status' => ReservationStatus::PENDING,
                'total_price' => self::price($request, $vehicle),
                'deposit_amount' => $vehicle->deposit,
                'currency' => config('reservation.currency'),
            ]);
            $reservation->recordCreation($by->id);

            return $reservation->refresh();
        });
    }

    /** Modification des dates ou de l'agence de retour, seulement en PENDING ; le prix est recalculé. */
    public function update(Reservation $reservation, BookingRequest $request, Principal $by): Reservation
    {
        if ($reservation->status !== ReservationStatus::PENDING) {
            throw ApiException::conflict(
                "Seule une réservation PENDING peut être modifiée (statut actuel : {$reservation->status->value})",
                [['field' => 'status', 'message' => 'Annulez la réservation puis créez-en une nouvelle']],
            );
        }
        $this->checkCustomer($request);
        $vehicle = $this->findBookableVehicle($request->vehicleId);

        return DB::transaction(function () use ($reservation, $request, $vehicle): Reservation {
            $this->checkNoOverlap($request, $reservation->id);
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== ReservationStatus::PENDING) {
                throw ApiException::conflict('La réservation a changé de statut pendant la modification');
            }
            $reservation->fill([
                'start_date' => $request->startDate->toDateString(),
                'end_date' => $request->endDate->toDateString(),
                'return_agency_id' => $request->returnAgencyId ?? $reservation->pickup_agency_id,
                'category_id' => $vehicle->categoryId,
                'total_price' => self::price($request, $vehicle),
                'deposit_amount' => $vehicle->deposit,
            ])->save();

            return $reservation->refresh();
        });
    }

    /** Annulation (PENDING ou CONFIRMED) ; ReservationCancelled est publié dans la même transaction. */
    public function cancel(Reservation $reservation, ?string $reason, ?string $changedBy): Reservation
    {
        if (! $reservation->status->isCancellable()) {
            throw InvalidStatusTransition::from(
                $reservation->status,
                ReservationStatus::CANCELLED,
                "Annulation impossible : la réservation est {$reservation->status->value} (seulement en PENDING ou CONFIRMED)",
            );
        }

        return $this->changeStatus($reservation, ReservationStatus::CANCELLED, $changedBy, $reason);
    }

    /**
     * Changement de statut vérifié (409 si interdit), avec l'historique et le message éventuel
     * (ReservationCancelled, ReservationCompleted) dans la même transaction.
     */
    public function changeStatus(Reservation $reservation, ReservationStatus $target, ?string $changedBy, ?string $comment = null): Reservation
    {
        return DB::transaction(function () use ($reservation, $target, $changedBy, $comment): Reservation {
            // Relecture verrouillée : deux changements simultanés ne peuvent pas partir du même statut.
            $reservation = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $reservation->transitionTo($target, $changedBy, $comment);

            match ($target) {
                // Dans la transaction : si RabbitMQ ne confirme pas, l'annulation est annulée (503).
                ReservationStatus::CANCELLED => $this->events->reservationCancelled($reservation, Carbon::now(), $comment),
                // Best effort, une fois la transaction validée : un échec de publication n'annule pas la fin de location.
                ReservationStatus::COMPLETED => DB::afterCommit(fn () => $this->events->reservationCompleted($reservation->fresh())),
                default => null,
            };

            return $reservation->refresh();
        });
    }

    /** Suppression définitive (ADMIN) : seulement une réservation annulée, les autres sont référencées ailleurs. */
    public function delete(Reservation $reservation): void
    {
        if ($reservation->status !== ReservationStatus::CANCELLED) {
            throw ApiException::conflict(
                "Seule une réservation CANCELLED peut être supprimée (statut actuel : {$reservation->status->value})",
                [['field' => 'status', 'message' => "Les paiements, contrats et réclamations s'y réfèrent encore"]],
            );
        }
        $reservation->delete();
    }

    /** Prix = jours x dailyRate de la catégorie, à 3 décimales. */
    public static function price(BookingRequest $request, VehicleInfo $vehicle): string
    {
        return bcmul((string) $request->days(), $vehicle->dailyRate, 3);
    }

    /** Règles 1 et 2 : le client existe (404), son compte est actif et son permis vérifié et valide jusqu'au retour (422). */
    private function checkCustomer(BookingRequest $request): void
    {
        $user = $this->users->find($request->userId);
        if ($user === null) {
            throw ApiException::notFound('Client introuvable');
        }
        if (! $user->active) {
            throw ApiException::unprocessable('Le compte de ce client est désactivé', [['field' => 'userId', 'message' => 'Compte désactivé']]);
        }
        $license = $user->license;
        if ($license === null) {
            throw ApiException::unprocessable('Le client n\'a pas de permis de conduire enregistré', [['field' => 'license', 'message' => 'Permis absent']]);
        }
        if (! $license->verified) {
            throw ApiException::unprocessable("Le permis du client n'a pas encore été vérifié par une agence", [['field' => 'license', 'message' => 'Permis non vérifié']]);
        }
        if (! $license->isValidUntil($request->endDate)) {
            throw ApiException::unprocessable(
                sprintf('Le permis du client expire le %s, avant la fin de la location (%s)', $license->expiryDate->toDateString(), $request->endDate->toDateString()),
                [['field' => 'endDate', 'message' => 'Permis valide jusqu\'au '.$license->expiryDate->toDateString()]],
            );
        }
    }

    /** Règles 3 et 4 : le véhicule existe (404) et n'est ni en MAINTENANCE ni OUT_OF_SERVICE (409). */
    private function findBookableVehicle(string $vehicleId): VehicleInfo
    {
        $vehicle = $this->vehicles->find($vehicleId);
        if ($vehicle === null) {
            throw ApiException::notFound('Véhicule introuvable');
        }
        if (! $vehicle->isBookable()) {
            throw ApiException::conflict(
                "Ce véhicule n'est pas réservable (statut {$vehicle->status})",
                [['field' => 'vehicleId', 'message' => 'Véhicule en '.$vehicle->status]],
            );
        }

        return $vehicle;
    }

    /** Le départ se fait à l'agence où se trouve le véhicule. */
    private function pickupAgency(BookingRequest $request, VehicleInfo $vehicle): string
    {
        if ($request->pickupAgencyId !== null && $request->pickupAgencyId !== $vehicle->agencyId) {
            throw ApiException::unprocessable(
                "Le véhicule n'est pas disponible dans cette agence de départ",
                [['field' => 'pickupAgencyId', 'message' => 'Le véhicule se trouve à l\'agence '.$vehicle->agencyId]],
            );
        }

        return $vehicle->agencyId;
    }

    /** Règle 5 : pas de réservation PENDING, CONFIRMED ou ONGOING du même véhicule sur la période (409). */
    private function checkNoOverlap(BookingRequest $request, ?string $exceptId = null, bool $lock = true): void
    {
        $conflicts = Reservation::query()
            ->overlapping($request->vehicleId, $request->startDate->toDateString(), $request->endDate->toDateString(), $exceptId)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->orderBy('start_date')
            ->get(['id', 'start_date', 'end_date']);

        if ($conflicts->isNotEmpty()) {
            throw ApiException::conflict(
                'Ce véhicule est déjà réservé sur ces dates',
                $conflicts->map(fn (Reservation $r) => [
                    'field' => 'startDate',
                    'message' => sprintf('Déjà réservé du %s au %s', $r->start_date->toDateString(), $r->end_date->toDateString()),
                ])->all(),
            );
        }
    }
}
