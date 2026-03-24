<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\File\GetUploadCredentialsTool;
use Hn\McpServer\Service\SiteInformationService;
use Mcp\Types\TextContent;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class GetUploadCredentialsToolTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = [
        'workspaces',
        'frontend',
    ];

    protected array $testExtensionsToLoad = [
        'mcp_server',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        // Create storage base path
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
    }

    public function testValidFolderReturnsCredentials(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test-upload.jpg',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));
        $this->assertCount(1, $result->content);
        $this->assertInstanceOf(TextContent::class, $result->content[0]);

        $content = $result->content[0]->text;

        $this->assertStringContainsString('UPLOAD CREDENTIALS GENERATED', $content);
        $this->assertStringContainsString('Upload URL: https://example.com/mcp/upload', $content);
        $this->assertStringContainsString('Token:', $content);
        $this->assertStringContainsString('Expires in: 300 seconds', $content);
        $this->assertStringContainsString("curl -X POST 'https://example.com/mcp/upload'", $content);
    }

    public function testTokenHashIsStoredInDatabase(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test.jpg',
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        // Extract the token from the output
        $content = $result->content[0]->text;
        preg_match('/Token: ([a-f0-9]+)/', $content, $matches);
        $token = $matches[1] ?? '';
        $this->assertNotEmpty($token, 'Token should be in output');
        $this->assertSame(64, strlen($token), 'Token should be 64 hex chars');

        // Check the database stores the HASH, not the plaintext token
        $expectedHash = hash('sha256', $token);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $row = $connection->executeQuery(
            'SELECT * FROM tx_mcpserver_upload_tokens WHERE token_hash = ?',
            [$expectedHash]
        )->fetchAssociative();

        $this->assertIsArray($row, 'Token record should exist in database');
        $this->assertSame($expectedHash, $row['token_hash']);
        $this->assertSame('1:/', $row['folder']);
        $this->assertSame('test.jpg', $row['filename']);
        $this->assertSame(0, (int)$row['used']);
    }

    public function testInvalidFolderReturnsError(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $result = $tool->execute([
            'folder' => '999:/nonexistent/',
            'filename' => 'test.jpg',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('Invalid folder', $result->content[0]->text);
    }

    public function testFilenameWithPathSeparatorIsRejected(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        // Test forward slash
        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'path/to/file.jpg',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('path separators', $result->content[0]->text);

        // Test backslash
        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'path\\to\\file.jpg',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('path separators', $result->content[0]->text);
    }

    public function testExpiredTokensAreCleanedUp(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        // Insert an expired token directly into the database
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $expiredTime = time() - 3600; // 1 hour ago
        $connection->insert('tx_mcpserver_upload_tokens', [
            'pid' => 0,
            'tstamp' => $expiredTime,
            'crdate' => $expiredTime,
            'token_hash' => 'expired_hash_123',
            'folder' => '1:/',
            'filename' => 'old.jpg',
            'max_size' => 52428800,
            'be_user_uid' => 1,
            'expires_at' => $expiredTime, // Already expired
            'used' => 0,
        ]);

        // Verify expired token exists
        $expiredCount = $connection->executeQuery(
            'SELECT COUNT(*) FROM tx_mcpserver_upload_tokens WHERE token_hash = ?',
            ['expired_hash_123']
        )->fetchOne();
        $this->assertSame('1', (string)$expiredCount, 'Expired token should exist before cleanup');

        // Create a new token (this triggers lazy cleanup)
        $tool = new GetUploadCredentialsTool($siteInformationService);
        $tool->execute([
            'folder' => '1:/',
            'filename' => 'new.jpg',
        ]);

        // Verify expired token was cleaned up
        $expiredCount = $connection->executeQuery(
            'SELECT COUNT(*) FROM tx_mcpserver_upload_tokens WHERE token_hash = ?',
            ['expired_hash_123']
        )->fetchOne();
        $this->assertSame('0', (string)$expiredCount, 'Expired token should be cleaned up');
    }

    public function testCustomMaxSize(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $customMaxSize = 10 * 1024 * 1024; // 10 MB

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test.jpg',
            'maxSize' => $customMaxSize,
        ]);

        $this->assertFalse($result->isError, json_encode($result->jsonSerialize()));

        $content = $result->content[0]->text;
        $this->assertStringContainsString('Max size: 10 MB', $content);

        // Verify it was stored in the database
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $row = $connection->executeQuery(
            'SELECT max_size FROM tx_mcpserver_upload_tokens ORDER BY uid DESC LIMIT 1'
        )->fetchAssociative();

        $this->assertSame($customMaxSize, (int)$row['max_size']);
    }

    public function testMissingBaseUrlReturnsError(): void
    {
        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn(null);

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test.jpg',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('base URL', $result->content[0]->text);
    }

    public function testMissingTokensTableReturnsActionableError(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        // Drop the table to simulate missing schema.
        // Each FunctionalTestCase gets its own database, so no cleanup needed.
        $connection->executeStatement('DROP TABLE IF EXISTS tx_mcpserver_upload_tokens');

        $siteInformationService = $this->createMock(SiteInformationService::class);
        $siteInformationService->method('getBaseUrl')->willReturn('https://example.com');

        $tool = new GetUploadCredentialsTool($siteInformationService);

        $result = $tool->execute([
            'folder' => '1:/',
            'filename' => 'test.jpg',
        ]);

        $this->assertTrue($result->isError);
        $this->assertStringContainsString('tx_mcpserver_upload_tokens', $result->content[0]->text);
        $this->assertStringContainsString('Database Analyzer', $result->content[0]->text);
    }
}
