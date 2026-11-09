<?php

namespace App\Dto;

use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Corps de POST /api/complaints. « Exactement un des deux » (422) est vérifié dans ComplaintService.
 *
 * Conventions des DTO :
 * - propriétés en mixed + Assert\Type : une erreur de type n'interrompt pas la validation des autres champs ;
 * - champ obligatoire = sans valeur par défaut (non initialisé = null pour le validator, donc NotBlank -> 400),
 *   ce qui le rend aussi « required » dans la doc OpenAPI ; champ facultatif = valeur par défaut null.
 */
#[OA\Schema(
    required: ['category', 'subject', 'description'],
    description: 'Indiquer exactement un des deux champs reservationId ou contractId (aucun ou les deux → 422). La réservation ou le contrat doit appartenir à l\'appelant.',
    example: ['reservationId' => '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8', 'category' => 'VEHICLE', 'priority' => 'HIGH', 'subject' => 'Siège enfant absent', 'description' => 'Le siège enfant réservé n\'est pas prévu sur la fiche.'],
)]
final class CreateComplaintRequest
{
    #[OA\Property(type: 'string', format: 'uuid', nullable: true, description: 'Réservation concernée (exclusif avec contractId).', example: '33bcc8dc-bd48-4ff8-b7e8-01d7cac54ad8')]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Uuid(message: 'Doit être un UUID')]
    public mixed $reservationId = null;

    #[OA\Property(type: 'string', format: 'uuid', nullable: true, description: 'Contrat concerné (exclusif avec reservationId).', example: '6804f1c2-925a-4604-94d4-487853bc1cac')]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Uuid(message: 'Doit être un UUID')]
    public mixed $contractId = null;

    #[OA\Property(type: 'string', nullable: false, example: 'VEHICLE')]
    #[Assert\NotBlank(message: 'Champ obligatoire')]
    #[Assert\Choice(callback: [ComplaintCategory::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
    public mixed $category;

    #[OA\Property(type: 'string', nullable: true, default: 'MEDIUM', description: 'MEDIUM si absent.', example: 'HIGH')]
    #[Assert\Choice(callback: [ComplaintPriority::class, 'values'], message: 'Valeur invalide, valeurs possibles : {{ choices }}')]
    public mixed $priority = null;

    #[OA\Property(type: 'string', nullable: false, example: 'Siège enfant absent')]
    #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 150, maxMessage: '150 caractères au maximum')]
    public mixed $subject;

    #[OA\Property(type: 'string', nullable: false, example: 'Le siège enfant réservé n\'est pas prévu sur la fiche.')]
    #[Assert\NotBlank(message: 'Champ obligatoire', normalizer: [Normalize::class, 'trim'])]
    #[Assert\Type(type: 'string', message: 'Chaîne de caractères attendue')]
    #[Assert\Length(max: 5000, maxMessage: '5000 caractères au maximum')]
    public mixed $description;
}
