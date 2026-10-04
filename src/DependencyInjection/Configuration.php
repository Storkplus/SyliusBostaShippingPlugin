<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\DependencyInjection;

use Storkplus\SyliusBostaShippingPlugin\Bosta;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('storkplus_sylius_bosta_shipping');

        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode('api_base_url')->defaultValue(Bosta::API_BASE_URL)->cannotBeEmpty()->end()
                ->floatNode('timeout')->defaultValue(20.0)->min(1.0)->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
