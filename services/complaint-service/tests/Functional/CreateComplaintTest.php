<?php

namespace App\Tests\Functional;

use App\DataFixtures\DemoIds;

/** POST /api/complaints */
final class CreateComplaintTest extends ApiTestCase
{
    public function testCreateOnOwnReservation(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, [
            'reservationId' => DemoIds::R_CONFIRMED,
            'category' => 'VEHICLE',
            'priority' => 'HIGH',
            'subject' => '  Siège enfant absent  ',
            'description' => 'Le siège enfant réservé n\'est pas prévu.',
        ]);
        $c = $this->assertJsonStatus(201);

        self::assertSame(DemoIds::CLIENT1, $c['userId']);
        self::assertSame(DemoIds::R_CONFIRMED, $c['reservationId']);
        self::assertNull($c['contractId']);
        self::assertSame('OPEN', $c['status']);
        self::assertSame('HIGH', $c['priority']);
        self::assertSame('Siège enfant absent', $c['subject']);
        self::assertSame([], $c['messages']);
        self::assertCount(1, $c['statusHistory']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $c['id']);

        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertSame('/api/complaints/' . $c['id'], $location);
        $this->request('GET', $location, self::CLIENT1);
        self::assertSame($c['id'], $this->assertJsonStatus(200)['id']);
    }

    public function testCreateOnOwnContractWithDefaultPriority(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT2, [
            'contractId' => strtoupper(DemoIds::C_COMPLETED),
            'category' => 'BILLING',
            'subject' => 'Frais de carburant',
            'description' => 'Le plein avait été fait.',
        ]);
        $c = $this->assertJsonStatus(201);

        self::assertSame(DemoIds::C_COMPLETED, $c['contractId']);
        self::assertNull($c['reservationId']);
        self::assertSame('MEDIUM', $c['priority']);
    }

    public function testInvalidFieldsAre400WithOneDetailPerField(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['reservationId' => 'abc', 'category' => 'AUTRE', 'priority' => 'URGENT', 'subject' => 123, 'description' => '   ']);

        $this->assertError(400, 'BAD_REQUEST', 'Requête invalide', ['reservationId', 'category', 'priority', 'subject', 'description']);
    }

    public function testMissingFieldsAre400(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, []);

        $body = $this->assertError(400, 'BAD_REQUEST', fields: ['category', 'subject', 'description']);
        self::assertSame(['Champ obligatoire'], array_values(array_unique(array_column($body['details'], 'message'))));
    }

    public function testTooLongSubjectIs400(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['reservationId' => DemoIds::R_CONFIRMED, 'category' => 'BILLING', 'subject' => str_repeat('a', 151), 'description' => 'd']);

        $this->assertError(400, 'BAD_REQUEST', fields: ['subject']);
    }

    public function testNeitherReservationNorContractIs422(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['category' => 'BILLING', 'subject' => 's', 'description' => 'd', 'reservationId' => '']);

        $this->assertError(422, 'UNPROCESSABLE_ENTITY', 'exactement un des deux', ['reservationId', 'contractId']);
    }

    public function testBothReservationAndContractIs422(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT2, ['reservationId' => DemoIds::R_COMPLETED, 'contractId' => DemoIds::C_COMPLETED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);

        $this->assertError(422, 'UNPROCESSABLE_ENTITY', 'exactement un des deux', ['reservationId', 'contractId']);
    }

    public function testUnknownReservationIs404(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['reservationId' => self::UNKNOWN_ID, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);

        $this->assertError(404, 'NOT_FOUND', 'Réservation introuvable');
    }

    public function testUnknownContractIs404(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['contractId' => self::UNKNOWN_ID, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);

        $this->assertError(404, 'NOT_FOUND', 'Contrat introuvable');
    }

    public function testAnotherClientsReservationIs403(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['reservationId' => DemoIds::R_COMPLETED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);

        $this->assertError(403, 'FORBIDDEN', 'Cette réservation appartient à un autre client');
    }

    public function testAnotherClientsContractIs403(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['contractId' => DemoIds::C_COMPLETED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);

        $this->assertError(403, 'FORBIDDEN', 'Ce contrat appartient à un autre client');
    }

    public function testStaffCannotCreate(): void
    {
        foreach ([self::AGENT1, self::ADMIN1] as $staff) {
            $this->request('POST', '/api/complaints', $staff, ['reservationId' => DemoIds::R_CONFIRMED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);
            $this->assertError(403, 'FORBIDDEN', 'Seul un client');
        }
    }

    public function testNothingIsPersistedOnError(): void
    {
        $this->request('POST', '/api/complaints', self::CLIENT1, ['reservationId' => DemoIds::R_COMPLETED, 'category' => 'BILLING', 'subject' => 's', 'description' => 'd']);
        $this->assertError(403, 'FORBIDDEN');

        $this->request('GET', '/api/complaints', self::AGENT1);
        self::assertSame(5, $this->assertJsonStatus(200)['total']);
    }
}
