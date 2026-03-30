<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Hn\McpServer\MCP\Tool\RequestAwareToolInterface;
use Hn\McpServer\Service\BaseUrlService;
use Mcp\Types\CallToolResult;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for generating one-time upload credentials for direct HTTP uploads
 *
 * This tool implements the pre-signed URL pattern (like AWS S3) to enable
 * large file uploads from Claude Code without Base64 encoding overhead.
 * The token is stored as SHA-256 hash in the database for security.
 */
class GetUploadCredentialsTool extends AbstractRecordTool implements RequestAwareToolInterface
{
    private const DEFAULT_MAX_SIZE = 52428800; // 50 MB
    private const TOKEN_EXPIRY_SECONDS = 300; // 5 minutes

    private ?ServerRequestInterface $request = null;

    public function __construct(
        private readonly BaseUrlService $baseUrlService,
    ) {
        parent::__construct();
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Generate one-time upload credentials for direct HTTP file upload. '
                . 'Returns a URL and token for use with curl. Use this for files > 2MB to avoid Base64 overhead. '
                . 'The token expires after 5 minutes and can only be used once.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Target folder as combined identifier (e.g. "1:/user_upload/")',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Target filename (will be sanitized by TYPO3). Must not contain / or \\',
                    ],
                    'maxSize' => [
                        'type' => 'integer',
                        'description' => 'Maximum file size in bytes (default: 52428800 = 50MB)',
                    ],
                ],
                'required' => ['folder', 'filename'],
            ],
            'annotations' => [
                'readOnlyHint' => false,
                'idempotentHint' => false,
            ],
        ];
    }

    /**
     * Execute the tool logic
     */
    protected function doExecute(array $params): CallToolResult
    {
        $folderIdentifier = (string)$params['folder'];
        $filename = (string)$params['filename'];
        $maxSize = (int)($params['maxSize'] ?? self::DEFAULT_MAX_SIZE);

        // Validate filename does not contain path separators (D8: early rejection)
        if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            return $this->createErrorResult('Filename must not contain path separators (/ or \\).');
        }

        // Validate folder exists and get storage
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        try {
            $folder = $resourceFactory->getFolderObjectFromCombinedIdentifier($folderIdentifier);
        } catch (\Exception $e) {
            return $this->createErrorResult(sprintf('Invalid folder: %s', $e->getMessage()));
        }

        // Check write permission
        if (!$folder->checkActionPermission('write')) {
            return $this->createErrorResult(sprintf('No write permission for folder "%s".', $folderIdentifier));
        }

        // Sanitize filename (D8: at token creation time)
        $storage = $folder->getStorage();
        $sanitizedFilename = $storage->sanitizeFileName($filename);

        // Generate cryptographic token (64 chars hex)
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        // Resolve base URL via service (request-aware, with site validation)
        if ($this->request !== null) {
            $baseUrl = $this->baseUrlService->getBaseUrl($this->request);
        } else {
            $baseUrl = $this->baseUrlService->getBaseUrlFromSiteConfiguration();
        }

        // Verify upload tokens table exists (created by ext_tables.sql)
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        if (!$connection->createSchemaManager()->tablesExist(['tx_mcpserver_upload_tokens'])) {
            return $this->createErrorResult(
                'Required database table "tx_mcpserver_upload_tokens" does not exist. '
                . 'A TYPO3 backend admin must run the Database Analyzer in the Install Tool '
                . '(Admin Tools → Maintenance → Analyze Database Structure) to create it.'
            );
        }

        // Lazy cleanup: Delete expired tokens (D6: only expired, not used)
        $this->cleanupExpiredTokens();

        $now = time();
        $connection->insert('tx_mcpserver_upload_tokens', [
            'pid' => 0,
            'tstamp' => $now,
            'crdate' => $now,
            'token_hash' => $tokenHash,
            'folder' => $folderIdentifier,
            'filename' => $sanitizedFilename,
            'max_size' => $maxSize,
            'be_user_uid' => (int)($GLOBALS['BE_USER']->user['uid'] ?? 0),
            'expires_at' => $now + self::TOKEN_EXPIRY_SECONDS,
            'used' => 0,
        ]);

        $uploadUrl = $baseUrl . '/mcp/upload';

        // Build response with curl usage example
        $lines = [];
        $lines[] = 'UPLOAD CREDENTIALS GENERATED';
        $lines[] = '============================';
        $lines[] = '';
        $lines[] = sprintf('Upload URL: %s', $uploadUrl);
        $lines[] = sprintf('Token: %s', $token);
        $lines[] = sprintf('Expires in: %d seconds', self::TOKEN_EXPIRY_SECONDS);
        $lines[] = sprintf('Max size: %s', $this->formatFileSize($maxSize));
        $lines[] = sprintf('Target: %s%s', rtrim($folderIdentifier, '/') . '/', $sanitizedFilename);
        $lines[] = '';
        $lines[] = 'Usage (run in Bash):';
        $lines[] = sprintf("curl -X POST '%s' \\", $uploadUrl);
        $lines[] = sprintf("  -H 'Authorization: Bearer %s' \\", $token);
        $lines[] = "  -F 'file=@/path/to/local/file'";
        $lines[] = '';
        $lines[] = 'Note: Replace /path/to/local/file with the actual file path.';
        $lines[] = 'The token can only be used once and expires after 5 minutes.';

        return $this->createSuccessResult(implode("\n", $lines));
    }

    /**
     * Delete expired tokens to prevent table bloat
     */
    private function cleanupExpiredTokens(): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_mcpserver_upload_tokens');

        $connection->executeStatement(
            'DELETE FROM tx_mcpserver_upload_tokens WHERE expires_at < ?',
            [time()]
        );
    }

    /**
     * Format file size to human-readable string
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

}
