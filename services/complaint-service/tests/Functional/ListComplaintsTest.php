<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** GET /api/complaints */
final class ListComplaintsTest extends ApiTestCase
{
    public function testClientOnlySeesOwnComplaints(): void
    {
        $this->request('GET', '/api/complaints', self::CLIENT2);
        $page = $this->assertJsonStatus(200);

        self::assertSame(['items', 'page', 'size', 'total'], array_keys($page));
        self::assertSame(3, $page['total']);
        self::assertSame([DemoIds::CLIENT2], array_values(array_unique(array_column($page['items'], 'userId'))));
        // Plus récentes d'abord.
        self::assertSame([DemoIds::EXTRA_DAMAGE, DemoIds::REC_1, DemoIds::REC_2], array_column($page['items'], 'id'));
    }

    public function testClientMayFilterOnHisOwnId(): void
    {
        $this->request('GET', '/api/complaints?userId=' . DemoIds::CLIENT1, self::CLIENT1);

        self::assertSame(2, $this->assertJsonStatus(200)['total']);
    }

    public function testStaffSeesEverything(): void
    {
        $this->request('GET', '/api/complaints', self::AGENT1);

        self::assertSame(5, $this->assertJsonStatus(200)['total']);
    }

    public function testItemsAreSummariesInCamelCase(): void
    {
        $this->request('GET', '/api/complaints?size=1', self::ADMIN1);
        $item = $this->assertJsonStatus(200)['items'][0];

        self::assertSame(
            ['id', 'userId', 'reservationId', 'contractId', 'category', 'priority', 'status', 'subject', 'description', 'createdAt', 'updatedAt'],
            array_keys($item),
        );
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $item['createdAt']);
    }

    public function testPagination(): void
    {
        $this->request('GET', '/api/complaints?page=1&size=2', self::AGENT1);
        $page = $this->assertJsonStatus(200);

        self::assertSame(1, $page['page']);
        self::assertSame(2, $page['size']);
        self::assertSame(5, $page['total']);
        self::assertSame([DemoIds::EXTRA_DAMAGE, DemoIds::REC_1], array_column($page['items'], 'id'));

        $this->request('GET', '/api/complaints?page=9', self::AGENT1);
        self::assertSame([], $this->assertJsonStatus(200)['items']);
    }

    public function testFilters(): void
    {
        $cases = [
            'status=OPEN' => [DemoIds::EXTRA_DAMAGE, DemoIds::REC_1],
            'category=BILLING' => [DemoIds::EXTRA_BILLING, DemoIds::REC_1],
            'priority=high' => [DemoIds::EXTRA_DAMAGE, DemoIds::REC_2],
            'userId=' . DemoIds::CLIENT1 => [DemoIds::EXTRA_SERVICE, DemoIds::EXTRA_BILLING],
            'status=OPEN&category=DAMAGE_DISPUTE&priority=HIGH' => [DemoIds::EXTRA_DAMAGE],
            'status=RESOLVED&category=BILLING' => [],
        ];
        foreach ($cases as $query => $expected) {
            $this->request('GET', "/api/complaints?$query", self::AGENT1);
            self::assertSame($expected, array_column($this->assertJsonStatus(200)['items'], 'id'), $query);
        }
    }

    public function testInvalidParametersAre400WithOneDetailPerParameter(): void
    {
        $this->request('GET', '/api/complaints?page=-1&size=500&userId=abc&status=CLOSED&category=X&priority=URGENT', self::AGENT1);

        $this->assertError(400, 'BAD_REQUEST', 'Paramètres de requête invalides', ['page', 'size', 'userId', 'status', 'category', 'priority']);
    }

    public function testClientFilteringOnAnotherClientIs403(): void
    {
        $this->request('GET', '/api/complaints?userId=' . DemoIds::CLIENT2, self::CLIENT1);

        $this->assertError(403, 'FORBIDDEN', 'ses propres réclamations');
    }
}
