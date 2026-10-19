<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\HttpClient;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\HttpClient\MonitoringHttpClient;
use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

final class MonitoringHttpClientTest extends TestCase
{
    public function testOneRequestRemainsLazyAndRecordsMetadataAfterConsumption(): void
    {
        $reads = new \ArrayObject();
        $body = static function () use ($reads): \Generator {
            $reads->append(true);
            yield '{"ok":true}';
        };
        $collector = new OutboundCallCollector(true);
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse($body(), ['total_time' => 0.125])), $collector);
        $response = $client->request('GET', 'https://api.example.com/items?token=secret');

        self::assertCount(0, $reads);
        self::assertSame(1, $collector->getCallCount());
        self::assertSame(CallOutcome::Pending, $collector->getCalls()[0]->getOutcome());
        self::assertNull($collector->getCalls()[0]->getDurationMs());
        self::assertSame(['ok' => true], $response->toArray());
        self::assertCount(1, $reads);

        $call = $collector->getCalls()[0];
        self::assertSame('https://api.example.com/items', $call->target->displayUrl);
        self::assertSame(200, $call->getStatusCode());
        self::assertSame(125.0, $call->getDurationMs());
        self::assertSame(CallOutcome::Success, $call->getOutcome());
        self::assertTrue($call->isSuccessful());

        self::assertSame('{"ok":true}', $response->getContent());
        $response->cancel();
        self::assertSame(1, $collector->getCallCount());
        self::assertSame(125.0, $collector->getTotalDurationMs());
    }

    public function testMultipleConcurrentRequestsPreserveStreamingAndResponseIdentity(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient([
            new MockResponse('first', ['total_time' => 0.1]),
            new MockResponse('second', ['total_time' => 0.2]),
        ]), $collector);
        $first = $client->request('GET', 'https://api.example.com/first', ['buffer' => false]);
        $second = $client->request('GET', 'https://api.example.com/second', ['buffer' => false]);
        $bodies = [spl_object_id($first) => '', spl_object_id($second) => ''];
        $responses = static function () use ($first, $second): \Generator {
            yield $first;
            yield $second;
        };

        foreach ($client->stream($responses()) as $response => $chunk) {
            $bodies[spl_object_id($response)] .= $chunk->getContent();
        }

        self::assertSame('first', $bodies[spl_object_id($first)]);
        self::assertSame('second', $bodies[spl_object_id($second)]);
        self::assertSame(2, $collector->getCallCount());
        self::assertSame(300.0, $collector->getTotalDurationMs());
        self::assertSame($collector->getCalls()[1], $collector->getSlowestCall());
    }

    public function testWithOptionsClonesTheClientAndResolvesRelativeUrls(): void
    {
        $collector = new OutboundCallCollector(true);
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('ok', ['total_time' => 0.01])), $collector);
        $configured = $client->withOptions(['base_uri' => 'https://api.example.com/v1/']);

        self::assertNotSame($client, $configured);
        self::assertSame('ok', $configured->request('GET', 'items?token=secret')->getContent());
        self::assertSame('https://api.example.com/v1/items', $collector->getCalls()[0]->target->displayUrl);
    }

    public function testHttpErrorResponseAndExceptionBehaviorArePreserved(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('failure', ['http_code' => 503, 'total_time' => 0.25])), $collector);
        $response = $client->request('POST', 'https://api.example.com/items');

        try {
            $response->getContent();
            self::fail('The normal HTTP exception should be preserved.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame($response, $exception->getResponse());
        }

        self::assertSame(503, $collector->getCalls()[0]->getStatusCode());
        self::assertFalse($collector->getCalls()[0]->isSuccessful());
        self::assertSame('failure', $response->getContent(false));
        self::assertSame(CallOutcome::HttpError, $collector->getCalls()[0]->getOutcome());
        self::assertSame(250.0, $collector->getTotalDurationMs());
    }

    public function testTransportFailureBeforeHeadersIsRecordedWithoutTheErrorMessage(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('', [
            'error' => 'Connection failed: secret-error-value',
            'total_time' => 0.075,
        ])), $collector);
        $response = $client->request('GET', 'https://api.example.com/items');

        try {
            $response->getContent();
            self::fail('The transport error should be preserved.');
        } catch (TransportExceptionInterface $exception) {
            self::assertStringContainsString('secret-error-value', $exception->getMessage());
        }

        self::assertSame(CallOutcome::TransportError, $collector->getCalls()[0]->getOutcome());
        self::assertFalse($collector->getCalls()[0]->isSuccessful());
        self::assertStringNotContainsString('secret-error-value', serialize($collector->getCalls()));
    }

    public function testTransportFailureWhileReadingTheBodyIsRecorded(): void
    {
        $body = static function (): \Generator {
            yield 'partial';
            throw new TransportException('broken stream');
        };
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse($body(), ['total_time' => 0.05])), $collector);
        $response = $client->request('GET', 'https://api.example.com/items');

        try {
            $response->getContent();
            self::fail('The stream should fail.');
        } catch (TransportExceptionInterface) {
            self::assertSame(CallOutcome::TransportError, $collector->getCalls()[0]->getOutcome());
            self::assertSame(200, $collector->getCalls()[0]->getStatusCode());
            self::assertSame(50.0, $collector->getTotalDurationMs());
        }
    }

    public function testSynchronousTransportExceptionIsRethrownUnchangedAndCounted(): void
    {
        $error = new TransportException('secret-connection-error');
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(static function () use ($error): never {
            throw $error;
        }), $collector);

        try {
            $client->request('GET', 'https://api.example.com/items?token=secret');
            self::fail('The request should fail.');
        } catch (TransportExceptionInterface $exception) {
            self::assertSame($error, $exception);
        }

        self::assertSame(1, $collector->getCallCount());
        self::assertSame(CallOutcome::TransportError, $collector->getCalls()[0]->getOutcome());
        self::assertNull($collector->getCalls()[0]->getDurationMs());
        self::assertStringNotContainsString('secret', serialize($collector->getCalls()));
    }

    public function testCancelledRequestsAreNotReportedAsSuccessful(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse('body')), $collector);
        $response = $client->request('GET', 'https://api.example.com/items');
        $response->cancel();

        self::assertTrue($response->getInfo('canceled'));
        self::assertSame(CallOutcome::Cancelled, $collector->getCalls()[0]->getOutcome());
        self::assertFalse($collector->getCalls()[0]->isSuccessful());
    }

    public function testPollingTimeoutDoesNotPermanentlyMarkAResumableResponseAsFailed(): void
    {
        $body = static function (): \Generator {
            yield '';
            yield 'resumed';
        };
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient(new MockResponse($body(), ['total_time' => 0.01])), $collector);
        $response = $client->request('GET', 'https://api.example.com/items');
        $timedOut = false;

        foreach ($client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                $timedOut = true;
                self::assertSame(CallOutcome::Pending, $collector->getCalls()[0]->getOutcome());
                break;
            }
        }

        self::assertTrue($timedOut);
        self::assertSame('resumed', $response->getContent());
        self::assertSame(CallOutcome::Success, $collector->getCalls()[0]->getOutcome());
    }

    public function testLateResponsesCannotContaminateTheNextCollection(): void
    {
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient(new MockHttpClient([
            new MockResponse('old', ['total_time' => 0.5]),
            new MockResponse('new', ['total_time' => 0.02]),
        ]), $collector);
        $old = $client->request('GET', 'https://api.example.com/items');
        $collector->reset();
        $new = $client->request('GET', 'https://api.example.com/items');

        self::assertSame('old', $old->getContent());
        self::assertSame('new', $new->getContent());
        self::assertSame(1, $collector->getCallCount());
        self::assertSame(20.0, $collector->getTotalDurationMs());
    }

    public function testSecretsAreNotStoredAndTheUserProgressCallbackStillRuns(): void
    {
        $progressCalls = 0;
        $mock = new MockHttpClient(new MockResponse('secret-response-body', [
            'total_time' => 0.025,
            'response_headers' => ['set-cookie: secret-response-cookie'],
            'debug' => 'secret-debug',
        ]));
        $collector = new OutboundCallCollector();
        $client = new MonitoringHttpClient($mock, $collector);
        $response = $client->request('POST', 'https://user:secret-password@api.example.com/secret-path?api_key=secret-query#secret-fragment', [
            'headers' => ['Authorization' => 'Bearer secret-authorization', 'Cookie' => 'session=secret-cookie', 'X-API-Key' => 'secret-api-key'],
            'body' => 'secret-request-body',
            'user_data' => 'secret-user-data',
            'on_progress' => static function () use (&$progressCalls): void {
                ++$progressCalls;
            },
        ]);

        self::assertSame('secret-response-body', $response->getContent());
        self::assertGreaterThan(0, $progressCalls);
        self::assertSame(25.0, $collector->getTotalDurationMs());
        self::assertStringNotContainsString('secret-', serialize($collector->getCalls()));
    }
}
