<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\Collector;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use PHPUnit\Framework\TestCase;

final class OutboundCallCollectorTest extends TestCase
{
    public function testEmptyAndPendingCallsDoNotInventDurationsOrSuccess(): void
    {
        $collector = new OutboundCallCollector();

        self::assertSame([], $collector->getCalls());
        self::assertSame(0.0, $collector->getTotalDurationMs());
        self::assertNull($collector->getSlowestCall());

        $call = $collector->start('get', 'https://api.example.com/items');

        self::assertSame('GET', $call->method);
        self::assertNull($call->getStatusCode());
        self::assertNull($call->getDurationMs());
        self::assertNull($call->isSuccessful());
        self::assertSame(CallOutcome::Pending, $call->getOutcome());
        self::assertSame(1, $collector->getCallCount());
        self::assertNull($collector->getSlowestCall());
    }

    public function testAggregatesKnownDurationsAndFindsTheSlowestCall(): void
    {
        $collector = new OutboundCallCollector();
        $first = $collector->start('GET', 'https://api.example.com/first');
        $first->recordStatus(200);
        $first->finish(CallOutcome::Success, 25.0);
        $slowest = $collector->start('GET', 'https://api.example.com/second');
        $slowest->finish(CallOutcome::TransportError, 75.0);
        $collector->start('GET', 'https://api.example.com/pending');

        self::assertSame(3, $collector->getCallCount());
        self::assertSame(100.0, $collector->getTotalDurationMs());
        self::assertSame($slowest, $collector->getSlowestCall());
        self::assertTrue($first->isSuccessful());
        self::assertFalse($slowest->isSuccessful());
    }

    public function testResetClearsMetricsRotatesTheKeyAndIsolatesOldCalls(): void
    {
        $collector = new OutboundCallCollector();
        $old = $collector->start('GET', 'https://api.example.com/items');
        $collector->reset();
        $new = $collector->start('GET', 'https://api.example.com/items');
        $old->finish(CallOutcome::Success, 100.0);

        self::assertSame([$new], $collector->getCalls());
        self::assertNotSame($old->target->fingerprint, $new->target->fingerprint);
        self::assertSame(0.0, $collector->getTotalDurationMs());
    }

    public function testRepeatedCompletionCannotOverwriteMetrics(): void
    {
        $call = (new OutboundCallCollector())->start('GET', 'https://api.example.com/items');
        $call->finish(CallOutcome::Success, 25.0);
        $call->finish(CallOutcome::Cancelled, 99.0);

        self::assertSame(CallOutcome::Success, $call->getOutcome());
        self::assertSame(25.0, $call->getDurationMs());
    }

    public function testUnknownOrInvalidDurationsStayUnknownAndHttpErrorsAreFailures(): void
    {
        foreach ([null, -1.0, INF, NAN] as $duration) {
            $call = (new OutboundCallCollector())->start('GET', 'https://api.example.com/items');
            $call->recordStatus(0);
            self::assertNull($call->getStatusCode());
            $call->recordStatus(503);
            self::assertFalse($call->isSuccessful());
            $call->finish(CallOutcome::HttpError, $duration);

            self::assertNull($call->getDurationMs());
        }
    }
}
