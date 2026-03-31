<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Enables record-specific workspace stage/publish buttons in the split preview.
 *
 * When the split preview is opened for a record-based detail page (e.g. news),
 * the Core stage buttons only find records on the preview page — not the actual
 * record living on a different storage folder.
 *
 * This middleware detects record preview parameters (auto-discovered from
 * TCEMAIN.preview configuration), looks up the workspace version, and injects
 * a data attribute + JS module that renders record-specific stage buttons.
 */
class WorkspaceRecordPreviewMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $request->getAttribute('route');
        $isPreviewRoute = ($route && $route->getPath() === '/workspace/preview-control')
            || str_contains($request->getUri()->getPath(), '/workspace/preview-control');

        if (!$isPreviewRoute) {
            return $handler->handle($request);
        }

        $queryParams = $request->getQueryParams();
        $pageId = (int)($queryParams['id'] ?? 0);

        if ($pageId === 0) {
            return $handler->handle($request);
        }

        $recordInfo = $this->findPreviewedRecord($queryParams, $pageId);

        if ($recordInfo === null) {
            return $handler->handle($request);
        }

        // Register JS module and pass record data via TYPO3's inline settings.
        // Both calls go through PageRenderer BEFORE the PreviewController renders,
        // so the module is in the import map and the data is in TYPO3.settings.
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->loadJavaScriptModule('@hn/mcp-server/workspace-record-preview.js');
        $pageRenderer->addInlineSettingArray('WorkspaceRecordPreview', $recordInfo);

        return $handler->handle($request);
    }

    /**
     * Auto-detect the previewed record by matching query parameters against
     * TCEMAIN.preview.fieldToParameterMap configurations.
     */
    private function findPreviewedRecord(array $queryParams, int $pageId): ?array
    {
        $pageTsConfig = BackendUtility::getPagesTSconfig($pageId);
        $previewConfigs = $pageTsConfig['TCEMAIN.']['preview.'] ?? [];

        foreach ($previewConfigs as $tableKey => $config) {
            if (!is_array($config) || !str_ends_with($tableKey, '.')) {
                continue;
            }

            $table = rtrim($tableKey, '.');
            $fieldMap = $config['fieldToParameterMap.'] ?? [];

            // Find the UID parameter name for this table
            $uidParamName = $fieldMap['uid'] ?? null;
            if ($uidParamName === null) {
                continue;
            }

            // Resolve the nested GET parameter (e.g. tx_news_pi1[news_preview])
            $uid = $this->resolveNestedParameter($queryParams, $uidParamName);
            if ($uid === null) {
                continue;
            }

            $versionRecord = BackendUtility::getWorkspaceVersionOfRecord(
                $GLOBALS['BE_USER']->workspace,
                $table,
                $uid,
                'uid,t3ver_oid,t3ver_state,t3ver_stage'
            );

            if (!is_array($versionRecord)) {
                continue;
            }

            $isNew = (int)$versionRecord['t3ver_state'] === 1;
            $versionUid = (int)$versionRecord['uid'];

            return [
                'table' => $table,
                'liveUid' => $isNew ? $versionUid : (int)$versionRecord['t3ver_oid'],
                'versionUid' => $versionUid,
                'isNew' => $isNew,
                'currentStage' => (int)($versionRecord['t3ver_stage'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * Resolve a bracket-notation GET parameter like tx_news_pi1[news_preview]
     * from the parsed query params array.
     */
    private function resolveNestedParameter(array $params, string $parameterName): ?int
    {
        // Parse "tx_news_pi1[news_preview]" into ["tx_news_pi1", "news_preview"]
        $parameterName = str_replace(']', '', $parameterName);
        $keys = explode('[', $parameterName);

        $current = $params;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        return is_numeric($current) ? (int)$current : null;
    }
}
