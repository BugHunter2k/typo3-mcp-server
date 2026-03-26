<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Trait for adding CORS headers to HTTP responses
 */
trait CorsHeadersTrait
{
    /**
     * Add CORS headers to response for OAuth/API endpoints
     */
    private function addCorsHeaders(ResponseInterface $response, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $allowedOrigin = $this->getAllowedOrigin($request);

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowedOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->withHeader('Access-Control-Max-Age', '86400');

        // Only set Allow-Credentials when origin is not wildcard
        if ($allowedOrigin !== '*') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    /**
     * Get the allowed origin from the request
     */
    private function getAllowedOrigin(?ServerRequestInterface $request = null): string
    {
        $request = $request ?? ($GLOBALS['TYPO3_REQUEST'] ?? null);
        if ($request && $request->hasHeader('Origin')) {
            return $request->getHeaderLine('Origin');
        }

        return '*';
    }

    /**
     * Handle preflight OPTIONS requests
     */
    private function handlePreflightRequest(?ServerRequestInterface $request = null): ResponseInterface
    {
        $response = new \TYPO3\CMS\Core\Http\Response();
        return $this->addCorsHeaders($response->withStatus(200), $request);
    }
}