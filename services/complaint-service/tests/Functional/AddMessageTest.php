<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** POST /api/complaints/{id}/messages */
final class AddMessageTest extends ApiTestCase
{
    public function testOwnerAndStaffCanWrite(): void
    {
        $authors = [[self::CLIENT2, 'CLIENT'], [self::AGENT1, 'AGENT'], [self::ADMIN1, 'ADMIN']];
        foreach ($authors as [$as, $role]) {
            $this->request('POST', '/api/complaints/' . DemoIds::REC_1 . '/messages', $as, ['content' => "  Message de $role  "]);
            $m = $this->assertJsonStatus(201);

            self::assertSame(DemoIds::REC_1, $m['complaintId']);
            self::assertSame($as[0], $m['authorId']);
            self::assertSame($role, $m['authorRole']);
            self::assertSame("Message de $role", $m['content']);
            self::assertResponseHeaderSame('Location', '/api/complaints/' . DemoIds::REC_1);
        }

        $this->request('GET', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2);
        $c = $this->assertJsonStatus(200);
        self::assertSame(['CLIENT', 'CLIENT', 'AGENT', 'ADMIN'], array_column($c['messages'], 'authorRole'));
        self::assertSame(end($c['messages'])['createdAt'], $c['updatedAt']);
    }

    public function testInProgressComplaintAcceptsMessages(): void
    {
        $this->request('POST', '/api/complaints/' . DemoIds::EXTRA_SERVICE . '/messages', self::CLIENT1, ['content' => 'Merci']);

        $this->assertJsonStatus(201);
    }

    public function testBlankOrInvalidContentIs400(): void
    {
        foreach ([['content' => '   '], ['content' => 42], [], ['content' => str_repeat('a', 2001)]] as $body) {
            $this->request('POST', '/api/complaints/' . DemoIds::REC_1 . '/messages', self::CLIENT2, $body);
            $this->assertError(400, 'BAD_REQUEST', fields: ['content']);
        }
    }

    public function testAnotherClientCannotWrite(): void
    {
        $this->request('POST', '/api/complaints/' . DemoIds::REC_1 . '/messages', self::CLIENT1, ['content' => 'Bonjour']);

        $this->assertError(403, 'FORBIDDEN');
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('POST', '/api/complaints/' . self::UNKNOWN_ID . '/messages', self::AGENT1, ['content' => 'Bonjour']);

        $this->assertError(404, 'NOT_FOUND');
    }

    public function testFinalStatesAre409(): void
    {
        foreach ([[DemoIds::REC_2, self::CLIENT2, 'RESOLVED'], [DemoIds::EXTRA_BILLING, self::AGENT1, 'REJECTED']] as [$id, $as, $final]) {
            $this->request('POST', "/api/complaints/$id/messages", $as, ['content' => 'Encore une question']);
            $this->assertError(409, 'CONFLICT', "Réclamation clôturée ($final)");
        }
    }
}
