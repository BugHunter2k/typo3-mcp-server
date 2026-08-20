<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\McpEndpoint;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;

/**
 * Access tokens must never reach the log.
 *
 * The endpoint logs the request for debugging, and extractToken() accepts the bearer both
 * in the Authorization header and — for backward compatibility — in the "token" query
 * parameter. Dumping either verbatim puts a usable credential in the PHP error log of every
 * installation, on every single request.
 */
class McpEndpointLogHygieneTest extends AbstractFunctionalTest
{
    private const SECRET = 'mcpt_bearer_that_must_not_be_logged_0123456789';

    private mixed $previousRequest;
    private mixed $previousConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $this->previousConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? null;
        // There is nothing to redact unless the trace is switched on, so these run with
        // debugLogging enabled: that is the dangerous configuration and the one worth
        // guarding. testNothingIsTracedWhileTheSwitchIsOff() turns it back off.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['debugLogging'] = '1';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousConfiguration;
        parent::tearDown();
    }

    public function testTheBearerNeverReachesTheLogFromTheHeader(): void
    {
        $log = $this->captureLog(
            (new ServerRequest(new Uri('https://example.com/mcp'), 'POST'))
                ->withHeader('Authorization', 'Bearer ' . self::SECRET)
        );

        self::assertStringNotContainsString(self::SECRET, $log);
        self::assertStringContainsString('<redacted>', $log, 'The header must still be listed');
    }

    /**
     * The query fallback is the easier one to forget, and the worse one to leak: a URL
     * carrying a token also lands in access logs, proxies and browser history.
     */
    public function testTheBearerNeverReachesTheLogFromTheQueryParameter(): void
    {
        $log = $this->captureLog(
            (new ServerRequest(new Uri('https://example.com/mcp'), 'POST'))
                ->withQueryParams(['token' => self::SECRET])
        );

        self::assertStringNotContainsString(self::SECRET, $log);
        self::assertStringContainsString('<redacted>', $log);
    }

    /**
     * Not even a prefix: 20 characters of a live credential is still part of one, and the
     * be_user_uid logged after a successful validation is the identifier worth having.
     */
    public function testNoFragmentOfTheBearerIsLogged(): void
    {
        $log = $this->captureLog(
            (new ServerRequest(new Uri('https://example.com/mcp'), 'POST'))
                ->withHeader('Authorization', 'Bearer ' . self::SECRET)
        );

        self::assertStringNotContainsString(substr(self::SECRET, 0, 20), $log);
        self::assertStringContainsString('Bearer token present', $log);
    }

    /**
     * The default. Several lines per request bury everything else in the PHP error log,
     * including the authentication failures — which stay logged either way, because they
     * are what you go looking for when something is wrong.
     */
    public function testNothingIsTracedWhileTheSwitchIsOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server']['debugLogging'] = '0';

        $log = $this->captureLog(
            (new ServerRequest(new Uri('https://example.com/mcp'), 'POST'))
                ->withHeader('Authorization', 'Bearer ' . self::SECRET)
        );

        self::assertStringNotContainsString('MCP: Request headers', $log);
        self::assertStringNotContainsString('Bearer token present', $log);
        self::assertStringNotContainsString(self::SECRET, $log);
        self::assertStringContainsString('Token validation failed', $log, 'Failures stay logged');
    }

    public function testTheDiagnosticNamesTheMethodAndStatus(): void
    {
        // An unauthenticated request never reaches the adapter, so drive the diagnostic
        // through the path that does: it only needs the method and the resolved status.
        $log = $this->captureLog(
            (new ServerRequest(new Uri('https://example.com/mcp'), 'DELETE'))
                ->withHeader('Authorization', 'Bearer ' . self::SECRET)
        );

        self::assertStringContainsString('MCP: Request method: DELETE', $log);
    }

    private function captureLog(ServerRequest $request): string
    {
        $target = tempnam(sys_get_temp_dir(), 'mcp-log-');
        self::assertNotFalse($target);

        $previous = ini_get('error_log');
        ini_set('error_log', $target);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        try {
            (new McpEndpoint())($request);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $log = (string)file_get_contents($target);
        unlink($target);

        return $log;
    }
}
