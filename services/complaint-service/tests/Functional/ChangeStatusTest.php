<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** PATCH /api/complaints/{id}/status */
final class ChangeStatusTest extends ApiTestCase
{
    public function testAgentMovesOpenToInProgressWithComment(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/status', self::AGENT1, ['status' => 'IN_PROGRESS', 'comment' => '  Vérification auprès de la facturation ']);
        $c = $this->assertJsonStatus(200);

        self::assertSame('IN_PROGRESS', $c['status']);
        self::assertSame(['RESOLVED', 'REJECTED', 'OPEN'], $c['allowedTransitions']);
        $last = end($c['statusHistory']);
        self::assertSame('OPEN', $last['fromStatus']);
        self::assertSame('IN_PROGRESS', $last['toStatus']);
        self::assertSame(DemoIds::AGENT1, $last['changedBy']);
        self::assertSame('AGENT', $last['changedByRole']);
        self::assertSame('Vérification auprès de la facturation', $last['comment']);
        self::assertSame($last['changedAt'], $c['updatedAt']);
    }

    public function testAdminResolvesWithoutComment(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::EXTRA_SERVICE . '/status', self::ADMIN1, ['status' => 'RESOLVED']);
        $c = $this->assertJsonStatus(200);

        self::assertSame('RESOLVED', $c['status']);
        $last = end($c['statusHistory']);
        self::assertSame('ADMIN', $last['changedByRole']);
        self::assertNull($last['comment']);
    }

    public function testInvalidStatusIs400(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/status', self::AGENT1, ['status' => 'CLOSED', 'comment' => ['x']]);

        $this->assertError(400, 'BAD_REQUEST', fields: ['status', 'comment']);
    }

    public function testClientCannotChangeStatus(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/status', self::CLIENT2, ['status' => 'RESOLVED']);

        $this->assertError(403, 'FORBIDDEN', 'Seuls AGENT et ADMIN');
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('PATCH', '/api/complaints/' . self::UNKNOWN_ID . '/status', self::AGENT1, ['status' => 'IN_PROGRESS']);

        $this->assertError(404, 'NOT_FOUND');
    }

    public function testForbiddenTransitionIs409(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/status', self::AGENT1, ['status' => 'RESOLVED']);
        $body = $this->assertError(409, 'CONFLICT', 'OPEN -> RESOLVED', ['status']);
        self::assertStringContainsString('IN_PROGRESS, REJECTED', $body['details'][0]['message']);
    }

    public function testFinalStatesAre409(): void
    {
        foreach ([DemoIds::REC_2 => 'RESOLVED', DemoIds::EXTRA_BILLING => 'REJECTED'] as $id => $final) {
            $this->request('PATCH', "/api/complaints/$id/status", self::ADMIN1, ['status' => 'OPEN']);
            $body = $this->assertError(409, 'CONFLICT', "$final -> OPEN");
            self::assertStringContainsString('aucune (état final)', $body['details'][0]['message']);
        }
    }

    public function testRefusedTransitionChangesNothing(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_2 . '/status', self::ADMIN1, ['status' => 'OPEN']);
        $this->assertError(409, 'CONFLICT');

        $this->request('GET', '/api/complaints/' . DemoIds::REC_2, self::ADMIN1);
        $c = $this->assertJsonStatus(200);
        self::assertSame('RESOLVED', $c['status']);
        self::assertCount(3, $c['statusHistory']);
    }
}
