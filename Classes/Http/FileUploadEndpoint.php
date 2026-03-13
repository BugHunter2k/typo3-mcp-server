<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\McpAuthenticationException;
use Hn\McpServer\Service\McpAuthenticationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HTTP endpoint for multipart file uploads to TYPO3 FAL
 *
 * Accepts multipart/form-data with:
 *   - file: The uploaded file (binary)
 *   - folder: Target folder as combined identifier (e.g. "1:/user_upload/")
 *   - conflictMode: "rename" (default), "cancel", or "replace"
 *
 * Usage: curl -F "file=@image.jpg" -F "folder=1:/images/" -H "Authorization: Bearer <token>" https://example.com/mcp/upload
 */
class FileUploadEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        // Handle CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            return $this->handlePreflightRequest();
        }

        if ($request->getMethod() !== 'POST') {
            return $this->jsonResponse(['error' => 'Method not allowed'], 405);
        }

        // Authenticate
        try {
            $authService = GeneralUtility::makeInstance(McpAuthenticationService::class);
            $authService->authenticateRequest($request);
        } catch (McpAuthenticationException $exception) {
            return $this->unauthorizedResponse($exception->getMessage(), $request);
        }

        // Extract upload data
        $uploadedFiles = $request->getUploadedFiles();
        $body = $request->getParsedBody();

        $file = $uploadedFiles['file'] ?? null;
        if ($file === null || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->jsonResponse([
                'error' => 'No file uploaded or upload error',
                'hint' => 'Send file as multipart/form-data with field name "file"',
            ], 400);
        }

        $folderIdentifier = (string)($body['folder'] ?? '');
        if ($folderIdentifier === '') {
            return $this->jsonResponse([
                'error' => 'Missing "folder" parameter',
                'hint' => 'Specify target folder as combined identifier (e.g. "1:/user_upload/")',
            ], 400);
        }

        $conflictMode = (string)($body['conflictMode'] ?? 'rename');

        // Resolve target folder
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $folder = $resourceFactory->getFolderObjectFromCombinedIdentifier($folderIdentifier);

        if (!$folder->checkActionPermission('write')) {
            return $this->jsonResponse([
                'error' => sprintf('No write permission for folder "%s"', $folderIdentifier),
            ], 403);
        }

        $storage = $folder->getStorage();
        $filename = $file->getClientFilename() ?? 'uploaded-file';
        $sanitizedFilename = $storage->sanitizeFileName($filename);

        // Write uploaded file to temp location, then add to FAL
        $tempPath = GeneralUtility::tempnam('mcp_multipart_');
        try {
            $file->moveTo($tempPath);

            $duplicationBehavior = match ($conflictMode) {
                'cancel' => DuplicationBehavior::CANCEL,
                'replace' => DuplicationBehavior::REPLACE,
                default => DuplicationBehavior::RENAME,
            };

            $falFile = $storage->addFile($tempPath, $folder, $sanitizedFilename, $duplicationBehavior);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        $result = [
            'uid' => $falFile->getUid(),
            'name' => $falFile->getName(),
            'size' => $falFile->getSize(),
            'mimeType' => $falFile->getMimeType(),
            'path' => $storage->getUid() . ':' . $falFile->getIdentifier(),
            'publicUrl' => $falFile->getPublicUrl(),
        ];

        return $this->addCorsHeaders($this->jsonResponse($result, 201));
    }

    private function jsonResponse(array $data, int $status = 200): ResponseInterface
    {
        $stream = new Stream('php://temp', 'rw');
        $stream->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stream->rewind();

        return new Response($stream, $status, ['Content-Type' => 'application/json']);
    }

    private function unauthorizedResponse(string $message, ServerRequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() && $uri->getPort() !== 443 && $uri->getPort() !== 80) {
            $baseUrl .= ':' . $uri->getPort();
        }

        $response = $this->jsonResponse(['error' => 'Unauthorized', 'message' => $message], 401);
        $response = $response->withHeader(
            'WWW-Authenticate',
            'Bearer resource_metadata="' . $baseUrl . '/.well-known/oauth-protected-resource/mcp"'
        );

        return $this->addCorsHeaders($response);
    }
}
