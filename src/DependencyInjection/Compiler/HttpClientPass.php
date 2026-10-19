<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\DependencyInjection\Compiler;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\HttpClient\MonitoringHttpClient;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/** Decorates logical clients, including scoped clients, without double-counting the transport. */
final class HttpClientPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OutboundCallCollector::class)) {
            return;
        }

        foreach ($container->findTaggedServiceIds('http_client.client') as $id => $tags) {
            $decoratorId = 'outbound_guard.http_client.'.$id;

            $container->register($decoratorId, MonitoringHttpClient::class)
                ->setDecoratedService($id, null, 0)
                ->setArguments([
                    new Reference($decoratorId.'.inner'),
                    new Reference(OutboundCallCollector::class),
                ]);
        }
    }
}
