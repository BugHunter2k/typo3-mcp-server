<?php

declare(strict_types=1);

namespace Hn\McpServer\MCP\Tool\File;

use Hn\McpServer\Event\FilePreviewEvent;
use Hn\McpServer\MCP\Tool\Record\AbstractRecordTool;
use Mcp\Types\CallToolResult;
use Mcp\Types\TextContent;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Tool for generating file previews (thumbnails) from TYPO3 FAL
 *
 * Returns a download URL to the /mcp/preview endpoint which serves the
 * thumbnail as a direct image response. MCP clients can download via curl.
 *
 * Built-in support for images (via TYPO3 ProcessedFile, locally cached).
 * Other file types (PDFs, Office docs, etc.) can be handled by PSR-14
 * event listeners via FilePreviewEvent.
 */
class PreviewFileTool extends AbstractRecordTool
{
    private const DEFAULT_WIDTH = 400;
    private const DEFAULT_HEIGHT = 400;
    private const MAX_WIDTH = 1200;
    private const MAX_HEIGHT = 1200;

    public function getSchema(): array
    {
        return [
            'description' => 'Generate a preview thumbnail for a file in TYPO3 FAL. '
                . 'Returns a download URL for the resized thumbnail (NOT the original file). '
                . 'The URL requires Bearer token authentication. '
                . 'Download with: curl -H "Authorization: Bearer $TOKEN" -o /tmp/preview.jpg "URL"',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'uid' => [
                        'type' => 'integer',
                        'description' => 'The sys_file UID of the file to preview',
                    ],
                    'width' => [
                        'type' => 'integer',
                        'description' => 'Preview width in pixels (default: 400, max: 1200)',
                    ],
                    'height' => [
                        'type' => 'integer',
                        'description' => 'Preview height in pixels (default: 400, max: 1200)',
                    ],
                ],
                'required' => ['uid'],
            ],
            'annotations' => [
                'readOnlyHint' => true,
                'idempotentHint' => true,
            ],
        ];
    }

    protected function doExecute(array $params): CallToolResult
    {
        $fileUid = (int)$params['uid'];
        $width = min((int)($params['width'] ?? self::DEFAULT_WIDTH), self::MAX_WIDTH);
        $height = min((int)($params['height'] ?? self::DEFAULT_HEIGHT), self::MAX_HEIGHT);

        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $file = $resourceFactory->getFileObject($fileUid);

        $mimeType = $file->getMimeType();
        $metadata = $this->buildMetadataText($file, $mimeType);

        // Built-in: image preview via proxy endpoint
        if ($this->isPreviewableImage($mimeType)) {
            $previewUrl = $this->buildPreviewUrl($fileUid, $width, $height);

            return new CallToolResult([
                new TextContent($metadata),
                new TextContent('Preview URL: ' . $previewUrl),
            ]);
        }

        // Delegate to PSR-14 event listeners for other file types (e.g. PDFs via imgix)
        $event = new FilePreviewEvent($file, $width, $height);
        $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
        $eventDispatcher->dispatch($event);

        if ($event->hasPreview()) {
            return new CallToolResult([
                new TextContent($metadata),
                $event->getPreview(),
            ]);
        }

        // No listener provided a preview
        $errorMessage = $event->getErrorMessage()
            ?? 'Preview not available for this file type (' . $mimeType . ').';

        return new CallToolResult([
            new TextContent($metadata),
            new TextContent($errorMessage),
        ]);
    }

    private function buildPreviewUrl(int $fileUid, int $width, int $height): string
    {
        $baseUrl = $this->resolveBaseUrl();

        return $baseUrl . '/mcp/preview?' . http_build_query([
            'uid' => $fileUid,
            'w' => $width,
            'h' => $height,
        ]);
    }

    private function resolveBaseUrl(): string
    {
        // Try HTTP request first (available when running via HTTP transport)
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request !== null) {
            $uri = $request->getUri();
            if ($uri->getHost() !== '' && $uri->getHost() !== 'localhost') {
                $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
                $port = $uri->getPort();
                if ($port !== null && $port !== 443 && $port !== 80) {
                    $baseUrl .= ':' . $port;
                }
                return $baseUrl;
            }
        }

        // Fallback for stdio transport: derive from TYPO3 site configuration
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);
        $sites = $siteFinder->getAllSites();
        foreach ($sites as $site) {
            $base = rtrim((string)$site->getBase(), '/');
            if (str_starts_with($base, 'http')) {
                return $base;
            }
        }

        return '';
    }

    private function isPreviewableImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/')
            && $mimeType !== 'image/svg+xml';
    }

    private function buildMetadataText(File $file, string $mimeType): string
    {
        $lines = [
            sprintf('uid:%d | %s | %s | %s', $file->getUid(), $file->getName(), $this->formatFileSize($file->getSize()), $mimeType),
            sprintf('Path: %d:%s', $file->getStorage()->getUid(), $file->getIdentifier()),
        ];

        if ($this->isPreviewableImage($mimeType)) {
            $properties = $file->getProperties();
            $imageWidth = $properties['width'] ?? 0;
            $imageHeight = $properties['height'] ?? 0;
            if ($imageWidth > 0 && $imageHeight > 0) {
                $lines[] = sprintf('Dimensions: %dx%d px', $imageWidth, $imageHeight);
            }
        }

        return implode("\n", $lines);
    }

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
