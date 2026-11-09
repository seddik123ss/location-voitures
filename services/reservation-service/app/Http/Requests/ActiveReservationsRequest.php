<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /internal/reservations/active?vehicleId= */
class ActiveReservationsRequest extends FormRequest
{
    public function rules(): array
    {
        return ['vehicleId' => ['required', 'uuid']];
    }

    public function messages(): array
    {
        return [
            'vehicleId.required' => 'Le paramètre vehicleId est obligatoire',
            'vehicleId.uuid' => 'vehicleId doit être un UUID',
        ];
    }

    public function vehicleId(): string
    {
        return strtolower($this->query('vehicleId'));
    }
}
