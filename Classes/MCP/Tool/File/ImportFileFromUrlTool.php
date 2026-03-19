<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for importing files from a URL into a TYPO3 file storage
 */
class ImportFileFromUrlTool extends AbstractRecordTool
{
    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Import a file from a public URL into TYPO3 FAL. TYPO3 downloads the file server-side — no Base64 encoding needed. '
                . 'Use this for files accessible via HTTP(S).',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'url' => [
                        'type' => 'string',
                        'description' => 'The URL to download the file from',
                    ],
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Target folder as combined identifier (e.g. "1:/user_upload/")',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Target filename (optional — derived from URL if omitted)',
                    ],
                    'conflictMode' => [
                        'type' => 'string',
                        'description' => 'How to handle existing files: "rename" (default), "cancel", or "replace"',
                        'enum' => ['rename', 'cancel', 'replace'],
                    ],
                ],
                'required' => ['url', 'folder'],
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
        $url = (string)$params['url'];
        $folderIdentifier = (string)$params['folder'];
        $filename = (string)($params['filename'] ?? '');
        $conflictMode = (string)($params['conflictMode'] ?? 'rename');

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return $this->createErrorResult(sprintf('Only http:// and https:// URLs are allowed, got "%s://".', $scheme));
        }

        if ($filename === '') {
            $filename = $this->deriveFilenameFromUrl($url);
        }

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $folder = $resourceFactory->getFolderObjectFromCombinedIdentifier($folderIdentifier);

        if (!$folder->checkActionPermission('write')) {
            return $this->createErrorResult(sprintf('No write permission for folder "%s".', $folderIdentifier));
        }

        $storage = $folder->getStorage();
        $sanitizedFilename = $storage->sanitizeFileName($filename);

        // Download file to temp location
        $requestFactory = GeneralUtility::makeInstance(RequestFactory::class);
        $response = $requestFactory->request($url, 'GET', [
            'headers' => ['User-Agent' => 'TYPO3 MCP Server'],
            'allow_redirects' => true,
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() !== 200) {
            return $this->createErrorResult(sprintf(
                'Failed to download from URL: HTTP %d',
                $response->getStatusCode()
            ));
        }

        $tempPath = GeneralUtility::tempnam('mcp_import_');
        try {
            file_put_contents($tempPath, $response->getBody()->getContents());

            $duplicationBehavior = match ($conflictMode) {
                'cancel' => DuplicationBehavior::CANCEL,
                'replace' => DuplicationBehavior::REPLACE,
                default => DuplicationBehavior::RENAME,
            };

            $file = $storage->addFile($tempPath, $folder, $sanitizedFilename, $duplicationBehavior);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        // Extract metadata (width/height) after file is safely in FAL.
        // Non-critical enrichment — extractor failures must not abort the response.
        try {
            $indexer = GeneralUtility::makeInstance(Indexer::class, $storage);
            $indexer->extractMetaData($file);
        } catch (\Exception) {
            // Metadata extraction is non-critical
        }

        $publicUrl = $file->getPublicUrl() ?? '(not public)';

        $lines = [];
        $lines[] = 'FILE IMPORTED';
        $lines[] = '=============';
        $lines[] = '';
        $lines[] = sprintf('Source: %s', $url);
        $lines[] = sprintf('UID: %d', $file->getUid());
        $lines[] = sprintf('Name: %s', $file->getName());
        $lines[] = sprintf('Size: %s', $this->formatFileSize($file->getSize()));
        $lines[] = sprintf('MIME: %s', $file->getMimeType());
        $lines[] = sprintf('Path: %d:%s', $storage->getUid(), $file->getIdentifier());
        $lines[] = sprintf('URL: %s', $publicUrl);

        return $this->createSuccessResult(implode("\n", $lines));
    }

    /**
     * Derive a filename from a URL
     */
    private function deriveFilenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === '' || $path === '/') {
            return 'imported-file';
        }

        $filename = basename($path);

        // Strip query string artifacts
        if (str_contains($filename, '?')) {
            $filename = substr($filename, 0, (int)strpos($filename, '?'));
        }

        return $filename !== '' ? $filename : 'imported-file';
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
