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
        // Sends the user back into an OAuth authorization that the backend login
        // interrupted. Must run *after* authentication: it only acts once a backend user is
        // resolved, so running before it would never see a completed login. The previous
        // implementation lived in McpServerMiddleware, whose order relative to
        // authentication was never declared — and which this installation pins *before*
        // authentication for the MCP endpoints, so it could never have fired here.
        'hn-mcp-server/oauth-login-return' => [
            'target' => \Hn\McpServer\Middleware\OAuthLoginReturnMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];