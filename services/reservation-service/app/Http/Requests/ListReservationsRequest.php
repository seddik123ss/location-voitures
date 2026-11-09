<?php

namespace App\Http\Requests;

use App\Enums\ReservationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /api/reservations?page=0&size=20&status=&userId=&vehicleId= */
class ListReservationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:0'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', Rule::in(ReservationStatus::values())],
            'userId' => ['sometimes', 'uuid'],
            'vehicleId' => ['sometimes', 'uuid'],
        ];
    }

    public function messages(): array
    {
        return [
            'page.*' => 'page doit être un entier positif ou nul',
            'size.*' => 'size doit être un entier entre 1 et 100',
            'status.in' => 'status doit valoir '.implode(', ', ReservationStatus::values()),
            'userId.uuid' => 'userId doit être un UUID',
            'vehicleId.uuid' => 'vehicleId doit être un UUID',
        ];
    }

    public function page(): int
    {
        return (int) $this->query('page', 0);
    }

    public function size(): int
    {
        return (int) $this->query('size', 20);
    }

    /** @return array{status?: string, userId?: string, vehicleId?: string} */
    public function filters(): array
    {
        return array_map('strtolower', array_filter([
            'userId' => $this->query('userId'),
            'vehicleId' => $this->query('vehicleId'),
        ], 'is_string')) + array_filter(['status' => $this->query('status')], 'is_string');
    }
}
