<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Analysis;

/**
 * Immutable, serializable snapshot containing only safe report fields.
 *
 * @phpstan-type CallGroup array{method: string, target: string, count: int, duration_ms: float, unknown_duration_count: int, status_codes: list<int>}
 * @phpstan-type Warning array{code: 'max_calls'|'max_total_duration'|'duplicate_call'|'slow_call', message: string}
 */
final class OutboundReport
{
    /**
     * @param list<CallGroup> $groups
     * @param list<Warning> $warnings
     */
    public function __construct(
        public readonly Budget $budget,
        public readonly int $callCount = 0,
        public readonly float $totalDurationMs = 0.0,
        public readonly ?float $slowestDurationMs = null,
        public readonly int $pendingCallCount = 0,
        public readonly int $unknownDurationCount = 0,
        public readonly int $duplicateCallCount = 0,
        public readonly int $slowCallCount = 0,
        public readonly array $groups = [],
        public readonly array $warnings = [],
    ) {
    }

    public function hasWarnings(): bool
    {
        return [] !== $this->warnings;
    }

    public function isBudgetExceeded(): bool
    {
        foreach ($this->warnings as $warning) {
            if (in_array($warning['code'], ['max_calls', 'max_total_duration'], true)) {
                return true;
            }
        }

        return false;
    }

    public function getDuplicateGroupCount(): int
    {
        return count(array_filter($this->groups, static fn (array $group): bool => $group['count'] > 1));
    }
}
