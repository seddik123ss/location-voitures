<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Corps de POST /api/complaints/{id}/messages. */
final class AddMessageRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
        #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
        #[Assert\Length(max: 2000, maxMessage: '2000 caractères au maximum')]
        public mixed $content = null,
    ) {
    }
}
