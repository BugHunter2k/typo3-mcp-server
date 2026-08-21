<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP;

use Composer\InstalledVersions;
use Hn\McpServer\MCP\Tool\RequestAwareToolInterface;
use Mcp\Server\Server;
use Mcp\Server\InitializationOptions;
use Mcp\Server\NotificationOptions;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Factory for creating and configuring MCP Server instances
 */
class McpServerFactory
{
    /**
     * Composer package of this extension, for the installed-build lookup below.
     */
    private const PACKAGE = 'hn/typo3-mcp-server';

    public function __construct(
        private readonly ToolRegistry $toolRegistry
    ) {}

    /**
     * Create a fully configured MCP Server instance
     *
     * @param callable|null $debugLogger Optional debug logger function
     */
    public function createServer(?callable $debugLogger = null, ?ServerRequestInterface $request = null): Server
    {
        $serverName = $this->getServerName();
        $server = new Server($serverName);

        $this->registerHandlers($server, $debugLogger, $request);

        return $server;
    }

    /**
     * Create InitializationOptions with proper version information
     */
    public function createInitializationOptions(Server $server): InitializationOptions
    {
        $notificationOptions = new NotificationOptions();
        $capabilities = $server->getCapabilities($notificationOptions, []);

        return new InitializationOptions(
            serverName: $this->getServerName(),
            serverVersion: $this->getServerVersion(),
            capabilities: $capabilities
        );
    }

    /**
     * Get the server name from TYPO3 configuration
     */
    public function getServerName(): string
    {
        return $GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? 'TYPO3 MCP Server';
    }

    /**
     * Get the server version string including extension and TYPO3 versions
     */
    /**
     * The version reported in the initialize handshake's serverInfo.
     *
     * Shape: "dev-lia-main+<commit> (TYPO3 13.4.34)", or "1.2.0 (TYPO3 …)" for a
     * tagged install.
     *
     * The declared version cannot answer "is this installation current?" for
     * anyone installing from a branch: in a Composer install TYPO3 reports the
     * Composer version, so every commit of `lia-main` calls itself
     * "dev-lia-main" (and in a classic install, every commit calls itself
     * whatever ext_emconf.php says). The installed commit is the version that
     * actually distinguishes them, and Composer knows it at runtime, so it
     * rides along in the semver-sanctioned build position: the string stays a
     * valid version for anything that parses it, and a client — or the LIA
     * gateway — can hold it against the delivery branch's head.
     *
     * The suffix is omitted when Composer does not know this package (a classic
     * install, or an extension dropped into typo3conf/ext), which degrades to
     * exactly the previous output rather than to a wrong commit.
     */
    public function getServerVersion(): string
    {
        $extVersion = ExtensionManagementUtility::getExtensionVersion('mcp_server');
        $typo3Version = GeneralUtility::makeInstance(Typo3Version::class)->getVersion();

        $reference = InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getReference(self::PACKAGE)
            : null;

        return $extVersion
            . ($reference !== null ? '+' . $reference : '')
            . ' (TYPO3 ' . $typo3Version . ')';
    }

    /**
     * Register MCP handlers on the server
     */
    private function registerHandlers(Server $server, ?callable $debugLogger, ?ServerRequestInterface $request = null): void
    {
        $toolRegistry = $this->toolRegistry;
        $debug = $debugLogger ?? static fn($msg) => null;

        // Register tool/list handler
        $server->registerHandler('tools/list', static function () use ($toolRegistry, $debug) {
            $debug('Handling tools/list request');
            $tools = [];

            foreach ($toolRegistry->getTools() as $tool) {
                $schema = $tool->getSchema();
                $tools[] = [
                    'name' => $tool->getName(),
                    ...$schema
                ];
            }

            return ['tools' => $tools];
        });

        // Register tool/call handler
        $server->registerHandler('tools/call', static function ($params) use ($toolRegistry, $debug, $request) {
            $toolName = $params->name;
            $arguments = $params->arguments;

            $debug('Handling tools/call request for tool: ' . $toolName);

            $tool = $toolRegistry->getTool($toolName);
            if (!$tool) {
                throw new \InvalidArgumentException('Tool not found: ' . $toolName);
            }

            // Inject request into tools that need it
            if ($request !== null && $tool instanceof RequestAwareToolInterface) {
                $tool->setRequest($request);
            }

            try {
                return $tool->execute($arguments);
            } catch (\Throwable $e) {
                $debug('Error executing tool ' . $toolName . ': ' . $e->getMessage());
                return new CallToolResult(
                    [new TextContent($e->getMessage())],
                    true
                );
            }
        });
    }
}
