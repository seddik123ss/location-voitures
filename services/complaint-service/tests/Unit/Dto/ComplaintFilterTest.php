<?php

namespace App\Tests\Unit\Dto;

use App\Dto\ComplaintFilter;
use App\Enum\ComplaintCategory;
use App\Enum\ComplaintPriority;
use App\Enum\ComplaintStatus;
use App\Exception\ApiException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ComplaintFilterTest extends TestCase
{
    public function testDefaults(): void
    {
        $f = ComplaintFilter::fromRequest(Request::create('/api/complaints'));

        self::assertSame(0, $f->page);
        self::assertSame(20, $f->size);
        self::assertNull($f->userId);
        self::assertNull($f->status);
    }

    public function testParsesValuesCaseInsensitively(): void
    {
        $f = ComplaintFilter::fromRequest(Request::create('/api/complaints', 'GET', [
            'page' => '2', 'size' => '5', 'userId' => 'F4D1BAB3-468D-428D-B02C-B44341430BFD',
            'status' => 'in_progress', 'category' => 'Billing', 'priority' => 'high',
        ]));

        self::assertSame(2, $f->page);
        self::assertSame(5, $f->size);
        self::assertSame('f4d1bab3-468d-428d-b02c-b44341430bfd', $f->userId);
        self::assertSame(ComplaintStatus::IN_PROGRESS, $f->status);
        self::assertSame(ComplaintCategory::BILLING, $f->category);
        self::assertSame(ComplaintPriority::HIGH, $f->priority);
    }

    public function testCollectsEveryInvalidParameter(): void
    {
        try {
            ComplaintFilter::fromRequest(Request::create('/api/complaints', 'GET', [
                'page' => '-1', 'size' => '0', 'userId' => 'abc', 'status' => 'CLOSED', 'category' => 'X', 'priority' => 'URGENT',
            ]));
            self::fail('Paramètres invalides acceptés');
        } catch (ApiException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(['page', 'size', 'userId', 'status', 'category', 'priority'], array_column($e->details, 'field'));
        }
    }
}
