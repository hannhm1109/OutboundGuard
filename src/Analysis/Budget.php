<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Analysis;

/** Global limits. Zero permits no calls or no measured transfer time, respectively. */
final class Budget
{
    public function __construct(
        public readonly int $maxCalls = 5,
        public readonly int $maxTotalDurationMs = 1000,
        public readonly int $slowCallThresholdMs = 500,
    ) {
        if ($maxCalls < 0 || $maxTotalDurationMs < 0 || $slowCallThresholdMs < 0) {
            throw new \InvalidArgumentException('OutboundGuard budgets must be non-negative integers.');
        }
    }
}
