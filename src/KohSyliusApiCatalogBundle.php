<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * RFC 9727 API catalog and the Link headers that advertise it.
 */
final class KohSyliusApiCatalogBundle extends AbstractBundle
{
    protected string $extensionAlias = 'koh_api_catalog';

    /** config/ sits at the package root, not under src/. */
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->booleanNode('link_headers')
                    ->defaultTrue()
                    ->info('Advertise the catalog through Link headers on HTML pages (RFC 9727 §3).')
                ->end()
                ->integerNode('ttl')
                    ->defaultValue(3600)
                    ->info('How long the assembled Link header line is cached, per channel and locale.')
                ->end()
            ->end();
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('koh_api_catalog.link_headers', (bool) ($config['link_headers'] ?? true));
        $builder->setParameter('koh_api_catalog.ttl', (int) ($config['ttl'] ?? 3600));

        $container->import('../config/services.php');
    }
}
