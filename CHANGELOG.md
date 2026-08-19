# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

- The OAuth authorization link now survives an SSO login, not just a password
  login. The pending authorization was carried across the backend login only in
  the login URL's `redirect`/`redirectParams` query pair, and that query string
  does not survive every route through the login: switching the login provider
  uses a relative `?loginProvider=X` link that replaces the whole query, and an
  SSO login leaves TYPO3 and returns through a callback URL that
  `Mfc\OAuth2\ResourceServer\AbstractResourceServer::getRedirectUri()` rebuilds
  from a fixed set of parameters. In both cases the user ended up in the backend
  and had to open the authorization link a second time. The authorization is now
  also parked in a short-lived `tx_mcpserver_oauth` cookie (`SameSite=Lax`, so it
  survives the cross-site return from the identity provider), owned by the new
  `PendingAuthorizationCookie`, and picked up by the new
  `OAuthLoginReturnMiddleware` after the login. The middleware only acts on the
  request where a login completes, so an ongoing backend session is never
  interrupted; the query pair is unchanged and remains the fallback. No change to
  `mfc/oauth2` or `lia_oauth` is required.
- Removed `McpServerMiddleware::handleOAuthCookieContinuation()`, an earlier
  version of the same idea that could not fire in this extension: it read the
  cookie from `McpServerMiddleware`, which is pinned `before`
  `typo3/cms-backend/authentication` for the MCP endpoints, so no backend user was
  ever resolved when it checked `isLoggedIn()`. It also keyed on the request path
  `/typo3/main` rather than on the login, and never dropped the cookie.
  `OAuthLoginReturnMiddleware` replaces it with its ordering declared.
- Cookie and query pair now carry the same parameter set, `client_name` included
  (`Configuration/Backend/Routes.php` and
  `OAuthResumeController::AUTHORIZATION_PARAMETERS`). Without it, a resume through
  the query pair named the TYPO3 host on the consent screen instead of the client
  for the well-known seeded client, whose registered name is a placeholder.
- The backend login URL no longer pins `loginProvider=1450629977`. That
  identifier is not registered, so `LoginProviderResolver` discarded it and fell
  back to the `be_lastLoginProvider` cookie and then the primary provider
  anyway. Omitting it is behaviourally identical and lets an SSO-only
  installation put the user on its own provider directly.
- WriteTable no longer deletes file references of unrelated records when a
  file field is updated. The lookup for a record's existing inline children
  matched on `foreign_field` alone, so every `sys_file_reference` row whose
  `uid_foreign` happened to equal the parent UID was collected — across
  unrelated tables and unrelated fields — and deleted as an orphan. The
  lookup now applies the context columns TCA declares
  (`foreign_table_field`, `foreign_match_fields`) and refuses the write with
  a ConfigurationException if a `sys_file_reference` relation cannot be
  scoped at all.
- WriteTable no longer silently drops non-`settings` FlexForm subtrees
  (e.g. `{"persistence": {"storagePid": "..."}}`): ALL nested subtrees are
  dot-flattened and every field is stored in the sheet its DataStructure
  declares.
- WriteTable FlexForm updates are partial patches now: the stored value is
  loaded (workspace-resolved) and merged — previously updating a single
  FlexForm field silently deleted all other stored FlexForm values.
- ReadTable returns FlexForm values as nested JSON across all sheets;
  previously non-`settings` fields came back with mangled keys
  (e.g. `persistencestoragePid`).
- SiteInformationService::getAllDomains() now actually includes baseVariant
  hosts: the previous code probed for a `Site::getBaseVariants()` method that
  does not exist in TYPO3 13/14 and silently returned base hosts only.
  Variants are read from the raw site configuration; the configured main
  base is also kept when the runtime context already resolved a variant.

### Changed

- GetFlexFormSchema resolves DataStructures through TYPO3's FlexFormTools,
  so DataStructure identifier events run. Dynamic DataStructures such as
  EXT:form (form_formframework) now expose runtime-injected fields
  (available forms, finisher override sheets). The recordUid parameter is
  now used to resolve record-dependent schemas.
- WriteTable rejects FlexForm fields the record's DataStructure does not
  declare with an explicit error listing the available fields (previously a
  silent drop). ReadTable reports unparseable stored FlexForm XML as an
  error instead of returning an empty object.
- WriteTable declares `destructiveHint: false` and documents that "delete"
  only stages a workspace delete placeholder (reversible until publish) —
  cautious MCP clients refused delete calls because an absent hint defaults
  to true.

### Added

- FlexFormStructureService: shared DataStructure resolution (identifier- and
  record-row-based) with a field→sheet map for DS-aware writes.
- GetPage lists the frontend anchor (`#c<uid>`) of every content element and
  the `header_link` when set; GetPage and WriteTable document the RTE link
  convention `t3://page?uid=<pageId>#c<uid>` so LLM clients can build tables
  of contents.
- New CLI command `mcp:domains`: prints every domain the instance serves
  (site bases + all baseVariants) as JSON for the `x-mcp-proxy.domains` key
  of the project's `.mcp.json`, enabling the MCP gateway to map additional
  production domains to the correct backend.

### Removed

- Dead legacy code paths in GetFlexFormSchemaTool (raw-file DS reading).
