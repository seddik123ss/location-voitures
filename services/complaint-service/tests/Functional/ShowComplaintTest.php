<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** GET /api/complaints/{id} */
final class ShowComplaintTest extends ApiTestCase
{
    public function testOwnerSeesDetailWithMessagesAndHistory(): void
    {
        $this->request('GET', '/api/complaints/' . DemoIds::REC_2, self::CLIENT2);
        $c = $this->assertJsonStatus(200);

        self::assertSame(DemoIds::REC_2, $c['id']);
        self::assertSame('RESOLVED', $c['status']);
        self::assertSame([], $c['allowedTransitions']);
        self::assertSame(['AGENT', 'CLIENT', 'AGENT'], array_column($c['messages'], 'authorRole'));
        self::assertSame([null, 'OPEN', 'IN_PROGRESS'], array_column($c['statusHistory'], 'fromStatus'));
        self::assertSame(['OPEN', 'IN_PROGRESS', 'RESOLVED'], array_column($c['statusHistory'], 'toStatus'));
    }

    public function testStaffSeesAnyComplaint(): void
    {
        foreach ([self::AGENT1, self::ADMIN1] as $staff) {
            $this->request('GET', '/api/complaints/' . DemoIds::REC_1, $staff);
            $c = $this->assertJsonStatus(200);
            self::assertSame(['IN_PROGRESS', 'REJECTED'], $c['allowedTransitions']);
        }
    }

    public function testUppercaseIdIsAccepted(): void
    {
        $this->request('GET', '/api/complaints/' . strtoupper(DemoIds::REC_1), self::CLIENT2);

        self::assertSame(DemoIds::REC_1, $this->assertJsonStatus(200)['id']);
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('GET', '/api/complaints/' . self::UNKNOWN_ID, self::AGENT1);

        $this->assertError(404, 'NOT_FOUND', 'Réclamation introuvable');
    }

    public function testAnotherClientsComplaintIs403(): void
    {
        $this->request('GET', '/api/complaints/' . DemoIds::REC_1, self::CLIENT1);

        $this->assertError(403, 'FORBIDDEN', 'appartient à un autre client');
    }
}
