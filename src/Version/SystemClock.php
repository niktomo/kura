<?php

namespace Kura\Version;

use Psr\Clock\ClockInterface;

final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        if (defined('LARAVEL_START')) {
            return \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', LARAVEL_START)) ?: new \DateTimeImmutable;
        }

        return new \DateTimeImmutable;
    }
}
