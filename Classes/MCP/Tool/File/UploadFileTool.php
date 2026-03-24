<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for uploading files to a TYPO3 file storage
 */
class UploadFileTool extends AbstractRecordTool
{
    private const DEFAULT_MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB

    /**
     * Get the tool schema
     */
    public function getSchema(): array
    {
        return [
            'description' => 'Upload a file to TYPO3 FAL via Base64-encoded data. Best for small files only (< 1 MB). '
                . 'PREFERRED ALTERNATIVES: For files accessible via URL, use ImportFileFromUrl (no encoding overhead). '
                . 'For local files, use GetUploadCredentials + curl for direct HTTP upload — faster, no Base64 overhead, supports up to 50 MB.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'folder' => [
                        'type' => 'string',
                        'description' => 'Target folder as combined identifier (e.g. "1:/user_upload/")',
                    ],
                    'filename' => [
                        'type' => 'string',
                        'description' => 'Target filename (will be sanitized by TYPO3)',
                    ],
                    'fileData' => [
                        'type' => 'string',
                        'description' => 'Base64-encoded file content',
                    ],
                    'conflictMode' => [
                        'type' => 'string',
                        'description' => 'How to handle existing files: "rename" (default), "cancel", or "replace"',
                        'enum' => ['rename', 'cancel', 'replace'],
                    ],
                ],
                'required' => ['folder', 'filename', 'fileData'],
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
        $fileData = (string)$params['fileData'];
        $conflictMode = (string)($params['conflictMode'] ?? 'rename');

        $decodedData = base64_decode($fileData, true);
        if ($decodedData === false || $decodedData === '') {
            return $this->createErrorResult('Invalid or empty Base64 file data.');
        }

        $fileSize = strlen($decodedData);
        if ($fileSize > self::DEFAULT_MAX_FILE_SIZE) {
            return $this->createErrorResult(sprintf(
                'File too large: %s (max: %s).',
                $this->formatFileSize($fileSize),
                $this->formatFileSize(self::DEFAULT_MAX_FILE_SIZE)
            ));
        }

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $folder = $resourceFactory->getFolderObjectFromCombinedIdentifier($folderIdentifier);

        if (!$folder->checkActionPermission('write')) {
            return $this->createErrorResult(sprintf('No write permission for folder "%s".', $folderIdentifier));
        }

        $storage = $folder->getStorage();
        $sanitizedFilename = $storage->sanitizeFileName($filename);

        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        try {
            file_put_contents($tempPath, $decodedData);

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
        $lines[] = 'FILE UPLOADED';
        $lines[] = '=============';
        $lines[] = '';
        $lines[] = sprintf('UID: %d', $file->getUid());
        $lines[] = sprintf('Name: %s', $file->getName());
        $lines[] = sprintf('Size: %s', $this->formatFileSize($file->getSize()));
        $lines[] = sprintf('MIME: %s', $file->getMimeType());
        $lines[] = sprintf('Path: %d:%s', $storage->getUid(), $file->getIdentifier());
        $lines[] = sprintf('URL: %s', $publicUrl);

        return $this->createSuccessResult(implode("\n", $lines));
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
