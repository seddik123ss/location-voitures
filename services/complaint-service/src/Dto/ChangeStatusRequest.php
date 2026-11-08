<?php

namespace App\Dto;

use App\Enum\ComplaintStatus;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/complaints/{id}/status. */
final class ChangeStatusRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Champ obligatoire')]
        #[Assert\Choice(callback: [ComplaintStatus::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
        public mixed $status = null,

        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 1000, maxMessage: '1000 caractères au maximum')]
        public mixed $comment = null,
    ) {
    }
}
