<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\EventSubscriber;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class RequestResetSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly OutboundCallCollector $collector)
    {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // Run before normal routing, security and application request listeners.
        return [KernelEvents::REQUEST => ['onKernelRequest', PHP_INT_MAX]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->collector->reset();
        }
    }
}
