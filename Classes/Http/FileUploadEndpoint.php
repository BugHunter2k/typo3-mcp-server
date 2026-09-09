<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Hn\McpServer\Service\BackendUserContextService;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\SiteInformationService;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Resource\Index\Indexer;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException;
use TYPO3\CMS\Core\Resource\Exception\InsufficientFolderWritePermissionsException;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Target of the pre-signed upload URLs handed out by the UploadFile MCP tool.
 *
 * Accepts the raw file bytes as PUT/POST body (curl -T style) or a multipart
 * form upload, authenticated by a single-use token bound to a backend user
 * and a target folder. This is the "out-of-band upload" pattern for MCP:
 * binary data never travels through the model's context - the client's
 * harness uploads directly and gets the created sys_file back as JSON.
 */
class FileUploadEndpoint
{
    use CorsHeadersTrait;

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($request->getMethod() === 'OPTIONS') {
                return $this->handlePreflightRequest($request);
            }
            if (!in_array($request->getMethod(), ['PUT', 'POST'], true)) {
                return $this->jsonError('Use HTTP PUT (or POST) with the raw file bytes as request body.', 405, $request);
            }

            $uploadService = GeneralUtility::makeInstance(FileUploadService::class);
            // Preferred: Authorization header (query strings end up in server
            // logs); the ?token= query parameter is kept as fallback.
            $token = '';
            // Apache frequently strips Authorization before it reaches PHP —
            // mod_php and especially FastCGI/mod_fcgid. The .htaccess shipped
            // with this extension restores it as an environment variable, so
            // the server params are checked when the PSR-7 header is empty.
            $authHeader = $request->getHeaderLine('Authorization');
            if ($authHeader === '') {
                $serverParams = $request->getServerParams();
                $authHeader = (string)($serverParams['HTTP_AUTHORIZATION']
                    ?? $serverParams['REDIRECT_HTTP_AUTHORIZATION']
                    ?? '');
            }
            if (preg_match('/^Bearer\s+(\S+)$/i', $authHeader, $matches)) {
                $token = $matches[1];
            }
            $token = $token !== '' ? $token : (string)($request->getQueryParams()['token'] ?? '');
            $tokenRow = $uploadService->consumeUploadToken($token);
            if ($tokenRow === null) {
                return $this->jsonError('Invalid, expired, or already used upload token.', 401, $request);
            }

            $this->setupBackendUserContext((int)$tokenRow['be_user_uid']);
            GeneralUtility::makeInstance(SiteInformationService::class)->setCurrentRequest($request);

            // FAL operations expect a backend request: the application type
            // decides which TCA/driver configuration applies, and core
            // services reach for the global request rather than a passed one.
            $request = $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
            $GLOBALS['TYPO3_REQUEST'] = $request;

            // Disable deferred image processing. DeferredBackendImageProcessor
            // requires a full backend session with CSRF tokens, which this
            // endpoint does not have — it authenticates with a single-use
            // upload token. Without this, generating the preview below fails.
            GeneralUtility::makeInstance(Context::class)
                ->setAspect('fileProcessing', new FileProcessingAspect(false));

            [$body, $uploadedFileName] = $this->extractUpload($request);

            // A file name fixed in the token wins: the token authorizes exactly
            // that upload, the request must not widen it to another file type.
            $fileName = (string)$tokenRow['file_name']
                ?: trim((string)($request->getQueryParams()['fileName'] ?? ''))
                ?: ($uploadedFileName ?? '')
                ?: ($uploadService->extractFileNameFromContentDisposition($request->getHeaderLine('Content-Disposition')) ?? '');
            if ($fileName === '') {
                return $this->jsonError(
                    'No file name given. Pass it as ?fileName=<name.ext> query parameter or Content-Disposition header.',
                    400,
                    $request
                );
            }

            // Before buffering or touching the storage: a rejected name should
            // cost neither disk space nor a freshly created folder.
            $uploadService->assertFileNameIsAllowed($fileName);

            $tempPath = $this->bufferToTempFile($body, $uploadService->getMaxFileBytes());
            try {
                $folder = $uploadService->resolveTargetFolder((string)$tokenRow['target_folder']);
                $stored = $uploadService->storeFile($tempPath, $fileName, $folder);
            } finally {
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }

