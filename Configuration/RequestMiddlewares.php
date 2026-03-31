<?php

return [
    'frontend' => [
        'hn-mcp-server/routes' => [
            'target' => \Hn\McpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    'backend' => [
        'hn-mcp-server/routes' => [
            'target' => \Hn\McpServer\Middleware\McpServerMiddleware::class,
            'before' => [
                'typo3/cms-backend/authentication',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
        'hn-mcp-server/workspace-record-preview' => [
            'target' => \Hn\McpServer\Middleware\WorkspaceRecordPreviewMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];