<?php

declare(strict_types=1);

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\EventSubscriber\RequestResetSubscriber;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(OutboundCallCollector::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(RequestResetSubscriber::class)
        ->args([service(OutboundCallCollector::class)])
        ->tag('kernel.event_subscriber');
};
