<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Workspaces\Event\RetrievedPreviewUrlEvent;

/**
 * Redirects workspace preview for record-based detail pages to the split
 * preview with stage buttons, instead of opening a plain frontend URL.
 *
 * Auto-detects tables that have TCEMAIN.preview configuration with a
 * previewPageId — no hardcoded table list needed.
 */
#[AsEventListener('hn-mcp-server/workspace-record-preview')]
final class WorkspaceRecordPreviewListener
{
    public function __construct(
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function __invoke(RetrievedPreviewUrlEvent $event): void
    {
        $table = $event->getTableName();

        // Pages already use the split preview natively
        if ($table === 'pages') {
            return;
        }

        $uid = $event->getUid();
        $contextData = $event->getContextData();
        $versionRecord = $contextData['versionRecord'] ?? BackendUtility::getRecord($table, $uid);
        $liveRecord = $contextData['liveRecord'] ?? BackendUtility::getLiveVersionOfRecord($table, $uid);

        if (!is_array($versionRecord) || !is_array($liveRecord)) {
            return;
        }

        $previewPageId = (int)(empty($versionRecord['pid']) ? $liveRecord['pid'] : $versionRecord['pid']);
        $pageTsConfig = BackendUtility::getPagesTSconfig($previewPageId);
        $previewConfig = $pageTsConfig['TCEMAIN.']['preview.'][$table . '.'] ?? [];

        if (empty($previewConfig['previewPageId'])) {
            return;
        }

        $detailPageId = (int)$previewConfig['previewPageId'];

        // Build the same GET parameters that TCEMAIN.preview would generate
        $additionalParams = [];
        if (isset($previewConfig['fieldToParameterMap.'])) {
            foreach ($previewConfig['fieldToParameterMap.'] as $field => $parameterName) {
                $value = $versionRecord[$field] ?? '';
                if ($field === 'uid') {
                    $value = ($versionRecord['t3ver_oid'] ?? 0) === 0
                        ? $versionRecord['uid']
                        : $versionRecord['t3ver_oid'];
                }
                $additionalParams[$parameterName] = $value;
            }
        }
        if (isset($previewConfig['additionalGetParameters.'])) {
            $additionalParams = array_replace(
                $additionalParams,
                GeneralUtility::removeDotsFromTS($previewConfig['additionalGetParameters.'])
            );
        }

        $splitPreviewUri = $this->uriBuilder->buildUriFromRoute(
            'workspace_previewcontrols',
            array_merge(['id' => $detailPageId], $additionalParams),
            UriBuilder::ABSOLUTE_URL
        );

        $event->setPreviewUri(new Uri((string)$splitPreviewUri));
    }
}
