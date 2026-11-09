<?php

namespace App\Http\Resources;

use App\Http\Json;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Format des routes /internal (docs/internal-api.md), lu par payment, contract, complaint et user :
 * {id, userId, vehicleId, pickupAgencyId, returnAgencyId, startDate, endDate, status, totalPrice, depositAmount, currency}.
 *
 * @mixin Reservation
 */
class InternalReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'userId' => $this->user_id,
            'vehicleId' => $this->vehicle_id,
            'pickupAgencyId' => $this->pickup_agency_id,
            'returnAgencyId' => $this->return_agency_id,
            'startDate' => $this->start_date->toDateString(),
            'endDate' => $this->end_date->toDateString(),
            'status' => $this->status->value,
            'totalPrice' => Json::amount($this->total_price),
            'depositAmount' => Json::amount($this->deposit_amount),
            'currency' => $this->currency,
        ];
    }

    /** Toujours passer par Json::response() : c'est lui qui écrit les montants à 3 décimales. */
    public function toResponse($request)
    {
        return Json::response($this->resolve($request));
    }
}
