<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/sylius-api-catalog

The [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) API catalog at
`/.well-known/api-catalog`, plus the `Link` headers that advertise it.

Both live here because RFC 9727 §3 names the Link header as the discovery route
for the catalog: they are the same data in two serialisations, and splitting them
would mean two packages sharing one registry and normalising it twice.

## Install

```bash
composer require koh/sylius-api-catalog
```

```php
// config/bundles.php
Koh\SyliusApiCatalog\KohSyliusApiCatalogBundle::class => ['all' => true],
```

```yaml
# config/routes/koh_agent.yaml
koh_api_catalog:
    resource: '@KohSyliusApiCatalogBundle/config/routes.php'
```

## Configure

```yaml
# config/packages/koh_api_catalog.yaml
koh_api_catalog:
    link_headers: true
    ttl: 3600
```

## What it lists

Whatever the installed bundles register. Install `koh/sylius-mcp-oauth` and the
MCP endpoint appears with its metadata and `/auth.md`; install
`koh/sylius-llms-txt` and the shop gains a `describedby` link. This package
knows none of them.

Add your own by implementing `ApiCatalogEntryProviderInterface` from
`koh/sylius-agent-contracts` — container-wide autoconfiguration picks it up, so a
service in your `App\` namespace needs no wiring.

With nothing registered the route returns **404**. An empty linkset is a valid
document that claims the origin exposes nothing — a claim, and the wrong one,
when the truth is that no API bundle is installed yet.

## Link headers

```text
Link: </.well-known/api-catalog>; rel="api-catalog"; type="application/linkset+json",
      </openapi.json>; rel="service-desc"; type="application/json",
      </auth.md>; rel="service-doc"; type="text/markdown"
```

Emitted on HTML pages so an agent that lands anywhere finds the machine-readable
surface without knowing to look for `/.well-known/api-catalog`.

Headers are **appended, never replaced** — Sylius sets `rel="preload"` and API
Platform the Hydra documentation link on the same response, and clobbering those
breaks the storefront. The assembled line is cached per channel and locale;
walking every provider on each page view would charge ordinary shoppers for a
discovery feature.

## Decommission

Remove the `composer require`, the bundle entry, the route import and
`config/packages/koh_api_catalog.yaml`. Cached header lines expire on their own.

## Security

Report vulnerabilities privately — see [SECURITY.md](SECURITY.md). Advisories
are published as CSAF 2.0 at <https://ole-hartwig.eu/.well-known/csaf/>.

## Licence

MIT — see [LICENSE](LICENSE).
