<?php

declare(strict_types=1);

namespace Hanane\OutboundGuardBundle;

use Hanane\OutboundGuardBundle\Analysis\Budget;
use Hanane\OutboundGuardBundle\DependencyInjection\Compiler\HttpClientPass;
use Hanane\OutboundGuardBundle\DependencyInjection\Compiler\ProfilerPass;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\NodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class OutboundGuardBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new HttpClientPass());
        $container->addCompilerPass(new ProfilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 10);
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
        $children->integerNode('max_calls')->min(0)->defaultValue(5);
        $children->integerNode('max_total_duration_ms')->min(0)->defaultValue(1000);
        $children->integerNode('slow_call_threshold_ms')->min(0)->defaultValue(500);
    }

    /** @param array{enabled: bool, capture_paths: bool, max_calls: int, max_total_duration_ms: int, slow_call_threshold_ms: int} $config */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if (!$config['enabled']) {
            return;
        }

        $container->import('../config/services.php');
        $container->services()->get(Collector\OutboundCallCollector::class)
            ->arg('$capturePaths', $config['capture_paths']);
        $container->services()->get(Budget::class)
            ->args([$config['max_calls'], $config['max_total_duration_ms'], $config['slow_call_threshold_ms']]);
    }
}
