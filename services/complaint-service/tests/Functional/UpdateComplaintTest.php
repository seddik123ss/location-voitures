<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** PUT /api/complaints/{id} */
final class UpdateComplaintTest extends ApiTestCase
{
    private const BODY = ['subject' => 'Montant facturé supérieur au devis (+45 TND)', 'description' => 'Supplément de 45.000 TND non expliqué.', 'category' => 'SERVICE'];

    public function testOwnerUpdatesOpenComplaint(): void
    {
        $this->request('PUT', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2, self::BODY);
        $c = $this->assertJsonStatus(200);

        self::assertSame(self::BODY['subject'], $c['subject']);
        self::assertSame(self::BODY['description'], $c['description']);
        self::assertSame('SERVICE', $c['category']);
        self::assertSame('OPEN', $c['status']);
        self::assertGreaterThan('2026-10-06T09:20:00Z', $c['updatedAt']);

        $this->request('GET', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2);
        self::assertSame('SERVICE', $this->assertJsonStatus(200)['category']);
    }

    public function testAllFieldsAreRequired(): void
    {
        $this->request('PUT', '/api/complaints/' . DemoIds::REC_1, self::CLIENT2, ['subject' => 'Nouveau sujet']);

        $this->assertError(400, 'BAD_REQUEST', fields: ['description', 'category']);
    }

    public function testStaffCannotUpdate(): void
    {
        $this->request('PUT', '/api/complaints/' . DemoIds::REC_1, self::AGENT1, self::BODY);

        $this->assertError(403, 'FORBIDDEN', 'Seul le client auteur');
    }

    public function testAnotherClientCannotUpdate(): void
    {
        $this->request('PUT', '/api/complaints/' . DemoIds::REC_1, self::CLIENT1, self::BODY);

        $this->assertError(403, 'FORBIDDEN');
    }

    public function testUnknownComplaintIs404(): void
    {
        $this->request('PUT', '/api/complaints/' . self::UNKNOWN_ID, self::CLIENT2, self::BODY);

        $this->assertError(404, 'NOT_FOUND');
    }

    public function testNonOpenComplaintIs409(): void
    {
        $this->request('PUT', '/api/complaints/' . DemoIds::EXTRA_SERVICE, self::CLIENT1, self::BODY);
        $this->assertError(409, 'CONFLICT', 'statut actuel : IN_PROGRESS');

        $this->request('PUT', '/api/complaints/' . DemoIds::REC_2, self::CLIENT2, self::BODY);
        $this->assertError(409, 'CONFLICT', 'statut actuel : RESOLVED');
    }
}
