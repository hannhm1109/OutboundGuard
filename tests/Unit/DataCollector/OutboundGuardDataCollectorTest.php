<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\DataCollector;

use Hanane\OutboundGuardBundle\Analysis\Budget;
use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\DataCollector\OutboundGuardDataCollector;
use Hanane\OutboundGuardBundle\HttpClient\MonitoringHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OutboundGuardDataCollectorTest extends TestCase
{
    public function testLateCollectionIncludesTransfersCompletedAfterTheResponseSnapshot(): void
    {
        $collector = new OutboundCallCollector(budget: new Budget(1, 10, 10));
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('body', ['total_time' => 0.025])), $collector);
        $response = $client->request('GET', 'https://api.example.com/items');
        $profiler = new OutboundGuardDataCollector($collector);
        $profiler->collect(new Request(), new Response());

        self::assertSame(1, $profiler->getReport()->pendingCallCount);
        $response->getContent();
        self::assertSame(0.0, $profiler->getReport()->totalDurationMs);
        $profiler->lateCollect();

        self::assertSame(25.0, $profiler->getReport()->totalDurationMs);
        self::assertSame(['max_total_duration', 'slow_call'], array_column($profiler->getReport()->warnings, 'code'));
    }

    public function testSerializationRetainsOnlyTheSafeSnapshotAndSurvivesReset(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('secret-response-body', ['total_time' => 0.025, 'debug' => 'secret-debug'])), $collector);
        $response = $client->request('GET', 'https://user:secret-password@api.example.com/secret-path?token=secret-query#secret-fragment', [
            'headers' => ['Authorization' => 'secret-authorization', 'Cookie' => 'secret-cookie'],
            'body' => 'secret-request-body',
        ]);
        $response->getContent();
        $profiler = new OutboundGuardDataCollector($collector);
        $profiler->collect(new Request(), new Response());
        $serialized = serialize($profiler);

        self::assertStringNotContainsString('secret-', $serialized);
        self::assertStringNotContainsString('fingerprint', $serialized);
        self::assertStringNotContainsString(OutboundCallCollector::class, $serialized);
        $restored = unserialize($serialized);
        self::assertInstanceOf(OutboundGuardDataCollector::class, $restored);
        $collector->reset();

        self::assertSame('outbound_guard', $restored->getName());
        self::assertSame(1, $restored->getReport()->callCount);
        self::assertSame(25.0, $restored->getReport()->totalDurationMs);
        $restored->reset();
        self::assertSame(0, $restored->getReport()->callCount);
    }
}
