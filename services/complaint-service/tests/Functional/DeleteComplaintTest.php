<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** DELETE /api/complaints/{id} */
final class DeleteComplaintTest extends ApiTestCase
{
    public function testOwnerDeletesOpenComplaint(): void
    {
        $this->request('DELETE', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $this->client->getResponse()->getContent());
        $this->request('GET', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2);
        $this->assertError(404, 'NOT_FOUND');
    }

    public function testAdminDeletesWhateverTheStatus(): void
    {
        $this->request('DELETE', '/api/complaints/' . DemoIds::REC_2, self::ADMIN1);

        self::assertResponseStatusCodeSame(204);
        $this->request('GET', '/api/complaints', self::ADMIN1);
        self::assertSame(4, $this->assertJsonStatus(200)['total']);
    }

    public function testAgentCannotDelete(): void
    {
        $this->request('DELETE', '/api/complaints/' . DemoIds::REC_1, self::AGENT1);

        $this->assertError(403, 'FORBIDDEN', 'client auteur ou un ADMIN');
    }

    public function testAnotherClientCannotDelete(): void
    {
        $this->request('DELETE', '/api/complaints/' . DemoIds::REC_1, self::CLIENT1);

        $this->assertError(403, 'FORBIDDEN');
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('DELETE', '/api/complaints/' . self::UNKNOWN_ID, self::ADMIN1);

        $this->assertError(404, 'NOT_FOUND');
    }

    public function testOwnerCannotDeleteNonOpenComplaint(): void
    {
        $this->request('DELETE', '/api/complaints/' . DemoIds::EXTRA_SERVICE, self::CLIENT1);

        $this->assertError(409, 'CONFLICT', 'statut actuel : IN_PROGRESS');
        $this->request('GET', '/api/complaints/' . DemoIds::EXTRA_SERVICE, self::CLIENT1);
        $this->assertJsonStatus(200);
    }
}
