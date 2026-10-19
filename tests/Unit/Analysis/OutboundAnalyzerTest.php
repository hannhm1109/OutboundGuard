<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\Analysis;

use Hanane\OutboundGuardBundle\Analysis\Budget;
use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundAnalyzerTest extends TestCase
{
    public function testCheckoutExampleReportsAllFourRulesWithStableOrdering(): void
    {
        $collector = new OutboundCallCollector(true, new Budget(3, 800, 500));

        foreach ([['/products/42', 200.0], ['/products/42', 200.0], ['/products/42', 200.0], ['/shipping', 600.0], ['/taxes', 40.0]] as [$path, $duration]) {
            $call = $collector->start('GET', 'https://api.example.com'.$path);
            $call->recordStatus(200);
            $call->finish(CallOutcome::Success, $duration);
        }

        $report = $collector->getReport();

        self::assertSame(5, $report->callCount);
        self::assertSame(1240.0, $report->totalDurationMs);
        self::assertSame(600.0, $report->slowestDurationMs);
        self::assertSame(2, $report->duplicateCallCount);
        self::assertSame(1, $report->getDuplicateGroupCount());
        self::assertSame(1, $report->slowCallCount);
        self::assertSame(0, $report->unknownDurationCount);
        self::assertSame(['max_calls', 'max_total_duration', 'duplicate_call', 'slow_call'], array_column($report->warnings, 'code'));
        self::assertTrue($report->isBudgetExceeded());
        self::assertTrue($report->hasWarnings());
        self::assertSame([
            'method' => 'GET',
            'target' => 'https://api.example.com/products/42',
            'count' => 3,
            'duration_ms' => 600.0,
            'unknown_duration_count' => 0,
            'status_codes' => [200],
        ], $report->groups[0]);
        self::assertStringContainsString('5 calls / maximum 3', $report->warnings[0]['message']);
        self::assertStringContainsString('1240.00ms / maximum 800ms', $report->warnings[1]['message']);
        self::assertStringContainsString('3 calls; 2 repeats', $report->warnings[2]['message']);
        self::assertStringContainsString('#4: GET https://api.example.com/shipping', $report->warnings[3]['message']);
        self::assertSame(serialize($report), serialize($collector->getReport()));
    }

    public function testNormalCallsHaveNoWarningsAndMethodsAreDistinct(): void
    {
        $collector = new OutboundCallCollector();

        foreach (['GET', 'POST'] as $method) {
            $call = $collector->start($method, 'https://api.example.com/items');
            $call->recordStatus(200);
            $call->finish(CallOutcome::Success, 100.0);
        }

        $report = $collector->getReport();

        self::assertSame(2, $report->callCount);
        self::assertSame(200.0, $report->totalDurationMs);
        self::assertCount(2, $report->groups);
        self::assertSame(0, $report->duplicateCallCount);
        self::assertFalse($report->hasWarnings());
        self::assertFalse($report->isBudgetExceeded());
        self::assertSame(5, $report->budget->maxCalls);
        self::assertSame(1000, $report->budget->maxTotalDurationMs);
        self::assertSame(500, $report->budget->slowCallThresholdMs);
    }

    #[DataProvider('durationBoundaries')]
    public function testDurationLimitsUseStrictComparisonAndUnknownIsNotZero(?float $duration, int $limit, bool $exceeded): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(1, $limit, $limit));
        $call = $collector->start('GET', 'https://api.example.com/items');
        $call->finish(CallOutcome::Success, $duration);
        $report = $collector->getReport();

        self::assertSame($exceeded, $report->isBudgetExceeded());
        self::assertSame($exceeded ? 1 : 0, $report->slowCallCount);
        self::assertSame($exceeded ? ['max_total_duration', 'slow_call'] : [], array_column($report->warnings, 'code'));
        self::assertSame(null === $duration ? 1 : 0, $report->unknownDurationCount);
    }

    /** @return iterable<string, array{?float, int, bool}> */
    public static function durationBoundaries(): iterable
    {
        yield 'below' => [499.99, 500, false];
        yield 'equal' => [500.0, 500, false];
        yield 'above' => [500.01, 500, true];
        yield 'zero duration' => [0.0, 0, false];
        yield 'zero budget' => [0.01, 0, true];
        yield 'unknown' => [null, 0, false];
    }

    public function testCallBudgetCountsPendingAttemptsAndAllowsEquality(): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(1));
        $collector->start('GET', 'https://api.example.com/first');
        self::assertFalse($collector->getReport()->hasWarnings());
        $collector->start('GET', 'https://api.example.com/second');
        $report = $collector->getReport();

        self::assertSame(['max_calls'], array_column($report->warnings, 'code'));
        self::assertSame(2, $report->pendingCallCount);
        self::assertSame(2, $report->unknownDurationCount);
        self::assertSame(0.0, $report->totalDurationMs);
        self::assertNull($report->slowestDurationMs);
    }

    public function testZeroCallBudgetIsValidAndAnEmptyRequestIsQuiet(): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(0, 0, 0));

        self::assertFalse($collector->getReport()->hasWarnings());
        $collector->start('GET', 'https://api.example.com/items');
        self::assertSame(['max_calls'], array_column($collector->getReport()->warnings, 'code'));
    }

    public function testDuplicatesUseSafeFingerprintsEvenWhenDisplayTargetsAreIdentical(): void
    {
        $collector = new OutboundCallCollector();

        foreach ([['GET', '/items?page=1&token=secret-one'], ['GET', '/items?page=2&token=secret-two'], ['GET', '/other'], ['POST', '/items']] as [$method, $path]) {
            $collector->start($method, 'https://api.example.com'.$path);
        }

        $report = $collector->getReport();

        self::assertCount(3, $report->groups);
        self::assertSame(1, $report->duplicateCallCount);
        self::assertSame(2, $report->groups[0]['count']);
        self::assertSame('https://api.example.com/[path hidden]', $report->groups[0]['target']);
        self::assertSame(['duplicate_call'], array_column($report->warnings, 'code'));
        self::assertStringNotContainsString('secret-', serialize($report));
        self::assertStringNotContainsString('/items', serialize($report));
    }

    public function testMultipleRepeatedGroupsCountExtraCallsRatherThanGroups(): void
    {
        $collector = new OutboundCallCollector();

        foreach (['/first', '/second', '/first', '/second', '/first'] as $path) {
            $collector->start('GET', 'https://api.example.com'.$path);
        }

        $report = $collector->getReport();
        self::assertSame(3, $report->duplicateCallCount);
        self::assertSame(2, $report->getDuplicateGroupCount());
        self::assertSame([3, 2], array_column($report->groups, 'count'));
    }

    public function testFailuresAndCancellationContributeKnownTimeWithoutInventingUnknownValues(): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(5, 1000, 100));
        $failed = $collector->start('GET', 'https://api.example.com/items');
        $failed->recordStatus(503);
        $failed->finish(CallOutcome::HttpError, 120.0);
        $transportError = $collector->start('GET', 'https://api.example.com/items');
        $transportError->recordStatus(200);
        $transportError->finish(CallOutcome::TransportError, 50.0);
        $collector->start('GET', 'https://api.example.com/items')->finish(CallOutcome::Cancelled, null);
        $collector->start('GET', 'https://api.example.com/items');
        $report = $collector->getReport();

        self::assertSame(170.0, $report->totalDurationMs);
        self::assertSame([503, 200], $report->groups[0]['status_codes']);
        self::assertSame(2, $report->groups[0]['unknown_duration_count']);
        self::assertSame(1, $report->pendingCallCount);
        self::assertSame(1, $report->slowCallCount);
        self::assertSame(3, $report->duplicateCallCount);
    }

    public function testReportsAreImmutableSnapshotsAndResetKeepsConfiguredBudgets(): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(0, 10, 20));
        $call = $collector->start('GET', 'https://api.example.com/items');
        $pendingReport = $collector->getReport();
        $call->recordStatus(200);
        $call->finish(CallOutcome::Success, 50.0);
        $finishedReport = $collector->getReport();
        $collector->reset();

        self::assertSame(1, $pendingReport->pendingCallCount);
        self::assertSame([], $pendingReport->groups[0]['status_codes']);
        self::assertSame(0.0, $pendingReport->totalDurationMs);
        self::assertSame(50.0, $finishedReport->totalDurationMs);
        self::assertSame(0, $collector->getReport()->callCount);
        self::assertSame([], $collector->getReport()->warnings);
        self::assertSame(10, $collector->getReport()->budget->maxTotalDurationMs);
    }

    #[DataProvider('invalidBudgets')]
    public function testNegativeBudgetsAreRejected(int $calls, int $total, int $slow): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Budget($calls, $total, $slow);
    }

    /** @return iterable<array{int, int, int}> */
    public static function invalidBudgets(): iterable
    {
        yield [-1, 1000, 500];
        yield [5, -1, 500];
        yield [5, 1000, -1];
    }
}
