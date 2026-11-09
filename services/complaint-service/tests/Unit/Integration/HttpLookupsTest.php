<?php

namespace App\Tests\Unit\Integration;

use App\DataFixtures\DemoIds;
use App\Integration\Http\HttpContractLookup;
use App\Integration\Http\HttpReservationLookup;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Lecture des réponses de GET /internal/reservations/{id} et GET /internal/contracts/{id} (docs/internal-api.md). */
final class HttpLookupsTest extends IntegrationTestCase
{
    public function testReservationIsMappedFromInternalApiResponse(): void
    {
        $body = json_encode(['id' => strtoupper(DemoIds::R_CONFIRMED), 'userId' => DemoIds::CLIENT1, 'vehicleId' => 'x', 'status' => 'CONFIRMED', 'totalPrice' => 450.0]);
        $response = new MockResponse($body, ['http_code' => 200]);
        $resolver = new FakeResolver();

        $r = (new HttpReservationLookup(self::internalClient(new MockHttpClient($response), $resolver)))->find(strtoupper(DemoIds::R_CONFIRMED));

        self::assertSame(['RENTAL-RESERVATION'], $resolver->resolved);
        self::assertStringEndsWith('/internal/reservations/' . DemoIds::R_CONFIRMED, $response->getRequestUrl());
        self::assertSame(DemoIds::R_CONFIRMED, $r->id);
        self::assertSame(DemoIds::CLIENT1, $r->userId);
        self::assertSame('CONFIRMED', $r->status);
    }

    public function testUnknownReservationIsNull(): void
    {
        $lookup = new HttpReservationLookup(self::internalClient(new MockHttpClient(new MockResponse('', ['http_code' => 404]))));

        self::assertNull($lookup->find(DemoIds::R_CONFIRMED));
    }

    public function testReservationWithoutUserIdIs502(): void
    {
        $body = json_encode(['id' => DemoIds::R_CONFIRMED, 'status' => 'CONFIRMED']);
        $lookup = new HttpReservationLookup(self::internalClient(new MockHttpClient(new MockResponse($body, ['http_code' => 200]))));

        self::assertApiException(502, 'champ userId manquant ou invalide', fn () => $lookup->find(DemoIds::R_CONFIRMED));
    }

    public function testContractIsMappedFromInternalApiResponse(): void
    {
        $body = json_encode(['id' => DemoIds::C_COMPLETED, 'number' => 'CTR-001', 'reservationId' => DemoIds::R_COMPLETED, 'userId' => DemoIds::CLIENT2, 'vehicleId' => 'v', 'status' => 'CLOSED', 'signedAt' => '2026-10-01T09:00:00Z']);
        $response = new MockResponse($body, ['http_code' => 200]);
        $resolver = new FakeResolver('http://contract.test:8105');

        $c = (new HttpContractLookup(self::internalClient(new MockHttpClient($response), $resolver)))->find(DemoIds::C_COMPLETED);

        self::assertSame(['RENTAL-CONTRACT'], $resolver->resolved);
        self::assertSame('http://contract.test:8105/internal/contracts/' . DemoIds::C_COMPLETED, $response->getRequestUrl());
        self::assertSame(DemoIds::R_COMPLETED, $c->reservationId);
        self::assertSame(DemoIds::CLIENT2, $c->userId);
        self::assertSame('CLOSED', $c->status);
    }

    public function testContractWithInvalidUserIdIs502(): void
    {
        $body = json_encode(['id' => DemoIds::C_COMPLETED, 'reservationId' => DemoIds::R_COMPLETED, 'userId' => 42, 'status' => 'CLOSED']);
        $lookup = new HttpContractLookup(self::internalClient(new MockHttpClient(new MockResponse($body, ['http_code' => 200]))));

        self::assertApiException(502, 'Réponse illisible du service RENTAL-CONTRACT : champ userId', fn () => $lookup->find(DemoIds::C_COMPLETED));
    }
}
