<?php

namespace App\Tests\Unit\Integration;

use App\Integration\Http\ServiceTokenProvider;

/** Fournisseur de jeton factice : t1, puis t2 après un invalidate(), etc. */
final class FakeTokens implements ServiceTokenProvider
{
    public int $invalidations = 0;

    public function token(): string
    {
        return 't' . ($this->invalidations + 1);
    }

    public function invalidate(): void
    {
        ++$this->invalidations;
    }
}
