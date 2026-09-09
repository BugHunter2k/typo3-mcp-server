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
 *
 * The "redirect" option is not optional for such a route. RouteRedirect::resolve()
 * refuses any non-module route without "enable => true", and it discards *every*
 * parameter unless "parameters" lists it — silently, which would leave the resume
 * controller without the authorization it is supposed to resume.
 */
return [
    OAuthResumeController::ROUTE_NAME => [
        'path' => '/mcp-oauth/resume',
        'target' => OAuthResumeController::class . '::resumeAction',
        'redirect' => [
            'enable' => true,
            'parameters' => [
                'client_id' => true,
                'client_name' => true,
                'redirect_uri' => true,
                'code_challenge' => true,
                'code_challenge_method' => true,
                'state' => true,
            ],
        ],
    ],
];
