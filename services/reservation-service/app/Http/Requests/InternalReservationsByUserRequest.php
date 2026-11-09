<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /internal/reservations?userId=&page=0&size=20 */
class InternalReservationsByUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'userId' => ['required', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:0'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'userId.required' => 'Le paramètre userId est obligatoire',
            'userId.uuid' => 'userId doit être un UUID',
            'page.integer' => 'page doit être un entier positif ou nul',
            'page.min' => 'page doit être un entier positif ou nul',
            'size.integer' => 'size doit être un entier entre 1 et 100',
            'size.min' => 'size doit être un entier entre 1 et 100',
            'size.max' => 'size doit être un entier entre 1 et 100',
        ];
    }

    public function userId(): string
    {
        return strtolower($this->query('userId'));
    }

    public function page(): int
    {
        return (int) $this->query('page', 0);
    }

    public function size(): int
    {
        return (int) $this->query('size', 20);
    }
}
