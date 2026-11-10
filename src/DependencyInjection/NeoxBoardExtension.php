<?php

declare(strict_types=1);

namespace Neox\BoardBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

final class NeoxBoardExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        $bundlePath = dirname(__DIR__, 2);

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'NeoxBoardBundle' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => $bundlePath.'/src/Entity',
                        'prefix' => 'Neox\\BoardBundle\\Entity',
                    ],
                ],
            ],
        ]);
        $container->prependExtensionConfig('twig', [
            'paths' => [$bundlePath.'/templates' => 'NeoxBoard'],
        ]);
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $container->setParameter('neox_board.config', $config);
        $container->setParameter('neox_board.weather', $config['weather']);
        foreach ($config['weather'] as $name => $value) {
            $container->setParameter('neox_board.weather.'.$name, $value);
        }
        $container->setParameter('neox_board.calendar', $config['calendar']);
        foreach ($config['calendar'] as $name => $value) {
            $container->setParameter('neox_board.calendar.'.$name, $value);
        }
        $container->setParameter('neox_board.realtime', $config['realtime']);
        $container->setParameter('neox_board.realtime.mercure_hub_url', $config['realtime']['mercure_hub_url']);
        $container->setParameter('neox_board.realtime.mercure_public_url', $config['realtime']['mercure_public_url']);
        $container->setParameter('neox_board.realtime.mercure_jwt_secret', $config['realtime']['mercure_jwt_secret']);
        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../../config'));
        $loader->load('services.yaml');
        $publisher = $config['realtime']['mercure_enabled']
            ? \Neox\BoardBundle\Realtime\MercureTurboBroadcaster::class
            : \Neox\BoardBundle\Realtime\NullDashboardBroadcaster::class;
        $container->setAlias(\Neox\BoardBundle\Realtime\DashboardBroadcaster::class, $publisher);
    }
}
