<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

use Koh\SyliusApiCatalog\Controller\ApiCatalogController;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->add('koh_api_catalog', '/.well-known/api-catalog')
        ->controller(ApiCatalogController::class)
        ->methods(['GET', 'HEAD']);
};
