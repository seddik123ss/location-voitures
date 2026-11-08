<?php

namespace App\Dto;

use App\Enum\ComplaintCategory;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PUT /api/complaints/{id} : remplacement des champs modifiables par le client (conventions : voir CreateComplaintRequest). */
#[OA\Schema(required: ['subject', 'description', 'category'], description: 'Remplace les trois champs modifiables (tous obligatoires).')]
final class UpdateComplaintRequest
{
    #[OA\Property(type: 'string', nullable: false, example: 'Montant facturé supérieur au devis (+45 TND)')]
    #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 150, maxMessage: '150 caractères au maximum')]
    public mixed $subject;

    #[OA\Property(type: 'string', nullable: false, example: 'Supplément de 45.000 TND non expliqué.')]
    #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 5000, maxMessage: '5000 caractères au maximum')]
    public mixed $description;

    #[OA\Property(type: 'string', nullable: false, example: 'BILLING')]
    #[Assert\NotBlank(message: 'Champ obligatoire')]
    #[Assert\Choice(callback: [ComplaintCategory::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
    public mixed $category;
}
