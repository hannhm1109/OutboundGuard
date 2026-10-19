<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Unit\EventSubscriber;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\EventSubscriber\RequestResetSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class RequestResetSubscriberTest extends TestCase
{
    public function testOnlyMainRequestsStartANewCollection(): void
    {
        $collector = new OutboundCallCollector();
        $subscriber = new RequestResetSubscriber($collector);
        $kernel = $this->createStub(HttpKernelInterface::class);
        $collector->start('GET', 'https://api.example.com/items');

        $subscriber->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $collector->getCallCount());

        $subscriber->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $collector->getCallCount());
    }
}
