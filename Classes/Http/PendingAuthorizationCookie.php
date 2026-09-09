<?php

declare(strict_types=1);

namespace Hn\McpServer\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The pending OAuth authorization, parked in a cookie across the backend login.
 *
 * OAuthAuthorizeEndpoint sends unauthenticated users to the backend login and has to get
 * them back to the consent screen afterwards, otherwise the authorization link has to be
 * opened a second time. Carrying the parameters in the login URL does not work on its own,
 * because the query string does not survive every route through the login:
 *
 * - Switching the login provider on the login screen is a plain
 *   `<a href="?loginProvider=X">`. A relative URL with only a query replaces the *whole*
 *   query string.
 * - A login provider that authenticates outside TYPO3 leaves the site entirely and returns
 *   through a callback URL of its own making, which generally carries only the parameters
 *   that provider needs. (EXT:oauth2 is the common case: its
 *   `AbstractResourceServer::getRedirectUri()` assembles the identity provider's callback
 *   URL from a fixed set of four parameters.)
 *
 * A cookie rides on the browser rather than the URL and therefore survives both. It is read
 * back by OAuthLoginReturnMiddleware once the login completed.
 *
 * The cookie grants nothing on its own: it holds the same values the authorization link
 * already carries in plain sight, and OAuthAuthorizeEndpoint re-validates client_id and
 * redirect_uri against the registered client on the way back, no matter where the
 * parameters came from. So it needs no signature — forging it can at most put a logged-in
 * user in front of this installation's own consent screen, which is exactly what sending
 * them the authorization link does.
 */
final class PendingAuthorizationCookie
{
    use RequestUrlTrait;

    public const NAME = 'tx_mcpserver_oauth';

    /**
     * One login round-trip: long enough for a detour through an identity provider, a
     * password manager and a second factor, short enough that an abandoned flow expires on
     * its own.
     */
    private const LIFETIME_SECONDS = 600;

    /**
     * The parameters carried across the login, named explicitly in both directions.
     *
     * Writing: nothing else a client appended to the authorization URL is carried along.
     * Reading: the backend's own route parameters (token, route) can never reappear in the
     * authorization URL, not even from a hand-crafted cookie.
     *
     * ``client_name`` is part of the set because showConsentForm() falls back to it for the
     * well-known seeded client, whose registered name is a generic placeholder. Dropping it
     * would make that consent screen name the TYPO3 host instead of the client.
     */
    private const PARAMETERS = [
        'client_id',
        'client_name',
        'redirect_uri',
        'code_challenge',
        'code_challenge_method',
        'state',
    ];

    /**
     * Reduce an untrusted parameter bag to the non-empty string values we carry.
     *
     * @param array<mixed> $parameters
     * @return array<string, string>
     */
    public function extract(array $parameters): array
    {
        $carried = [];
        foreach (self::PARAMETERS as $name) {
            $value = $parameters[$name] ?? '';
            if (is_string($value) && $value !== '') {
                $carried[$name] = $value;
            }
        }

        return $carried;
    }

    /**
     * Set-Cookie value parking the pending authorization.
     *
     * SameSite=Lax is load-bearing, not a lazy default: a login provider that authenticates
     * outside TYPO3 comes back as a cross-site top-level navigation. Lax still sends the
     * cookie on that; Strict does not. "Hardening" this to Strict makes the cookie useless
     * for exactly the logins it exists to cover.
     *
     * @param array<string, string> $parameters
     */
    public function set(ServerRequestInterface $request, array $parameters): string
    {
        return self::NAME . '=' . base64_encode((string)json_encode($parameters))
            . '; Max-Age=' . self::LIFETIME_SECONDS
            . '; ' . $this->flags($request);
    }

    /**
     * Set-Cookie value dropping the cookie again.
     *
     * Must reproduce the exact Path/Secure attributes of {@see set()}, otherwise the browser
     * keeps the original cookie alongside the expired one.
     */
    public function clear(ServerRequestInterface $request): string
    {
        return self::NAME . '=; Max-Age=0; ' . $this->flags($request);
    }

    /**
     * The parked authorization, or an empty array when there is nothing to resume.
     *
     * Cookie content is client-supplied, so it is type-checked rather than trusted. A
     * malformed cookie reads as "nothing pending" instead of raising: this is evaluated on
     * backend requests, and a stale or tampered cookie must not be able to lock a user out
     * of the backend. Callers drop the cookie either way.
     *
     * @return array<string, string>
     */
    public function read(ServerRequestInterface $request): array
    {
        $raw = $request->getCookieParams()[self::NAME] ?? '';
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = base64_decode($raw, true);
        if ($decoded === false) {
            return [];
        }

        $parameters = json_decode($decoded, true);
        if (!is_array($parameters)) {
            return [];
        }

        $carried = $this->extract($parameters);

        // Without a client there is no authorization to return to. The endpoint validates
        // the client itself, so no further checking belongs here.
        return isset($carried['client_id']) ? $carried : [];
    }

    /**
     * Path is the TYPO3 installation root, not the current directory: the cookie is written
     * under /mcp_oauth and read back under /typo3.
     */
    private function flags(ServerRequestInterface $request): string
    {
        $path = $this->getRequestSitePath($request);
        $secure = $request->getUri()->getScheme() === 'https';

        return 'Path=' . ($path === '' ? '/' : $path)
            . '; HttpOnly; SameSite=Lax'
            . ($secure ? '; Secure' : '');
    }
}
