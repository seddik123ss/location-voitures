<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Réservation d'un véhicule. Les identifiants des autres services (utilisateur, véhicule, catégorie, agences)
 * sont de simples UUID, sans clé étrangère : chaque service a sa propre base.
 *
 * @property string $id
 * @property string $user_id
 * @property string $vehicle_id
 * @property string|null $category_id
 * @property string $pickup_agency_id
 * @property string $return_agency_id
 * @property \Illuminate\Support\Carbon $start_date
 * @property \Illuminate\Support\Carbon $end_date
 * @property ReservationStatus $status
 * @property string $total_price
 * @property string $deposit_amount
 * @property string $currency
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Reservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'vehicle_id',
        'category_id',
        'pickup_agency_id',
        'return_agency_id',
        'start_date',
        'end_date',
        'status',
        'total_price',
        'deposit_amount',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'status' => ReservationStatus::class,
            'total_price' => 'decimal:3',
            'deposit_amount' => 'decimal:3',
        ];
    }
}
