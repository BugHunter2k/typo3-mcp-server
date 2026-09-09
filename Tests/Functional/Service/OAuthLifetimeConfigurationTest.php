<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Service;

use Hn\McpServer\Service\OAuthService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;

/**
 * Token and authorization code lifetimes come from the extension configuration.
 *
 * The token lifetime is the upper bound on an unattended session, so an
 * installation has to be able to shorten it without patching the extension.
 */
class OAuthLifetimeConfigurationTest extends AbstractFunctionalTest
{
    private const EIGHT_HOURS = 28800;
    private const THIRTY_DAYS = 2592000;
    private const TEN_MINUTES = 600;

    private array $previousExtensionConfiguration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousExtensionConfiguration = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = $this->previousExtensionConfiguration;
        parent::tearDown();
    }

    public function testLifetimesFallBackToTheDefaultsWhenNotConfigured(): void
    {
        $this->configure(['tokenLifetime' => '', 'authorizationCodeLifetime' => '']);

        $service = new OAuthService();

        self::assertSame(self::THIRTY_DAYS, $service->getTokenLifetime());
        self::assertSame(self::TEN_MINUTES, $service->getAuthorizationCodeLifetime());
    }

    public function testConfiguredTokenLifetimeIsUsed(): void
    {
        $this->configure(['tokenLifetime' => (string)self::EIGHT_HOURS]);

        self::assertSame(self::EIGHT_HOURS, (new OAuthService())->getTokenLifetime());
    }

    public function testConfiguredAuthorizationCodeLifetimeIsUsed(): void
    {
        $this->configure(['authorizationCodeLifetime' => '120']);

        self::assertSame(120, (new OAuthService())->getAuthorizationCodeLifetime());
    }

    public function testIssuedTokenExpiresWithinTheConfiguredLifetime(): void
    {
        $this->configure(['tokenLifetime' => (string)self::EIGHT_HOURS]);

        $service = new OAuthService();
        $before = time();
        $token = $service->createToken(1, 'Test Client');

        // A token must never outlive the configured lifetime, which is the whole
        // point of shortening it.
        self::assertSame(self::EIGHT_HOURS, (int)$token['expires_in']);
        self::assertLessThanOrEqual($before + self::EIGHT_HOURS + 5, (int)$token['expires']);
        self::assertGreaterThan($before, (int)$token['expires']);
    }

    /**
     * A present but non-positive value is a misconfiguration. Falling back to the
     * default would hand out sessions far longer than the operator asked for, so
     * it has to surface instead.
     */
    public function testNonPositiveLifetimeIsRejected(): void
    {
        $this->configure(['tokenLifetime' => '0']);

        $this->expectException(\InvalidArgumentException::class);
        (new OAuthService())->getTokenLifetime();
    }

    public function testNegativeLifetimeIsRejected(): void
    {
        $this->configure(['tokenLifetime' => '-1']);

        $this->expectException(\InvalidArgumentException::class);
        (new OAuthService())->getTokenLifetime();
    }

    /**
     * @param array<string, string> $settings
     */
    private function configure(array $settings): void
    {
        // ExtensionConfiguration::get() reads $GLOBALS directly and keeps no
        // per-instance cache, so this is enough — setAll() would write to the
        // installation's LocalConfiguration.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] = array_merge(
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['mcp_server'] ?? [],
            $settings
        );
    }
}
