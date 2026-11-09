<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Exceptions\InvalidStatusTransition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Réservation d'un véhicule. Les identifiants des autres services (utilisateur, véhicule, catégorie, agences)
 * sont de simples UUID, sans clé étrangère : chaque service a sa propre base.
 * Le statut ne change que par transitionTo(), qui vérifie la transition et écrit l'historique.
 *
 * @property string $id
 * @property string $user_id
 * @property string $vehicle_id
 * @property string|null $category_id
 * @property string $pickup_agency_id
 * @property string $return_agency_id
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property ReservationStatus $status
 * @property string $total_price
 * @property string $deposit_amount
 * @property string $currency
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property \Illuminate\Database\Eloquent\Collection<int, ReservationStatusHistory> $statusHistory
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

    /** UUID v4 (docs/conventions.md) : HasUuids produit des UUID v7 par défaut depuis Laravel 11. */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->orderBy('position');
    }

    /** Réservations actives (PENDING, CONFIRMED, ONGOING) du véhicule qui chevauchent [start, end[. */
    public function scopeOverlapping(Builder $query, string $vehicleId, string $start, string $end, ?string $exceptId = null): Builder
    {
        // Deux périodes se chevauchent si chacune commence avant la fin de l'autre ;
        // un retour le jour d'un départ (end = start) n'est pas un chevauchement.
        return $query->where('vehicle_id', $vehicleId)
            ->whereIn('status', ReservationStatus::activeValues())
            ->where('start_date', '<', $end)
            ->where('end_date', '>', $start)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId));
    }

    /** Première entrée de l'historique, à appeler juste après la création. */
    public function recordCreation(?string $changedBy, ?string $comment = null): void
    {
        $this->addHistory(null, $this->status, $changedBy, $comment);
    }

    /**
     * Change le statut et l'enregistre dans l'historique.
     *
     * @throws InvalidStatusTransition si la transition n'est pas autorisée (409)
     */
    public function transitionTo(ReservationStatus $target, ?string $changedBy, ?string $comment = null): void
    {
        $from = $this->status;
        if (! $from->canTransitionTo($target)) {
            throw InvalidStatusTransition::from($from, $target);
        }
        $this->status = $target;
        $this->save();
        $this->addHistory($from, $target, $changedBy, $comment);
    }

    private function addHistory(?ReservationStatus $from, ReservationStatus $to, ?string $changedBy, ?string $comment): void
    {
        $position = (int) $this->statusHistory()->max('position') + 1;
        $this->statusHistory()->create([
            'position' => $position,
            'from_status' => $from,
            'to_status' => $to,
            'changed_at' => Carbon::now(),
            'changed_by' => $changedBy,
            'comment' => $comment,
        ]);
        $this->unsetRelation('statusHistory');
    }
}
