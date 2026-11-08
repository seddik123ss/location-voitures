<?php

namespace App\Dto;

use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/complaints. « Exactement un des deux » (422) est vérifié dans ComplaintService.
 * Propriétés en mixed + Assert\Type : une erreur de type n'interrompt pas la validation des autres champs,
 * toutes les erreurs remontent ensemble dans details.
 */
final class CreateComplaintRequest
{
    public function __construct(
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Uuid(message: 'Doit être un UUID')]
        public mixed $reservationId = null,

        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Uuid(message: 'Doit être un UUID')]
        public mixed $contractId = null,

        #[Assert\NotBlank(message: 'Champ obligatoire')]
        #[Assert\Choice(callback: [ComplaintCategory::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
        public mixed $category = null,

        #[Assert\Choice(callback: [ComplaintPriority::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
        public mixed $priority = null,

        #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 150, maxMessage: '150 caractères au maximum')]
        public mixed $subject = null,

        #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 5000, maxMessage: '5000 caractères au maximum')]
        public mixed $description = null,
    ) {
    }
}
