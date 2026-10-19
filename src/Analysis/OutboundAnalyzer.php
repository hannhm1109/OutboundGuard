<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Analysis;

use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use Hanane\OutboundGuardBundle\Metadata\OutboundCall;

/**
 * Pure analysis: no I/O, clock, mutable cache, logging or transport changes.
 *
 * @phpstan-import-type CallGroup from OutboundReport
 * @phpstan-import-type Warning from OutboundReport
 */
final class OutboundAnalyzer
{
    /** @param list<OutboundCall> $calls */
    public function analyze(array $calls, Budget $budget): OutboundReport
    {
        /** @var array<string, CallGroup> $groups */
        $groups = [];
        /** @var list<Warning> $slowWarnings */
        $slowWarnings = [];
        $totalDuration = 0.0;
        $slowestDuration = null;
        $pendingCount = 0;
        $unknownDurationCount = 0;

        foreach ($calls as $index => $call) {
            $fingerprint = $call->target->fingerprint;
            $groups[$fingerprint] ??= [
                'method' => $call->method,
                'target' => $call->target->displayUrl,
                'count' => 0,
                'duration_ms' => 0.0,
                'unknown_duration_count' => 0,
                'status_codes' => [],
            ];
            ++$groups[$fingerprint]['count'];
            $status = $call->getStatusCode();

            if (null !== $status && !in_array($status, $groups[$fingerprint]['status_codes'], true)) {
                $groups[$fingerprint]['status_codes'][] = $status;
            }

            if (CallOutcome::Pending === $call->getOutcome()) {
                ++$pendingCount;
            }

            $duration = $call->getDurationMs();

            if (null === $duration) {
                ++$unknownDurationCount;
                ++$groups[$fingerprint]['unknown_duration_count'];

                continue;
            }

            $totalDuration += $duration;
            $groups[$fingerprint]['duration_ms'] += $duration;
            $slowestDuration = null === $slowestDuration ? $duration : max($slowestDuration, $duration);

            if ($duration > $budget->slowCallThresholdMs) {
                $slowWarnings[] = [
                    'code' => 'slow_call',
                    'message' => sprintf(
                        'Slow outbound call #%d: %s %s (%sms / threshold %dms).',
                        $index + 1,
                        $call->method,
                        $call->target->displayUrl,
                        self::formatDuration($duration),
                        $budget->slowCallThresholdMs,
                    ),
                ];
            }
        }

        /** @var list<Warning> $warnings */
        $warnings = [];

        if (count($calls) > $budget->maxCalls) {
            $warnings[] = [
                'code' => 'max_calls',
                'message' => sprintf('Outbound call budget exceeded: %d calls / maximum %d.', count($calls), $budget->maxCalls),
            ];
        }

        if ($totalDuration > $budget->maxTotalDurationMs) {
            $warnings[] = [
                'code' => 'max_total_duration',
                'message' => sprintf(
                    'Total external HTTP duration exceeded: %sms / maximum %dms.',
                    self::formatDuration($totalDuration),
                    $budget->maxTotalDurationMs,
                ),
            ];
        }

        $duplicateCount = 0;

        foreach ($groups as $group) {
            if ($group['count'] > 1) {
                $repeats = $group['count'] - 1;
                $duplicateCount += $repeats;
                $warnings[] = [
                    'code' => 'duplicate_call',
                    'message' => sprintf(
                        'Duplicate outbound endpoint: %s %s (%d calls; %d repeats).',
                        $group['method'],
                        $group['target'],
                        $group['count'],
                        $repeats,
                    ),
                ];
            }
        }

        return new OutboundReport(
            budget: $budget,
            callCount: count($calls),
            totalDurationMs: $totalDuration,
            slowestDurationMs: $slowestDuration,
            pendingCallCount: $pendingCount,
            unknownDurationCount: $unknownDurationCount,
            duplicateCallCount: $duplicateCount,
            slowCallCount: count($slowWarnings),
            groups: array_values($groups),
            warnings: [...$warnings, ...$slowWarnings],
        );
    }

    private static function formatDuration(float $duration): string
    {
        return number_format($duration, 2, '.', '');
    }
}
