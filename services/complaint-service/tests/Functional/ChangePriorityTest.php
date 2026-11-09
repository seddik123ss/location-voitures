<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** PATCH /api/complaints/{id}/priority */
final class ChangePriorityTest extends ApiTestCase
{
    public function testAgentChangesPriorityWithoutTouchingHistory(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/priority', self::AGENT1, ['priority' => 'HIGH']);
        $c = $this->assertJsonStatus(200);

        self::assertSame('HIGH', $c['priority']);
        self::assertSame('OPEN', $c['status']);
        self::assertCount(1, $c['statusHistory']);
        self::assertGreaterThan('2026-10-06T09:20:00Z', $c['updatedAt']);
    }

    public function testAdminChangesPriorityOfInProgressComplaint(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::EXTRA_SERVICE . '/priority', self::ADMIN1, ['priority' => 'MEDIUM']);

        self::assertSame('MEDIUM', $this->assertJsonStatus(200)['priority']);
    }

    public function testInvalidPriorityIs400(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/priority', self::AGENT1, ['priority' => 'URGENT']);
        $this->assertError(400, 'BAD_REQUEST', fields: ['priority']);

        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/priority', self::AGENT1, []);
        $this->assertError(400, 'BAD_REQUEST', fields: ['priority']);
    }

    public function testClientCannotChangePriority(): void
    {
        $this->request('PATCH', '/api/complaints/' . DemoIds::REC_1 . '/priority', self::CLIENT2, ['priority' => 'HIGH']);

        $this->assertError(403, 'FORBIDDEN', 'Seuls AGENT et ADMIN peuvent changer la priorité');
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('PATCH', '/api/complaints/' . self::UNKNOWN_ID . '/priority', self::AGENT1, ['priority' => 'HIGH']);

        $this->assertError(404, 'NOT_FOUND');
    }

    public function testFinalStatesAre409(): void
    {
        foreach ([DemoIds::REC_2 => 'RESOLVED', DemoIds::EXTRA_BILLING => 'REJECTED'] as $id => $final) {
            $this->request('PATCH', "/api/complaints/$id/priority", self::ADMIN1, ['priority' => 'LOW']);
            $this->assertError(409, 'CONFLICT', "Réclamation clôturée ($final)");
        }
    }
}
