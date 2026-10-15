<?php

namespace App\Platform\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class PlatformClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return now()->toDateTimeImmutable();
    }
}
