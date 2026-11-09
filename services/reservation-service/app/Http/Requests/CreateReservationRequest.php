<?php

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use App\Http\Requests\Concerns\ValidatesBookingDates;
use App\Security\Principal;
use App\Services\BookingRequest;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/reservations
 * {"vehicleId", "startDate", "endDate", "pickupAgencyId"?, "returnAgencyId"?, "userId"?}
 * userId : absent pour un CLIENT (c'est lui), obligatoire pour un AGENT ou un ADMIN qui réserve pour un client.
 */
class CreateReservationRequest extends FormRequest
{
    use ValidatesBookingDates;

    public function rules(): array
    {
        $staff = $this->principal()->hasAnyRole('AGENT', 'ADMIN');

        return [
            'vehicleId' => ['required', 'uuid'],
            'userId' => [$staff ? 'required' : 'nullable', 'uuid'],
            'pickupAgencyId' => ['nullable', 'uuid'],
            'returnAgencyId' => ['nullable', 'uuid'],
            ...$this->dateRules(),
        ];
    }

    public function messages(): array
    {
        return [
            'vehicleId.required' => 'vehicleId est obligatoire',
            'vehicleId.uuid' => 'vehicleId doit être un UUID',
            'userId.required' => 'userId (le client) est obligatoire quand un AGENT ou un ADMIN réserve',
            'userId.uuid' => 'userId doit être un UUID',
            'pickupAgencyId.uuid' => 'pickupAgencyId doit être un UUID',
            'returnAgencyId.uuid' => 'returnAgencyId doit être un UUID',
            ...$this->dateMessages(),
        ];
    }

    public function booking(): BookingRequest
    {
        $principal = $this->principal();
        $userId = $this->uuidOrNull('userId');
        if (! $principal->hasAnyRole('AGENT', 'ADMIN')) {
            if ($userId !== null && $userId !== $principal->id) {
                throw ApiException::forbidden('Un client ne peut réserver que pour lui-même');
            }
            $userId = $principal->id;
        }

        return new BookingRequest(
            $userId,
            $this->uuidOrNull('vehicleId'),
            $this->startDate(),
            $this->endDate(),
            $this->uuidOrNull('pickupAgencyId'),
            $this->uuidOrNull('returnAgencyId'),
        );
    }

    private function principal(): Principal
    {
        return $this->attributes->get(Principal::class);
    }
}
