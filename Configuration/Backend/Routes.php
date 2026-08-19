<?php

declare(strict_types=1);

use Hn\McpServer\Controller\OAuthResumeController;

/**
 * Backend routes.
 *
 * "mcp_oauth_resume" exists so the backend login can send the user straight back
 * into the OAuth authorization flow instead of dropping them in the backend, which
 * forced them to open the authorization link a second time.
 *
 * It has to be a registered backend route: TYPO3 resolves the post-login target
 * through RouteRedirect, which accepts a route name only (never an arbitrary URL),
 * because an open redirector right behind the login form would be a phishing
 * vector. Deliberately no "module" option — BackendController::getStartupModule()
 * allows non-module routes as a redirect target.
 */
return [
    OAuthResumeController::ROUTE_NAME => [
        'path' => '/mcp-oauth/resume',
        'target' => OAuthResumeController::class . '::resumeAction',
    ],
];
