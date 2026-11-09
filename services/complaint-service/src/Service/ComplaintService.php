<?php

namespace App\Service;

use App\Dto\AddMessageRequest;
use App\Dto\ChangePriorityRequest;
use App\Dto\ChangeStatusRequest;
use App\Dto\ComplaintFilter;
use App\Dto\CreateComplaintRequest;
use App\Dto\UpdateComplaintRequest;
use App\Entity\Complaint;
use App\Entity\ComplaintMessage;
use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus;
use App\Exception\ApiException;
use App\Integration\ContractLookup;
use App\Integration\ReservationLookup;
use App\Repository\ComplaintRepository;
use App\Security\ApiUser;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles métier des réclamations. Les droits « qui » (403) sont vérifiés avant par ComplaintVoter ;
 * ici : existence (404), appartenance de la réservation / du contrat (404 / 403), état (409), cohérence (422).
 */
final class ComplaintService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ComplaintRepository $complaints,
        private readonly ReservationLookup $reservations,
        private readonly ContractLookup $contracts,
    ) {
    }

    public function get(string $id): Complaint
    {
        return $this->complaints->find(strtolower($id)) ?? throw ApiException::notFound('Réclamation introuvable');
    }

    /** @return array{items: list<Complaint>, total: int} */
    public function search(ComplaintFilter $filter, ApiUser $user): array
    {
        if (!$user->isStaff()) {
            if ($filter->userId !== null && $filter->userId !== $user->getId()) {
                throw ApiException::forbidden('Un client ne peut consulter que ses propres réclamations');
            }
            $filter = $filter->withUserId($user->getId());
        }

        return $this->complaints->search($filter);
    }

    public function create(CreateComplaintRequest $request, ApiUser $user): Complaint
    {
        $reservationId = self::uuidOrNull($request->reservationId);
        $contractId = self::uuidOrNull($request->contractId);

        if (($reservationId === null) === ($contractId === null)) {
            $message = $reservationId === null ? 'Aucun des deux n\'est renseigné' : 'Les deux sont renseignés';
            throw ApiException::unprocessable('Indiquer exactement un des deux champs reservationId ou contractId', [
                ['field' => 'reservationId', 'message' => $message],
                ['field' => 'contractId', 'message' => $message],
            ]);
        }

        if ($reservationId !== null) {
            $reservation = $this->reservations->find($reservationId) ?? throw ApiException::notFound('Réservation introuvable');
            if ($reservation->userId !== $user->getId()) {
                throw ApiException::forbidden('Cette réservation appartient à un autre client');
            }
        } else {
            $contract = $this->contracts->find($contractId) ?? throw ApiException::notFound('Contrat introuvable');
            if ($contract->userId !== $user->getId()) {
                throw ApiException::forbidden('Ce contrat appartient à un autre client');
            }
        }

        $complaint = new Complaint(
            $user->getId(),
            ComplaintCategory::from((string) $request->category),
            $request->priority !== null ? ComplaintPriority::from($request->priority) : ComplaintPriority::MEDIUM,
            trim((string) $request->subject),
            trim((string) $request->description),
            reservationId: $reservationId,
            contractId: $contractId,
        );
        $this->em->persist($complaint);
        $this->em->flush();

        return $complaint;
    }

    public function update(Complaint $complaint, UpdateComplaintRequest $request): void
    {
        $this->requireOpen($complaint, 'Seule une réclamation OPEN peut être modifiée');
        $complaint->update(
            trim((string) $request->subject),
            trim((string) $request->description),
            ComplaintCategory::from((string) $request->category),
        );
        $this->em->flush();
    }

    public function delete(Complaint $complaint, ApiUser $user): void
    {
        if (!$user->isAdmin()) {
            $this->requireOpen($complaint, 'Seule une réclamation OPEN peut être supprimée par le client');
        }
        $this->em->remove($complaint);
        $this->em->flush();
    }

    public function changeStatus(Complaint $complaint, ChangeStatusRequest $request, ApiUser $user): void
    {
        $comment = is_string($request->comment) && trim($request->comment) !== '' ? trim($request->comment) : null;
        $complaint->changeStatus(ComplaintStatus::from((string) $request->status), $user->getId(), $user->authorRole(), $comment);
        $this->em->flush();
    }

    public function changePriority(Complaint $complaint, ChangePriorityRequest $request): void
    {
        if ($complaint->getStatus()->isFinal()) {
            throw ApiException::conflict(sprintf('Réclamation clôturée (%s) : changement de priorité impossible', $complaint->getStatus()->value));
        }
        $complaint->changePriority(ComplaintPriority::from((string) $request->priority));
        $this->em->flush();
    }

    public function addMessage(Complaint $complaint, AddMessageRequest $request, ApiUser $user): ComplaintMessage
    {
        if ($complaint->getStatus()->isFinal()) {
            throw ApiException::conflict(sprintf('Réclamation clôturée (%s) : ajout de message impossible', $complaint->getStatus()->value));
        }
        $message = $complaint->addMessage($user->getId(), $user->authorRole(), trim((string) $request->content));
        $this->em->flush();

        return $message;
    }

    private function requireOpen(Complaint $complaint, string $message): void
    {
        if ($complaint->getStatus() !== ComplaintStatus::OPEN) {
            throw ApiException::conflict(sprintf('%s (statut actuel : %s)', $message, $complaint->getStatus()->value));
        }
    }

    /** UUID normalisé en minuscules, chaîne vide = absent. */
    private static function uuidOrNull(mixed $value): ?string
    {
        return !is_string($value) || trim($value) === '' ? null : strtolower(trim($value));
    }
}
