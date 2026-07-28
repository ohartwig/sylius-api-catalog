<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Koh\SyliusApiCatalog\Controller\ApiCatalogController;
use Koh\SyliusApiCatalog\EventListener\LinkHeaderContributor;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(ApiCatalogController::class)
        ->args([
            service(ApiCatalogRegistry::class),
            service(AgentContextFactoryInterface::class),
            service(AgentJsonResponder::class),
        ])
        ->public()
        ->tag('controller.service_arguments');

    $services->set(LinkHeaderContributor::class)
        ->args([
            service(ApiCatalogRegistry::class),
            service('cache.app'),
            param('koh_api_catalog.ttl'),
            param('koh_api_catalog.link_headers'),
        ])
        ->tag('koh_agent.response_header');
};
