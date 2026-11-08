<?php

namespace App\Dto;

use App\Enum\ComplaintCategory;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /api/complaints/{id} : remplacement des champs modifiables par le client. */
final class UpdateComplaintRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 150, maxMessage: '150 caractères au maximum')]
        public mixed $subject = null,

        #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 5000, maxMessage: '5000 caractères au maximum')]
        public mixed $description = null,

        #[Assert\NotBlank(message: 'Champ obligatoire')]
        #[Assert\Choice(callback: [ComplaintCategory::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
        public mixed $category = null,
    ) {
    }
}
