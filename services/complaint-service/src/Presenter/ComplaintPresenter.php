<?php

namespace App\Presenter;

use App\Entity\Complaint;
use App\Entity\ComplaintMessage;
use App\Entity\ComplaintStatusHistory;

/** Représentation JSON (camelCase, instants ISO 8601 UTC) des réclamations. */
final class ComplaintPresenter
{
    /** @return array<string, mixed> élément de liste, sans messages ni historique */
    public function summary(Complaint $c): array
    {
        return [
            'id' => $c->getId(),
            'userId' => $c->getUserId(),
            'reservationId' => $c->getReservationId(),
            'contractId' => $c->getContractId(),
            'category' => $c->getCategory()->value,
            'priority' => $c->getPriority()->value,
            'status' => $c->getStatus()->value,
            'subject' => $c->getSubject(),
            'description' => $c->getDescription(),
            'createdAt' => self::instant($c->getCreatedAt()),
            'updatedAt' => self::instant($c->getUpdatedAt()),
        ];
    }

    /** @return array<string, mixed> détail avec messages et historique */
    public function detail(Complaint $c): array
    {
        $data = $this->summary($c);
        $data['allowedTransitions'] = array_map(static fn ($s) => $s->value, $c->getStatus()->allowedTransitions());
        $data['messages'] = array_values(array_map($this->message(...), $c->getMessages()->toArray()));
        $data['statusHistory'] = array_values(array_map($this->history(...), $c->getStatusHistory()->toArray()));

        return $data;
    }

    /** @return array<string, mixed> */
    public function message(ComplaintMessage $m): array
    {
        return [
            'id' => $m->getId(),
            'complaintId' => $m->getComplaint()->getId(),
            'authorId' => $m->getAuthorId(),
            'authorRole' => $m->getAuthorRole()->value,
            'content' => $m->getContent(),
            'createdAt' => self::instant($m->getCreatedAt()),
        ];
    }

    /** @return array<string, mixed> */
    public function history(ComplaintStatusHistory $h): array
    {
        return [
            'id' => $h->getId(),
            'fromStatus' => $h->getFromStatus()?->value,
            'toStatus' => $h->getToStatus()->value,
            'changedBy' => $h->getChangedBy(),
            'changedByRole' => $h->getChangedByRole()->value,
            'comment' => $h->getComment(),
            'changedAt' => self::instant($h->getChangedAt()),
        ];
    }

    public static function instant(\DateTimeImmutable $d): string
    {
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
