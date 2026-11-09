<?php

namespace App\Exception;

use App\Enum\ComplaintStatus;

/** Transition de statut interdite : traduite en 409 CONFLICT. */
final class InvalidStatusTransition extends \DomainException
{
    public function __construct(public readonly ComplaintStatus $from, public readonly ComplaintStatus $to)
    {
        parent::__construct(sprintf('Transition de statut interdite : %s -> %s', $from->value, $to->value));
    }
}
