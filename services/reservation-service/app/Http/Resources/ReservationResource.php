<?php

namespace App\Http\Resources;

use App\Enums\ReservationStatus;
use App\Http\Json;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Format de l'API publique /api/reservations : celui de /internal plus categoryId, createdAt, updatedAt,
 * allowedTransitions ; le détail ajoute statusHistory.
 *
 * @mixin Reservation
 */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = (new InternalReservationResource($this->resource))->toArray($request) + [
            'categoryId' => $this->category_id,
            'allowedTransitions' => ReservationStatus::toValues($this->status->allowedTransitions()),
            'createdAt' => $this->created_at->utc()->toIso8601ZuluString(),
            'updatedAt' => $this->updated_at->utc()->toIso8601ZuluString(),
        ];

        if ($this->relationLoaded('statusHistory')) {
            $data['statusHistory'] = $this->statusHistory->map(fn (ReservationStatusHistory $h) => [
                'fromStatus' => $h->from_status?->value,
                'toStatus' => $h->to_status->value,
                'changedAt' => $h->changed_at->utc()->toIso8601ZuluString(),
                'changedBy' => $h->changed_by,
                'comment' => $h->comment,
            ])->all();
        }

        return $data;
    }

    public function toResponse($request)
    {
        return Json::response($this->resolve($request));
    }
}
