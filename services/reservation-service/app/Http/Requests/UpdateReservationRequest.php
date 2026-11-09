<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesBookingDates;
use App\Models\Reservation;
use App\Services\BookingRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PUT /api/reservations/{id} : remplace les dates et l'agence de retour d'une réservation PENDING.
 * {"startDate", "endDate", "returnAgencyId"?}. Le client, le véhicule et l'agence de départ ne changent pas.
 */
class UpdateReservationRequest extends FormRequest
{
    use ValidatesBookingDates;

    public function rules(): array
    {
        return [
            'returnAgencyId' => ['nullable', 'uuid'],
            ...$this->dateRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'returnAgencyId.uuid' => 'returnAgencyId doit être un UUID',
            ...$this->dateMessages(),
        ];
    }

    public function booking(Reservation $reservation): BookingRequest
    {
        return new BookingRequest(
            $reservation->user_id,
            $reservation->vehicle_id,
            $this->startDate(),
            $this->endDate(),
            $reservation->pickup_agency_id,
            $this->uuidOrNull('returnAgencyId'),
        );
    }
}
