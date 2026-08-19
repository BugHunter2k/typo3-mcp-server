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
                'typo3/cms-backend/site-resolver',
            ],
            'after' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
        // Sends the user back into an OAuth authorization that the backend login
        // interrupted. Must run *after* authentication: it only acts once a backend user is
        // resolved, so running before it would never see a completed login. The previous
        // implementation lived in McpServerMiddleware, whose order relative to
        // authentication was never declared.
        'hn-mcp-server/oauth-login-return' => [
            'target' => \Hn\McpServer\Middleware\OAuthLoginReturnMiddleware::class,
            'after' => [
                'typo3/cms-backend/authentication',
            ],
        ],
    ],
];