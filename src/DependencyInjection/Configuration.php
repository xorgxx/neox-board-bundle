<?php

declare(strict_types=1);

namespace Neox\BoardBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('neox_board');
        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')->defaultTrue()->end()
                ->arrayNode('weather')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->floatNode('latitude')->defaultValue(45.7640)->min(-90)->max(90)->end()
                    ->floatNode('longitude')->defaultValue(4.8357)->min(-180)->max(180)->end()
                    ->scalarNode('timezone')->defaultValue('Europe/Paris')->end()
                    ->scalarNode('location')->defaultValue('Lyon')->end()
                    ->scalarNode('api_url')->defaultValue('https://api.open-meteo.com/v1/forecast')->end()
                    ->scalarNode('api_key')->defaultNull()->end()
                ->end()->end()
                ->arrayNode('calendar')->addDefaultsIfNotSet()->children()
                    ->booleanNode('enabled')->defaultTrue()->end()
                    ->scalarNode('city')->defaultValue('Lyon')->end()
                    ->scalarNode('school_zone')->defaultValue('Zone A')->end()
                ->end()->end()
                ->arrayNode('security')->addDefaultsIfNotSet()->children()
                    ->booleanNode('require_authentication')->defaultTrue()->end()
                    ->scalarNode('role')->defaultNull()->end()
                ->end()->end()
                ->arrayNode('realtime')->addDefaultsIfNotSet()->children()
                    ->booleanNode('mercure_enabled')->defaultFalse()->end()
                    ->scalarNode('mercure_hub_url')->defaultNull()->end()
                    ->scalarNode('mercure_public_url')->defaultNull()->end()
                    ->scalarNode('mercure_jwt_secret')->defaultNull()->end()
                    ->booleanNode('async_enabled')->defaultFalse()->end()
                    ->scalarNode('messenger_bus')->defaultNull()->end()
                ->end()->end()
            ->end();

        return $treeBuilder;
    }
}
