<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Central service for base URL resolution.
 *
 * Replaces scattered base-URL logic across 8 locations with a single source of truth.
 * Validates request hosts against the configured TYPO3 site when siteRootPageId is set.
 */
class BaseUrlService
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {}

    /**
     * Resolve the base URL from an HTTP request.
     *
     * When siteRootPageId is configured, validates the request host against
     * the site's base URL and base variants (staging, dev environments).
     * When siteRootPageId = 0 (default), all hosts are accepted.
     */
    public function getBaseUrl(ServerRequestInterface $request): string
    {
        $requestHost = $request->getUri()->getHost();
        $site = $this->getConfiguredSite();

        if ($site !== null && !$this->isValidHostForSite($requestHost, $site)) {
            throw new \RuntimeException(
                sprintf('Host "%s" is not configured for site %d', $requestHost, $site->getRootPageId())
            );
        }

        return $this->buildBaseUrlFromUri($request->getUri());
    }

    /**
     * CLI fallback — no HTTP request available.
     *
     * Uses the configured site (siteRootPageId) to determine the base URL.
     * Eliminates the "first site wins" ambiguity in multi-site setups.
     */
    public function getBaseUrlFromSiteConfiguration(): string
    {
        $site = $this->getConfiguredSite();
        if ($site !== null) {
            return rtrim((string)$site->getBase(), '/');
        }

        // Fallback for siteRootPageId = 0: first site with absolute URL
        foreach ($this->siteFinder->getAllSites() as $site) {
            $base = rtrim((string)$site->getBase(), '/');
            if (str_starts_with($base, 'http')) {
                return $base;
            }
        }

        throw new \RuntimeException(
            'No site with absolute base URL found. Configure siteRootPageId in MCP Server extension settings.'
        );
    }

    public function getConfiguredSite(): ?Site
    {
        $rootPageId = (int)$this->extensionConfiguration->get('mcp_server', 'siteRootPageId');
        if ($rootPageId === 0) {
            return null;
        }
        return $this->siteFinder->getSiteByRootPageId($rootPageId);
    }

    /**
     * Check if the request host belongs to the configured site.
     * Allows the site base and all base variants (staging, dev).
     */
    private function isValidHostForSite(string $host, Site $site): bool
    {
        if ($site->getBase()->getHost() === $host) {
            return true;
        }
        foreach ($site->getBaseVariants() as $variant) {
            if ($variant->getBase()->getHost() === $host) {
                return true;
            }
        }
        return false;
    }

    protected function buildBaseUrlFromUri(\Psr\Http\Message\UriInterface $uri): string
    {
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        $port = $uri->getPort();
        if ($port !== null && !in_array($port, [80, 443], true)) {
            $baseUrl .= ':' . $port;
        }
        return $baseUrl;
    }
}
