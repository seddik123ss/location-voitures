<?php

namespace App\Dto;

use App\Enum\ComplaintStatus;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/** Corps de PATCH /api/complaints/{id}/status (conventions : voir CreateComplaintRequest). */
#[OA\Schema(required: ['status'])]
final class ChangeStatusRequest
{
    #[OA\Property(type: 'string', nullable: false, description: 'Nouveau statut, autorisé par la matrice des transitions.', example: 'IN_PROGRESS')]
    #[Assert\NotBlank(message: 'Champ obligatoire')]
    #[Assert\Choice(callback: [ComplaintStatus::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
    public mixed $status;

    #[OA\Property(type: 'string', nullable: true, description: 'Gardé dans l\'historique.', example: 'Vérification auprès de la facturation')]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 1000, maxMessage: '1000 caractères au maximum')]
    public mixed $comment = null;
}
