<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Optional interface for MCP tools that need access to the HTTP request.
 *
 * The tool runner checks for this interface before calling execute()
 * and injects the request via setRequest(). This avoids breaking the
 * ToolInterface signature while eliminating $GLOBALS['TYPO3_REQUEST'] usage.
 */
interface RequestAwareToolInterface
{
    public function setRequest(ServerRequestInterface $request): void;
}
