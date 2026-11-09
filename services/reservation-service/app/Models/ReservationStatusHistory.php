<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un changement de statut d'une réservation (la création est la première entrée, fromStatus null).
 *
 * @property string $id
 * @property string $reservation_id
 * @property int $position
 * @property ReservationStatus|null $from_status
 * @property ReservationStatus $to_status
 * @property \Illuminate\Support\Carbon $changed_at
 * @property string|null $changed_by
 * @property string|null $comment
 */
class ReservationStatusHistory extends Model
{
    use HasUuids;

    protected $table = 'reservation_status_history';

    public $timestamps = false;

    protected $fillable = ['position', 'from_status', 'to_status', 'changed_at', 'changed_by', 'comment'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'from_status' => ReservationStatus::class,
            'to_status' => ReservationStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /** UUID v4 (docs/conventions.md) plutôt que le v7 par défaut de HasUuids. */
    public function newUniqueId(): string
    {
        return (string) \Illuminate\Support\Str::uuid();
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
