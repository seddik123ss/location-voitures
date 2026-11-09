<?php

namespace App\Dto;

use App\Enum\ComplaintPriority;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/complaints/{id}/priority (conventions : voir CreateComplaintRequest). */
#[OA\Schema(required: ['priority'])]
final class ChangePriorityRequest
{
    #[OA\Property(type: 'string', nullable: false, example: 'HIGH')]
    #[Assert\NotBlank(message: 'Champ obligatoire')]
    #[Assert\Choice(callback: [ComplaintPriority::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
    public mixed $priority;
}
