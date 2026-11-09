<?php

namespace App\Entity;

use App\Enum\AuthorRole;
use App\Repository\ComplaintMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ComplaintMessageRepository::class)]
#[ORM\Table(name: 'complaint_message')]
#[ORM\UniqueConstraint(name: 'uniq_complaint_message_position', columns: ['complaint_id', 'position'])]
class ComplaintMessage
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    /** Rang dans la réclamation (1, 2, ...) : ordre fiable même si deux messages ont la même seconde. */
    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Complaint::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Complaint $complaint;

    #[ORM\Column(type: Types::GUID)]
    private string $authorId;

    #[ORM\Column(length: 10, enumType: AuthorRole::class)]
    private AuthorRole $authorRole;

    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Complaint $complaint, int $position, string $authorId, AuthorRole $authorRole, string $content, ?\DateTimeImmutable $createdAt = null)
    {
        $this->id = Uuid::v4()->toRfc4122();
        $this->position = $position;
        $this->complaint = $complaint;
        $this->authorId = $authorId;
        $this->authorRole = $authorRole;
        $this->content = $content;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string { return $this->id; }
    public function getPosition(): int { return $this->position; }
    public function getComplaint(): Complaint { return $this->complaint; }
    public function getAuthorId(): string { return $this->authorId; }
    public function getAuthorRole(): AuthorRole { return $this->authorRole; }
    public function getContent(): string { return $this->content; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
