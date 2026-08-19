<?php

declare(strict_types=1);

namespace Hn\McpServer\Controller;

use Hn\McpServer\Http\RequestUrlTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resumes an OAuth authorization that was interrupted by the backend login.
 *
 * OAuthAuthorizeEndpoint sends unauthenticated users to the backend login with
 * "redirect=mcp_oauth_resume" plus the pending authorization in "redirectParams".
 * TYPO3 opens this route afterwards and BackendController passes those parameters
 * on, so the authorization link only has to be opened once.
 */
class OAuthResumeController
{
    use RequestUrlTrait;

    /**
     * Backend route name, referenced by Configuration/Backend/Routes.php and by
     * OAuthAuthorizeEndpoint when it builds the login URL.
     */
    public const ROUTE_NAME = 'mcp_oauth_resume';

    /**
     * Parameters carried across the login round-trip. Named explicitly so the
     * backend's own route parameters (token, route) stay out of the authorization URL.
     */
    private const AUTHORIZATION_PARAMETERS = [
        'client_id',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'state',
    ];

    public function resumeAction(ServerRequestInterface $request): ResponseInterface
    {
        $parameters = $this->pendingAuthorization($request);

        // Nothing pending, so the route was opened on its own. Hand the user to the
        // backend they just logged into — the behaviour before this route existed.
        if ($parameters === []) {
            $uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);

            return new RedirectResponse((string)$uriBuilder->buildUriFromRoute('main'), 302);
        }

        return $this->createTopLevelRedirect(
            $this->getRequestSitePath($request) . '/mcp_oauth/authorize?' . http_build_query($parameters)
        );
    }

    /**
     * @return array<string, string> Empty when there is nothing to resume
     */
    private function pendingAuthorization(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();

        $parameters = [];
        foreach (self::AUTHORIZATION_PARAMETERS as $name) {
            $value = $queryParams[$name] ?? '';
            if (is_string($value) && $value !== '') {
                $parameters[$name] = $value;
            }
        }

        // Without a client there is no authorization to return to. The endpoint
        // re-validates client_id and redirect_uri against the registered client, so no
        // further checking belongs here.
        return isset($parameters['client_id']) ? $parameters : [];
    }

    /**
     * BackendController turns a redirect target into the backend's start module, so this
     * route renders inside the backend content frame. A plain 302 would navigate that
     * frame and show the consent screen embedded in the backend. Break out to the top
     * window instead, with a meta refresh and a manual link as fallbacks.
     */
    private function createTopLevelRedirect(string $url): ResponseInterface
    {
        $encodedUrl = htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $jsonUrl = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return new HtmlResponse(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Continuing authorization</title>
    <meta http-equiv="refresh" content="0;url={$encodedUrl}">
</head>
<body>
    <p>Continuing authorization &hellip; <a href="{$encodedUrl}">continue manually</a> if nothing happens.</p>
    <script>(window.top || window).location.replace({$jsonUrl});</script>
</body>
</html>
HTML);
    }
}
