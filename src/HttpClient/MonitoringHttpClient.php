<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\HttpClient;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\Metadata\CallOutcome;
use Hanane\OutboundGuardBundle\Metadata\OutboundCall;
use Symfony\Component\HttpClient\AsyncDecoratorTrait;
use Symfony\Component\HttpClient\Response\AsyncContext;
use Symfony\Component\HttpClient\Response\AsyncResponse;
use Symfony\Contracts\HttpClient\ChunkInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Observes transfer metadata while leaving chunks, exceptions and progress callbacks intact. */
final class MonitoringHttpClient implements HttpClientInterface
{
    use AsyncDecoratorTrait;

    public function __construct(HttpClientInterface $client, private readonly OutboundCallCollector $collector)
    {
        $this->client = $client;
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): AsyncResponse
    {
        $call = null;

        try {
            $response = new AsyncResponse($this->client, $method, $url, $options, self::createObserver($call));
        } catch (TransportExceptionInterface $exception) {
            $this->collector->start($method, $url)->finish(CallOutcome::TransportError, null);

            throw $exception;
        }

        // getInfo() is non-blocking. The inner client has resolved base_uri and URI templates.
        // Capture the original target once; later redirects cannot change its fingerprint.
        $resolvedUrl = $response->getInfo('url');
        $call = $this->collector->start($method, is_string($resolvedUrl) ? $resolvedUrl : $url);

        return $response;
    }

    /** @return \Closure(ChunkInterface, AsyncContext): \Generator<int, ChunkInterface> */
    private static function createObserver(?OutboundCall &$call): \Closure
    {
        // AsyncResponse invokes this only when the application consumes or cancels the response.
        // A static callback retains one safe call record, never this client or the collector.
        return static function (ChunkInterface $chunk, AsyncContext $context) use (&$call): \Generator {
            if (null === $call) {
                yield $chunk;

                return;
            }

            $status = $context->getInfo('http_code');
            $call->recordStatus(is_int($status) ? $status : null);
            $duration = $context->getInfo('total_time');
            $durationMs = is_float($duration) || is_int($duration) ? $duration * 1000 : null;

            if (true === $context->getInfo('canceled')) {
                $call->finish(CallOutcome::Cancelled, $durationMs);
            } elseif (null !== $chunk->getError()) {
                // A polling timeout is recoverable. A transport error throws from isTimeout().
                try {
                    $chunk->isTimeout();
                } catch (TransportExceptionInterface) {
                    $call->finish(CallOutcome::TransportError, $durationMs);
                }
            } elseif ($chunk->isLast()) {
                $call->finish(null !== $call->getStatusCode() && $call->getStatusCode() >= 400 ? CallOutcome::HttpError : CallOutcome::Success, $durationMs);
            }

            yield $chunk;
        };
    }
}