            // Extract width/height into sys_file_metadata and warm the image
            // preview. Both are enrichment, not part of storing the file, so a
            // failing extractor must not turn a successful upload into an
            // error — without them the backend shows a file with no dimensions.
            try {
                $indexer = GeneralUtility::makeInstance(Indexer::class, $stored['file']->getStorage());
                $indexer->extractMetaData($stored['file']);

                if (str_starts_with((string)$stored['file']->getMimeType(), 'image/')) {
                    $stored['file']->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, []);
                }
            } catch (\Exception) {
                // Enrichment only.
            }

            $data = $uploadService->describeFile($stored['file']);
            if ($stored['deduplicated']) {
                $data['deduplicated'] = true;
                $data['note'] = 'A file with identical content already existed in this storage (see identifier, '
                    . 'possibly in a different folder than requested); it is returned instead of creating a duplicate.';
            } elseif ($stored['file']->getName() !== basename($fileName)) {
                $data['renamedFrom'] = basename($fileName);
            }

            return $this->addCorsHeaders(new JsonResponse($data, 201), $request);
        } catch (InsufficientFolderAccessPermissionsException | InsufficientFolderWritePermissionsException $e) {
            return $this->jsonError('No permission for the target folder: ' . $e->getMessage(), 403, $request);
        } catch (\InvalidArgumentException $e) {
            return $this->jsonError($e->getMessage(), 400, $request);
        } catch (\Throwable $e) {
            // Log the details, but do not leak exception messages (paths etc.)
            GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)
                ->error('Pre-signed upload failed: ' . $e->getMessage(), ['exception' => $e]);
            return $this->jsonError('Upload failed due to an unexpected server error (see TYPO3 log).', 500, $request);
        }
    }

    /**
     * Get the upload source: a multipart file upload when present (browser
     * forms, curl -F), otherwise the raw request body (curl -T / PUT).
     *
     * @return array{0: StreamInterface, 1: string|null} stream and optional client file name
     */
    protected function extractUpload(ServerRequestInterface $request): array
    {
        $uploadedFiles = $request->getUploadedFiles();
        if (!empty($uploadedFiles)) {
            $first = reset($uploadedFiles);
            if (is_array($first)) {
                $first = reset($first);
            }
            if ($first instanceof \Psr\Http\Message\UploadedFileInterface) {
                $clientName = $first->getClientFilename();
                return [$first->getStream(), $clientName !== null ? basename($clientName) : null];
            }
        }
        return [$request->getBody(), null];
    }

    /**
     * Stream the upload into a temp file, enforcing the size limit.
     */
    protected function bufferToTempFile(StreamInterface $body, int $maxBytes): string
    {
        $tempPath = GeneralUtility::tempnam('mcp_upload_');
        $handle = fopen($tempPath, 'wb');
        $bytes = 0;
        while (!$body->eof()) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                @unlink($tempPath);
                throw new \InvalidArgumentException(
                    'The file exceeds the maximum size of ' . round($maxBytes / 1048576) . ' MiB '
                    . '(configurable via the extension setting "maxFileSizeMb").'
                );
            }
            fwrite($handle, $chunk);
        }
        fclose($handle);

        if ($bytes === 0) {
            @unlink($tempPath);
            throw new \InvalidArgumentException('The request body was empty - send the raw file bytes as PUT/POST body.');
        }
        return $tempPath;
    }

    protected function jsonError(string $message, int $status, ServerRequestInterface $request): ResponseInterface
    {
        return $this->addCorsHeaders(new JsonResponse(['error' => $message], $status), $request);
    }

    /**
     * Set up the TYPO3 backend user context for the token's user.
     */
    protected function setupBackendUserContext(int $userId): void
    {
        try {
            GeneralUtility::makeInstance(BackendUserContextService::class)->impersonate($userId);
        } catch (\InvalidArgumentException) {
            // Do not reveal whether the user exists, is disabled, or expired
            throw new \InvalidArgumentException('The backend user this upload token belongs to is not available.');
        }
    }
}
