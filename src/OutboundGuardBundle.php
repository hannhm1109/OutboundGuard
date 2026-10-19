<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle;

use Hanane\OutboundGuardBundle\DependencyInjection\Compiler\HttpClientPass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class OutboundGuardBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        self::configureOptions($definition->rootNode());
    }

    private static function configureOptions(NodeDefinition $root): void
    {
        // Symfony 6.4 types rootNode() as NodeDefinition; newer versions narrow it to ArrayNodeDefinition.
        if (!$root instanceof ArrayNodeDefinition) {
            throw new \LogicException('OutboundGuard configuration requires an array root node.');
        }

        $children = $root->children();
        $children->booleanNode('enabled')->defaultTrue();
        $children->booleanNode('capture_paths')
            ->info('Store URL paths only when they contain no credentials or personal data.')
            ->defaultFalse();
    }

    /** @param array{enabled: bool, capture_paths: bool} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        $container->import('../config/services.php');
        $container->services()->get(Collector\OutboundCallCollector::class)
            ->arg('$capturePaths', $config['capture_paths']);
    }
}
