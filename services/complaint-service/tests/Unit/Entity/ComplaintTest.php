<?php

namespace App\Tests\Unit\Entity;

use App\DataFixtures\DemoIds;
use App\Entity\Complaint;
use App\Enum\AuthorRole;
use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus as S;
use App\Exception\InvalidStatusTransition;
use App\Tests\Unit\Enum\ComplaintStatusTest;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;

final class ComplaintTest extends TestCase
{
    public function testNewComplaintIsOpenWithCreationEntry(): void
    {
        $c = $this->complaint();

        self::assertSame(S::OPEN, $c->getStatus());
        self::assertCount(1, $c->getStatusHistory());
        $entry = $c->getStatusHistory()->first();
        self::assertNull($entry->getFromStatus());
        self::assertSame(S::OPEN, $entry->getToStatus());
        self::assertSame(DemoIds::CLIENT1, $entry->getChangedBy());
        self::assertSame(AuthorRole::CLIENT, $entry->getChangedByRole());
        self::assertEquals($c->getCreatedAt(), $c->getUpdatedAt());
    }

    /** Chaque paire de la matrice, appliquée à l'entité : historique si autorisée, exception sinon. */
    #[DataProviderExternal(ComplaintStatusTest::class, 'allPairs')]
    public function testChangeStatusFollowsMatrix(S $from, S $to, bool $allowed): void
    {
        $c = $this->complaintIn($from);
        $historyBefore = $c->getStatusHistory()->count();
        $at = new \DateTimeImmutable('2030-01-01T00:00:00Z');

        if (!$allowed) {
            $this->expectException(InvalidStatusTransition::class);
            $this->expectExceptionMessage(sprintf('%s -> %s', $from->value, $to->value));
        }
        $c->changeStatus($to, DemoIds::AGENT1, AuthorRole::AGENT, 'commentaire', $at);

        self::assertSame($to, $c->getStatus());
        self::assertCount($historyBefore + 1, $c->getStatusHistory());
        $last = $c->getStatusHistory()->last();
        self::assertSame($from, $last->getFromStatus());
        self::assertSame($to, $last->getToStatus());
        self::assertSame('commentaire', $last->getComment());
        self::assertEquals($at, $c->getUpdatedAt());
    }

    public function testRefusedTransitionLeavesComplaintUnchanged(): void
    {
        $c = $this->complaintIn(S::RESOLVED);
        $updatedAt = $c->getUpdatedAt();

        try {
            $c->changeStatus(S::OPEN, DemoIds::ADMIN1, AuthorRole::ADMIN);
            self::fail('Transition RESOLVED -> OPEN acceptée');
        } catch (InvalidStatusTransition $e) {
            self::assertSame(S::RESOLVED, $e->from);
            self::assertSame(S::OPEN, $e->to);
        }
        self::assertSame(S::RESOLVED, $c->getStatus());
        self::assertCount(3, $c->getStatusHistory());
        self::assertEquals($updatedAt, $c->getUpdatedAt());
    }

    public function testChangePriorityUpdatesTimestampButNotHistory(): void
    {
        $c = $this->complaint(createdAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'));

        $c->changePriority(ComplaintPriority::HIGH);

        self::assertSame(ComplaintPriority::HIGH, $c->getPriority());
        self::assertCount(1, $c->getStatusHistory());
        self::assertGreaterThan($c->getCreatedAt(), $c->getUpdatedAt());
    }

    public function testAddMessageRecordsAuthorAndTouchesComplaint(): void
    {
        $c = $this->complaint(createdAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $at = new \DateTimeImmutable('2026-01-02T10:00:00Z');

        $m = $c->addMessage(DemoIds::AGENT1, AuthorRole::AGENT, 'Bonjour', $at);

        self::assertCount(1, $c->getMessages());
        self::assertSame($c, $m->getComplaint());
        self::assertSame(AuthorRole::AGENT, $m->getAuthorRole());
        self::assertEquals($at, $c->getUpdatedAt());
    }

    private function complaint(?\DateTimeImmutable $createdAt = null): Complaint
    {
        return new Complaint(DemoIds::CLIENT1, ComplaintCategory::BILLING, ComplaintPriority::LOW, 'Sujet', 'Description', DemoIds::R_CONFIRMED, createdAt: $createdAt);
    }

    /** Réclamation amenée dans le statut demandé par des transitions autorisées. */
    private function complaintIn(S $status): Complaint
    {
        $c = $this->complaint(createdAt: new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $path = match ($status) {
            S::OPEN => [],
            S::IN_PROGRESS => [S::IN_PROGRESS],
            S::RESOLVED => [S::IN_PROGRESS, S::RESOLVED],
            S::REJECTED => [S::REJECTED],
        };
        foreach ($path as $step) {
            $c->changeStatus($step, DemoIds::AGENT1, AuthorRole::AGENT, null, new \DateTimeImmutable('2026-01-01T01:00:00Z'));
        }

        return $c;
    }
}
