<?php

namespace App\Tests\Unit\Integration;

use App\Exception\ApiException;
use App\Http\CorrelationId;
use App\Integration\Http\InternalApiClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

abstract class IntegrationTestCase extends TestCase
{
    protected const CORRELATION_ID = 'corr-1234';

    protected static function internalClient(MockHttpClient $http, ?FakeResolver $resolver = null, ?FakeTokens $tokens = null): InternalApiClient
    {
        $stack = new RequestStack();
        $stack->push(Request::create('/api/complaints', 'POST', server: ['HTTP_X_CORRELATION_ID' => self::CORRELATION_ID]));

        return new InternalApiClient($http, $resolver ?? new FakeResolver(), $tokens ?? new FakeTokens(), new CorrelationId($stack), new NullLogger());
    }

    /** Vérifie qu'une ApiException est levée avec ce code HTTP et un message qui contient $message. */
    protected static function assertApiException(int $status, string $message, callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            self::assertSame($status, $e->status, 'Code HTTP : ' . $e->getMessage());
            self::assertStringContainsString($message, $e->getMessage());

            return $e;
        }
        self::fail("ApiException $status attendue");
    }
}
