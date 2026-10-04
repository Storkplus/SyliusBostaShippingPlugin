<?php

declare(strict_types=1);

namespace Storkplus\SyliusBostaShippingPlugin\DependencyInjection;

use Sylius\Bundle\CoreBundle\DependencyInjection\PrependDoctrineMigrationsTrait;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class StorkplusSyliusBostaShippingExtension extends Extension implements PrependExtensionInterface
{
    use PrependDoctrineMigrationsTrait;

    public function load(array $configs, ContainerBuilder $container): void
    {
        /** @var array{api_base_url: string, timeout: float} $config */
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('storkplus_sylius_bosta_shipping.api_base_url', $config['api_base_url']);
        $container->setParameter('storkplus_sylius_bosta_shipping.timeout', $config['timeout']);

        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'entity_managers' => [
                    'default' => [
                        'mappings' => [
                            'StorkplusSyliusBostaShippingPlugin' => [
                                'dir' => \dirname(__DIR__, 2) . '/config/doctrine',
                                'is_bundle' => false,
                                'prefix' => 'Storkplus\SyliusBostaShippingPlugin\Entity',
                                'type' => 'xml',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->prependDoctrineMigrations($container);
    }

    protected function getMigrationsNamespace(): string
    {
        return 'Storkplus\SyliusBostaShippingPlugin\Migrations';
    }

    protected function getMigrationsDirectory(): string
    {
        return '@StorkplusSyliusBostaShippingPlugin/src/Migrations';
    }

    protected function getNamespacesOfMigrationsExecutedBefore(): array
    {
        return ['Sylius\Bundle\CoreBundle\Migrations'];
    }
}
