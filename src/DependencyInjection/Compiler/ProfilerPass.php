<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\DependencyInjection\Compiler;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\DataCollector\OutboundGuardDataCollector;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ProfilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(OutboundCallCollector::class)
            || !$container->hasDefinition('profiler')
            || !$container->hasDefinition('web_profiler.controller.profiler')
            || !$container->hasDefinition('twig')) {
            return;
        }

        $container->register(OutboundGuardDataCollector::class, OutboundGuardDataCollector::class)
            ->setArguments([new Reference(OutboundCallCollector::class)])
            ->addTag('data_collector', [
                'id' => 'outbound_guard',
                'template' => '@OutboundGuard/Collector/outbound_guard.html.twig',
            ]);
    }
}
