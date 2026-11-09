<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /api/reservations/{id}/status (AGENT, ADMIN) : {"status": "ONGOING", "comment"?: "..."} */
class ChangeStatusRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(ReservationStatus::values())],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'status.required' => 'status est obligatoire',
            'status.in' => 'status doit valoir '.implode(', ', ReservationStatus::values()),
            'comment.string' => 'comment doit être un texte',
            'comment.max' => 'comment : 1000 caractères au plus',
        ];
    }

    public function status(): ReservationStatus
    {
        return ReservationStatus::from($this->input('status'));
    }

    public function comment(): ?string
    {
        $comment = trim((string) $this->input('comment'));

        return $comment === '' ? null : $comment;
    }
}
