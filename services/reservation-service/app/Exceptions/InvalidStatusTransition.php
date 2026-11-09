<?php

namespace App\Exceptions;

use App\Enums\ReservationStatus;

/** 409 : transition interdite ; les transitions possibles sont listées dans details. */
final class InvalidStatusTransition extends ApiException
{
    public static function from(ReservationStatus $current, ReservationStatus $target, ?string $message = null): self
    {
        $allowed = ReservationStatus::toValues($current->allowedTransitions());

        return new self(409, 'CONFLICT', $message ?? sprintf(
            'Transition interdite : %s -> %s',
            $current->value,
            $target->value,
        ), [[
            'field' => 'status',
            'message' => $allowed === []
                ? "Statut {$current->value} final : aucune transition possible"
                : 'Transitions possibles depuis '.$current->value.' : '.implode(', ', $allowed),
        ]]);
    }
}
