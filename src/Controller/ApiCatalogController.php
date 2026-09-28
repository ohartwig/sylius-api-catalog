<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig <mail@ole-hartwig.eu>
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusApiCatalog\Controller;

use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusAgentContracts\Registry\ApiCatalogRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the RFC 9727 API catalog.
 *
 * The linkset is assembled from every installed bundle, which is the whole
 * reason this package can exist separately from the APIs it describes.
 */
final class ApiCatalogController
{
    public const CONTENT_TYPE = 'application/linkset+json';

    public function __construct(
        private readonly ApiCatalogRegistry $registry,
        private readonly AgentContextFactoryInterface $contextFactory,
        private readonly AgentJsonResponder $responder,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $context = $this->contextFactory->create();
        $entries = $this->registry->all($context);

        // An empty linkset is a valid document that says "this origin exposes
        // nothing" — a claim, and the wrong one, when the real answer is that
        // no API bundle is installed yet.
        if ([] === $entries) {
            throw new NotFoundHttpException('No API catalog entries are registered.');
        }

        return $this->responder->respond(
            ['linkset' => array_map(static fn ($entry): array => $entry->toArray(), $entries)],
            self::CONTENT_TYPE,
            request: $request,
        );
    }
}
