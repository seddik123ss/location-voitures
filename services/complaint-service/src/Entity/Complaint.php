<?php

namespace App\Entity;

use App\Enum\AuthorRole;
use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus;
use App\Exception\InvalidStatusTransition;
use App\Repository\ComplaintRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ComplaintRepository::class)]
#[ORM\Table(name: 'complaint')]
#[ORM\Index(name: 'idx_complaint_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_complaint_status', columns: ['status'])]
#[ORM\Index(name: 'idx_complaint_category', columns: ['category'])]
#[ORM\Index(name: 'idx_complaint_priority', columns: ['priority'])]
#[ORM\Index(name: 'idx_complaint_reservation', columns: ['reservation_id'])]
#[ORM\Index(name: 'idx_complaint_contract', columns: ['contract_id'])]
class Complaint
{
    #[ORM\Id]
    #[ORM\Column(type: Types::GUID)]
    private string $id;

    #[ORM\Column(type: Types::GUID)]
    private string $userId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $reservationId;

    #[ORM\Column(type: Types::GUID, nullable: true)]
    private ?string $contractId;

    #[ORM\Column(length: 20, enumType: ComplaintCategory::class)]
    private ComplaintCategory $category;

    #[ORM\Column(length: 10, enumType: ComplaintPriority::class)]
    private ComplaintPriority $priority;

    #[ORM\Column(length: 20, enumType: ComplaintStatus::class)]
    private ComplaintStatus $status = ComplaintStatus::OPEN;

    #[ORM\Column(length: 150)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, ComplaintMessage> */
    #[ORM\OneToMany(targetEntity: ComplaintMessage::class, mappedBy: 'complaint', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    private Collection $messages;

    /** @var Collection<int, ComplaintStatusHistory> */
    #[ORM\OneToMany(targetEntity: ComplaintStatusHistory::class, mappedBy: 'complaint', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['changedAt' => 'ASC'])]
    private Collection $statusHistory;

    public function __construct(
        string $userId,
        ComplaintCategory $category,
        ComplaintPriority $priority,
        string $subject,
        string $description,
        ?string $reservationId = null,
        ?string $contractId = null,
        ?string $id = null,
        ?\DateTimeImmutable $createdAt = null,
    ) {
        $this->id = $id ?? Uuid::v4()->toRfc4122();
        $this->userId = $userId;
        $this->category = $category;
        $this->priority = $priority;
        $this->subject = $subject;
        $this->description = $description;
        $this->reservationId = $reservationId;
        $this->contractId = $contractId;
        $this->createdAt = $createdAt ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->updatedAt = $this->createdAt;
        $this->messages = new ArrayCollection();
        $this->statusHistory = new ArrayCollection();
        // Première entrée de l'historique : création en OPEN par le client.
        $this->statusHistory->add(new ComplaintStatusHistory($this, null, ComplaintStatus::OPEN, $userId, AuthorRole::CLIENT, null, $this->createdAt));
    }

    /**
     * Change le statut en respectant la matrice de ComplaintStatus et trace le changement.
     *
     * @throws InvalidStatusTransition
     */
    public function changeStatus(ComplaintStatus $target, string $changedBy, AuthorRole $role, ?string $comment = null, ?\DateTimeImmutable $at = null): ComplaintStatusHistory
    {
        if (!$this->status->canTransitionTo($target)) {
            throw new InvalidStatusTransition($this->status, $target);
        }
        $at ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $entry = new ComplaintStatusHistory($this, $this->status, $target, $changedBy, $role, $comment, $at);
        $this->statusHistory->add($entry);
        $this->status = $target;
        $this->updatedAt = $at;

        return $entry;
    }

    public function addMessage(string $authorId, AuthorRole $role, string $content, ?\DateTimeImmutable $at = null): ComplaintMessage
    {
        $message = new ComplaintMessage($this, $authorId, $role, $content, $at);
        $this->messages->add($message);
        $this->updatedAt = $message->getCreatedAt();

        return $message;
    }

    public function update(?string $subject = null, ?string $description = null, ?ComplaintCategory $category = null, ?ComplaintPriority $priority = null): void
    {
        $this->subject = $subject ?? $this->subject;
        $this->description = $description ?? $this->description;
        $this->category = $category ?? $this->category;
        $this->priority = $priority ?? $this->priority;
        $this->updatedAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): string { return $this->id; }
    public function getUserId(): string { return $this->userId; }
    public function getReservationId(): ?string { return $this->reservationId; }
    public function getContractId(): ?string { return $this->contractId; }
    public function getCategory(): ComplaintCategory { return $this->category; }
    public function getPriority(): ComplaintPriority { return $this->priority; }
    public function getStatus(): ComplaintStatus { return $this->status; }
    public function getSubject(): string { return $this->subject; }
    public function getDescription(): string { return $this->description; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, ComplaintMessage> */
    public function getMessages(): Collection { return $this->messages; }

    /** @return Collection<int, ComplaintStatusHistory> */
    public function getStatusHistory(): Collection { return $this->statusHistory; }
}
