<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Http;

use Hn\McpServer\Http\FileUploadEndpoint;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

class FileUploadEndpointTest extends FunctionalTestCase
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

        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file_storage.csv');

        $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->create('default');

        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['BE_USER'] = $backendUser;

        // Create storage base path
        @mkdir($this->instancePath . '/fileadmin', 0777, true);
    }

    /**
     * Create a valid token in the database and return the plaintext token
     */
    private function createValidToken(string $folder = '1:/', string $filename = 'test.jpg', int $maxSize = 52428800): string
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $now = time();
        $connection->insert('tx_mcpserver_upload_tokens', [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'token_hash' => $tokenHash,
            'folder' => $folder,
            'filename' => $filename,
            'max_size' => $maxSize,
            'be_user_uid' => 1,
            'expires_at' => $now + 300, // 5 minutes
            'used' => 0,
        ]);

        return $token;
    }

    /**
     * Create a mock uploaded file
     */
    private function createUploadedFile(string $content, string $filename = 'test.jpg'): UploadedFileInterface
    {
        $tempFile = GeneralUtility::tempnam('test_upload_');
        file_put_contents($tempFile, $content);

        return new UploadedFile(
            $tempFile,
            strlen($content),
            UPLOAD_ERR_OK,
            $filename,
            'image/jpeg'
        );
    }

    /**
     * Create a request with Bearer token and uploaded file
     */
    private function createUploadRequest(string $token, ?UploadedFileInterface $file = null): ServerRequest
    {
        $request = new ServerRequest('https://example.com/mcp/upload', 'POST');
        $request = $request->withHeader('Authorization', 'Bearer ' . $token);

        if ($file !== null) {
            $request = $request->withUploadedFiles(['file' => $file]);
        }

        return $request;
    }

    public function testValidUploadSucceeds(): void
    {
        $token = $this->createValidToken('1:/', 'test-upload.txt');
        $file = $this->createUploadedFile('Hello World', 'test-upload.txt');
        $request = $this->createUploadRequest($token, $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('file', $body);
        $this->assertSame('test-upload.txt', $body['file']['name']);
        $this->assertArrayHasKey('uid', $body['file']);
        $this->assertArrayHasKey('url', $body['file']);

        // Verify file exists on disk
        $this->assertFileExists($this->instancePath . '/fileadmin/test-upload.txt');
    }

    public function testExpiredTokenReturns401(): void
    {
        // Create an expired token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $pastTime = time() - 3600; // 1 hour ago
        $connection->insert('tx_mcpserver_upload_tokens', [
            'pid' => 0,
            'tstamp' => $pastTime,
            'crdate' => $pastTime,
            'token_hash' => $tokenHash,
            'folder' => '1:/',
            'filename' => 'test.jpg',
            'max_size' => 52428800,
            'be_user_uid' => 1,
            'expires_at' => $pastTime, // Already expired
            'used' => 0,
        ]);

        $file = $this->createUploadedFile('test content');
        $request = $this->createUploadRequest($token, $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertStringContainsString('expired', strtolower($body['error']));
    }

    public function testAlreadyUsedTokenReturns401(): void
    {
        // Create a used token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $now = time();
        $connection->insert('tx_mcpserver_upload_tokens', [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'token_hash' => $tokenHash,
            'folder' => '1:/',
            'filename' => 'test.jpg',
            'max_size' => 52428800,
            'be_user_uid' => 1,
            'expires_at' => $now + 300,
            'used' => 1, // Already used!
        ]);

        $file = $this->createUploadedFile('test content');
        $request = $this->createUploadRequest($token, $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testInvalidTokenReturns401(): void
    {
        $file = $this->createUploadedFile('test content');
        $request = $this->createUploadRequest('invalid_token_12345', $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $request = new ServerRequest('https://example.com/mcp/upload', 'POST');
        $file = $this->createUploadedFile('test content');
        $request = $request->withUploadedFiles(['file' => $file]);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertStringContainsString('Authorization', $body['error']);
    }

    public function testFileTooLargeReturns413(): void
    {
        // Create token with small max_size
        $token = $this->createValidToken('1:/', 'test.txt', 10); // Only 10 bytes allowed

        // Create file larger than allowed
        $file = $this->createUploadedFile('This content is definitely more than 10 bytes');
        $request = $this->createUploadRequest($token, $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(413, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertStringContainsString('too large', strtolower($body['error']));
    }

    public function testFileAlreadyExistsReturns409(): void
    {
        // Pre-create a file
        file_put_contents($this->instancePath . '/fileadmin/existing.txt', 'original content');

        $token = $this->createValidToken('1:/', 'existing.txt');
        $file = $this->createUploadedFile('new content', 'existing.txt');
        $request = $this->createUploadRequest($token, $file);

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertStringContainsString('already exists', strtolower($body['error']));

        // Original file should be unchanged
        $this->assertSame('original content', file_get_contents($this->instancePath . '/fileadmin/existing.txt'));
    }

    public function testMissingFileFieldReturns400(): void
    {
        $token = $this->createValidToken();
        $request = $this->createUploadRequest($token, null); // No file

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody()->getContents(), true);
        $this->assertStringContainsString('file', strtolower($body['error']));
    }

    public function testTokenCanOnlyBeUsedOnce(): void
    {
        $token = $this->createValidToken('1:/', 'test1.txt');

        // First upload should succeed
        $file1 = $this->createUploadedFile('content 1', 'test1.txt');
        $request1 = $this->createUploadRequest($token, $file1);

        $endpoint = new FileUploadEndpoint();
        $response1 = $endpoint($request1);
        $this->assertSame(200, $response1->getStatusCode());

        // Second upload with same token should fail
        // Need a new token for a different filename since the first one is used
        $file2 = $this->createUploadedFile('content 2', 'test2.txt');
        $request2 = $this->createUploadRequest($token, $file2);

        $response2 = $endpoint($request2);
        $this->assertSame(401, $response2->getStatusCode());
    }

    public function testOptionsRequestReturnsCorHeaders(): void
    {
        $request = (new ServerRequest('https://example.com/mcp/upload', 'OPTIONS'))
            ->withHeader('Origin', 'https://client.example.com');

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
        $this->assertTrue($response->hasHeader('Access-Control-Allow-Methods'));
    }

    public function testGetMethodReturns405(): void
    {
        $request = new ServerRequest('https://example.com/mcp/upload', 'GET');

        $endpoint = new FileUploadEndpoint();
        $response = $endpoint($request);

        $this->assertSame(405, $response->getStatusCode());
    }
}
