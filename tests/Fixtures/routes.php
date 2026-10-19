<?php

declare(strict_types=1);

use Hanane\OutboundGuardBundle\Tests\Fixtures\ProfilerController;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('fixture_checkout', '/checkout')->controller([ProfilerController::class, 'checkout']);
    $routes->add('fixture_empty', '/empty')->controller([ProfilerController::class, 'empty']);
    $routes->add('fixture_stream', '/stream')->controller([ProfilerController::class, 'stream']);

    $directory = (new WebProfilerBundle())->getPath().'/Resources/config/routing/';
    $extension = is_file($directory.'profiler.php') ? 'php' : 'xml';
    $routes->import($directory.'profiler.'.$extension)->prefix('/_profiler');
    $routes->import($directory.'wdt.'.$extension)->prefix('/_wdt');
};
