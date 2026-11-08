<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Les DATETIME MySQL sont stockés en UTC (conventions : instants ISO 8601 UTC).
        date_default_timezone_set('UTC');
        parent::boot();
    }
}
