<?php

namespace App\Entity;

use App\Enum\AuthorRole;
use App\Enum\ComplaintStatus;
use App\Repository\ComplaintStatusHistoryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ComplaintStatusHistoryRepository::class)]
#[ORM\Table(name: 'complaint_status_history')]
#[ORM\UniqueConstraint(name: 'uniq_complaint_history_position', columns: ['complaint_id', 'position'])]
class ComplaintStatusHistory
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Rang dans la réclamation (1 = création) : ordre fiable même si deux changements ont la même seconde. */
    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Complaint::class, inversedBy: 'statusHistory')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Complaint $complaint;

    /** Null pour l'entrée de création. */
    #[ORM\Column(length: 20, nullable: true, enumType: ComplaintStatus::class)]
    private ?ComplaintStatus $fromStatus;

    #[ORM\Column(length: 20, enumType: ComplaintStatus::class)]
    private ComplaintStatus $toStatus;

    #[ORM\Column(type: Types::GUID)]
    private string $changedBy;

    #[ORM\Column(length: 10, enumType: AuthorRole::class)]
    private AuthorRole $changedByRole;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    public function __construct(Complaint $complaint, int $position, ?ComplaintStatus $fromStatus, ComplaintStatus $toStatus, string $changedBy, AuthorRole $changedByRole, ?string $comment, \DateTimeImmutable $changedAt)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->position = $position;
        $this->complaint = $complaint;
        $this->fromStatus = $fromStatus;
        $this->toStatus = $toStatus;
        $this->changedBy = $changedBy;
        $this->changedByRole = $changedByRole;
        $this->comment = $comment;
        $this->changedAt = $changedAt;
    }

    public function getId(): string { return $this->id; }
    public function getPosition(): int { return $this->position; }
    public function getComplaint(): Complaint { return $this->complaint; }
    public function getFromStatus(): ?ComplaintStatus { return $this->fromStatus; }
    public function getToStatus(): ComplaintStatus { return $this->toStatus; }
    public function getChangedBy(): string { return $this->changedBy; }
    public function getChangedByRole(): AuthorRole { return $this->changedByRole; }
    public function getComment(): ?string { return $this->comment; }
    public function getChangedAt(): \DateTimeImmutable { return $this->changedAt; }
}
