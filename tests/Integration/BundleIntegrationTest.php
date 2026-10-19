<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Integration;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\HttpClient\MonitoringHttpClient;
use Hanane\OutboundGuardBundle\Tests\Fixtures\HttpClientConsumer;
use Hanane\OutboundGuardBundle\Tests\Fixtures\TestKernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Service\ResetInterface;

final class BundleIntegrationTest extends TestCase
{
    public function testExistingDefaultAndScopedClientInjectionIsTransparentlyMonitored(): void
    {
        $kernel = new TestKernel(capturePaths: true);
        $kernel->boot();

        try {
            $consumer = $kernel->getContainer()->get(HttpClientConsumer::class);
            self::assertInstanceOf(HttpClientConsumer::class, $consumer);
            self::assertInstanceOf(MonitoringHttpClient::class, $consumer->httpClient);
            self::assertInstanceOf(MonitoringHttpClient::class, $consumer->exampleClient);

            $consumer->httpClient->request('GET', 'https://api.example.com/default')->getContent();
            $consumer->exampleClient->request('GET', 'items?token=secret')->getContent();
            $collector = $this->collector($kernel);

            self::assertSame(2, $collector->getCallCount());
            self::assertSame(50.0, $collector->getTotalDurationMs());
            self::assertSame('https://api.example.com/v1/items', $collector->getCalls()[1]->target->displayUrl);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testConsecutiveMainRequestsAreIsolatedAndSubrequestsShareTheirParentCollection(): void
    {
        $kernel = new TestKernel();
        $kernel->boot();

        try {
            $consumer = $kernel->getContainer()->get(HttpClientConsumer::class);
            self::assertInstanceOf(HttpClientConsumer::class, $consumer);
            $makeCall = static function () use ($consumer): Response {
                $consumer->httpClient->request('GET', 'https://api.example.com/items')->getContent();

                return new Response('ok');
            };
            $main = new Request(attributes: ['_controller' => static function () use ($kernel, $makeCall): Response {
                $makeCall();
                $kernel->handle(new Request(attributes: ['_controller' => $makeCall]), HttpKernelInterface::SUB_REQUEST);

                return new Response('main');
            }]);

            self::assertSame('main', $kernel->handle($main)->getContent());
            $collector = $this->collector($kernel);
            self::assertSame(2, $collector->getCallCount());
            $previousFingerprint = $collector->getCalls()[0]->target->fingerprint;

            self::assertSame('ok', $kernel->handle(new Request(attributes: ['_controller' => $makeCall]))->getContent());
            self::assertSame(1, $collector->getCallCount());
            self::assertSame(25.0, $collector->getTotalDurationMs());
            self::assertNotSame($previousFingerprint, $collector->getCalls()[0]->target->fingerprint);

            $resetter = $kernel->getContainer()->get('services_resetter');
            self::assertInstanceOf(ResetInterface::class, $resetter);
            $resetter->reset();
            self::assertSame(0, $collector->getCallCount());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testDisabledBundleLeavesTheClientsUndecorated(): void
    {
        $kernel = new TestKernel(guardEnabled: false);
        $kernel->boot();

        try {
            $consumer = $kernel->getContainer()->get(HttpClientConsumer::class);
            self::assertInstanceOf(HttpClientConsumer::class, $consumer);
            self::assertNotInstanceOf(MonitoringHttpClient::class, $consumer->httpClient);
            self::assertNotInstanceOf(MonitoringHttpClient::class, $consumer->exampleClient);
            self::assertFalse($kernel->getContainer()->has(OutboundCallCollector::class));
            self::assertSame('ok', $consumer->httpClient->request('GET', 'https://api.example.com/items')->getContent());
        } finally {
            $kernel->shutdown();
        }
    }

    public function testAnApplicationWithoutAnEnabledHttpClientStillBoots(): void
    {
        $kernel = new TestKernel(httpClientEnabled: false);
        $kernel->boot();

        try {
            self::assertSame(0, $this->collector($kernel)->getCallCount());
        } finally {
            $kernel->shutdown();
        }
    }

    private function collector(TestKernel $kernel): OutboundCallCollector
    {
        $collector = $kernel->getContainer()->get('test.outbound_collector');
        self::assertInstanceOf(OutboundCallCollector::class, $collector);

        return $collector;
    }
}
