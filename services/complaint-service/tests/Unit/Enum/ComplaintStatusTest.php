<?php

namespace App\Tests\Unit\Enum;

use App\Enum\ComplaintStatus as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Matrice des transitions (règle de l'équipe) : les 16 paires et isFinal(). */
final class ComplaintStatusTest extends TestCase
{
    /** Transitions autorisées ; toute autre paire est interdite. */
    private const ALLOWED = [
        'OPEN' => ['IN_PROGRESS', 'REJECTED'],
        'IN_PROGRESS' => ['RESOLVED', 'REJECTED', 'OPEN'],
        'RESOLVED' => [],
        'REJECTED' => [],
    ];

    /** @return iterable<string, array{S, S, bool}> */
    public static function allPairs(): iterable
    {
        foreach (S::cases() as $from) {
            foreach (S::cases() as $to) {
                $allowed = in_array($to->value, self::ALLOWED[$from->value], true);
                yield sprintf('%s -> %s %s', $from->value, $to->value, $allowed ? 'autorisée' : 'interdite') => [$from, $to, $allowed];
            }
        }
    }

    #[DataProvider('allPairs')]
    public function testCanTransitionTo(S $from, S $to, bool $expected): void
    {
        self::assertSame($expected, $from->canTransitionTo($to));
    }

    public function testMatrixCoversEveryPair(): void
    {
        self::assertCount(16, iterator_to_array(self::allPairs()));
    }

    /** @return iterable<string, array{S, bool}> */
    public static function finality(): iterable
    {
        yield 'OPEN' => [S::OPEN, false];
        yield 'IN_PROGRESS' => [S::IN_PROGRESS, false];
        yield 'RESOLVED' => [S::RESOLVED, true];
        yield 'REJECTED' => [S::REJECTED, true];
    }

    #[DataProvider('finality')]
    public function testIsFinal(S $status, bool $expected): void
    {
        self::assertSame($expected, $status->isFinal());
        self::assertSame($expected, $status->allowedTransitions() === []);
    }

    public function testValuesListsAllCases(): void
    {
        self::assertSame(['OPEN', 'IN_PROGRESS', 'RESOLVED', 'REJECTED'], S::values());
    }
}
