<?php

declare(strict_types=1);

namespace Hn\McpServer\Middleware;

use Hn\McpServer\Http\PendingAuthorizationCookie;
use Hn\McpServer\Http\RequestUrlTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Returns the user to the OAuth consent screen after the backend login.
 *
 * OAuthAuthorizeEndpoint parks the pending authorization in a cookie before handing an
 * unauthenticated user to the backend login (see PendingAuthorizationCookie); this picks it
 * up once the login succeeded, so the authorization link only has to be opened once.
 *
 * Replaces McpServerMiddleware::handleOAuthCookieContinuation(), which did the same thing
 * with two implicit assumptions. It keyed on the request path `/typo3/main`, so it depended
 * on that being where the login lands — a start module other than the main one, or an
 * `auth.BE.redirectToURL` TSconfig, and nothing happened. And it read the cookie from
 * McpServerMiddleware, whose position relative to `typo3/cms-backend/authentication` was
 * never declared, so whether a backend user was resolved yet came down to how the middleware
 * order happened to resolve. Both assumptions are now explicit: this middleware declares
 * `after: typo3/cms-backend/authentication` and keys on the login itself.
 */
final class OAuthLoginReturnMiddleware implements MiddlewareInterface
{
    use RequestUrlTrait;

    public function __construct(
        private readonly Context $context,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = GeneralUtility::makeInstance(PendingAuthorizationCookie::class);
        $pendingAuthorization = $cookie->read($request);

        if (!$this->shouldResume($request, $pendingAuthorization)) {
            return $handler->handle($request);
        }

        // Redirecting here rather than from a backend route means this happens before the
        // backend renders: there is no content frame to break out of, and the user goes
        // straight from the login to the consent screen. The cookie is dropped on the way
        // out, and the endpoint re-validates client_id and redirect_uri against the
        // registered client.
        $authorizeUrl = $this->getRequestSitePath($request)
            . '/mcp_oauth/authorize?' . http_build_query($pendingAuthorization);

        return (new RedirectResponse($authorizeUrl, 302))
            ->withHeader('Set-Cookie', $cookie->clear($request));
    }

    /**
     * May this request be redirected into the pending authorization?
     *
     * Deliberately narrow: only the request on which a login actually completes. A parked
     * authorization alone is not enough, because the cookie outlives an abandoned flow for
     * its full lifetime — resuming on any backend request would yank a user out of an
     * unrelated backend session they were already working in.
     *
     * The trade is asymmetric, which is why it is settled this way. If a login route ever
     * completes without announcing itself as one, this stays quiet and the user lands in the
     * backend — the behaviour before this middleware existed. The looser condition would
     * trade that for interrupting people mid-session, which is worse than not helping.
     *
     * @param array<string, string> $pendingAuthorization Empty when nothing is parked
     */
    private function shouldResume(ServerRequestInterface $request, array $pendingAuthorization): bool
    {
        return $pendingAuthorization !== []
            && $this->isBackendUserLoggedIn()
            && $this->isLoginAttempt($request);
    }

    /**
     * Is a backend user resolved on this request?
     */
    private function isBackendUserLoggedIn(): bool
    {
        return (bool)$this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', false);
    }

    /**
     * Did a login just complete on this request?
     *
     * TYPO3 announces a login attempt with "login_status=login". The username/password login
     * submits it as a form field, while a provider that authenticates outside TYPO3 carries
     * it in the query string of the callback URL it returns to. Both places are checked, so
     * neither kind of login is missed.
     */
    private function isLoginAttempt(ServerRequestInterface $request): bool
    {
        $body = $request->getParsedBody();
        $status = $request->getQueryParams()['login_status']
            ?? (is_array($body) ? ($body['login_status'] ?? null) : null);

        return $status === 'login';
    }
}
