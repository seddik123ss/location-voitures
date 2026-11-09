<?php

namespace App\Dto;

use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/complaints/{id}/messages (conventions : voir CreateComplaintRequest). */
#[OA\Schema(required: ['content'])]
final class AddMessageRequest
{
    #[OA\Property(type: 'string', nullable: false, example: 'Nous avons retrouvé le supplément : frais de péage. Remboursement en cours.')]
    #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 2000, maxMessage: '2000 caractères au maximum')]
    public mixed $content;
}
