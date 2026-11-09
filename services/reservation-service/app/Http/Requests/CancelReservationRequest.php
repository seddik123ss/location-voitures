<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/reservations/{id}/cancel : {"reason"?: "Changement de programme"} (corps facultatif). */
class CancelReservationRequest extends FormRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }

    public function messages(): array
    {
        return [
            'reason.string' => 'reason doit être un texte',
            'reason.max' => 'reason : 500 caractères au plus',
        ];
    }

    public function reason(): ?string
    {
        $reason = trim((string) $this->input('reason'));

        return $reason === '' ? null : $reason;
    }
}
