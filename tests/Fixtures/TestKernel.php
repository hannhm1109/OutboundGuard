<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle\Tests\Fixtures;

use Hanane\OutboundGuardBundle\Collector\OutboundCallCollector;
use Hanane\OutboundGuardBundle\OutboundGuardBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public function __construct(private readonly bool $guardEnabled = true, private readonly bool $capturePaths = false, private readonly bool $httpClientEnabled = true)
    {
        parent::__construct('test_'.bin2hex(random_bytes(6)), true);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new OutboundGuardBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', [
                'secret' => 'test-only',
                'http_method_override' => false,
                'handle_all_throwables' => true,
                'php_errors' => ['log' => false],
                'http_client' => $this->httpClientEnabled ? [
                    'mock_response_factory' => ResponseFactory::class,
                    'scoped_clients' => [
                        'example.client' => ['base_uri' => 'https://api.example.com/v1/'],
                    ],
                ] : ['enabled' => false],
            ]);
            $container->loadFromExtension('outbound_guard', [
                'enabled' => $this->guardEnabled,
                'capture_paths' => $this->capturePaths,
            ]);

            if ($this->guardEnabled) {
                $container->setAlias('test.outbound_collector', OutboundCallCollector::class)->setPublic(true);
            }

            if ($this->httpClientEnabled) {
                $container->register(ResponseFactory::class);
                $container->register(HttpClientConsumer::class)->setAutowired(true)->setPublic(true);
            }
        });
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__, 2).'/var/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__, 2).'/var/log';
    }

    public function getProjectDir(): string
    {
        // FrameworkBundle may generate application config references; keep those out of bundle config/.
        return dirname(__DIR__, 2).'/var/test-app/'.$this->environment;
    }
}
