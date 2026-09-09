# TYPO3 MCP Server — Extension Analysis, Branch State & Roadmap

Date: 2026-07-08
Scope: full-extension analysis, branch/merge audit (fork `BugHunter2k` vs upstream `hauptsacheNet`), and a backlog for future extension/optimization work.
Analyzed at: `integration/merge-main-into-v14` @ `a6763e3` (+ uncommitted FlexForm work).

---

## 1. What the Extension Does

`hn/typo3-mcp-server` (key `mcp_server`, v0.2.1 beta) exposes a **Model Context Protocol server** inside TYPO3 so LLM clients (Claude Desktop, ChatGPT, …) can read and edit content safely. All writes go through DataHandler into **TYPO3 workspaces** — never live — and require manual publishing. Workspaces are fully transparent to the MCP client (live UIDs only, auto-created "MCP Workspace for <user>" if none exists).

### Request flow (HTTP transport)
1. `Classes/Middleware/McpServerMiddleware.php` — single PSR-15 middleware registered for FE **and** BE stacks; routes by exact path: `/mcp`, `/mcp/preview`, `/mcp/upload`, `/mcp_oauth/*`, `/.well-known/oauth-*`, and `/typo3/main` (OAuth flow continuation after BE login).
2. `Classes/Http/McpEndpoint.php` — CORS preflight before auth → token extraction (Bearer / Apache `REDIRECT_HTTP_AUTHORIZATION` fallbacks / `?token=`) → `OAuthService::validateToken` → manual `BackendUserAuthentication` bootstrap → bundled `logiscape/mcp-sdk-php` runner with `FileSessionStore` in `var/mcp_sessions`.
3. `Classes/MCP/McpServerFactory.php` + `ToolRegistry` (Symfony `AutowireIterator` over the `mcp.tool` tag) dispatch `tools/list` / `tools/call`.

Second transport: **stdio** via CLI `mcp:server` (local, admin privileges).
OAuth: full Authorization Code + PKCE (S256), dynamic client registration, SHA-256-hashed tokens (`token_version` + upgrade wizard), managed via BE module `user_mcp_server` and CLI `mcp:oauth`.

### MCP tools
| Category | Tools |
|---|---|
| Discovery | `GetPageTree`, `GetPage`, `ListTables` |
| Read | `ReadTable`, `Search`, `GetTableSchema`, `GetFlexFormSchema` |
| Write | `WriteTable` (create/update/translate/move/delete; inline replace-all; FlexForm JSON→XML) |
| File/FAL | `ListStorages`, `BrowseFolder`, `SearchFile`, `PreviewFile`, `UploadFile`, `ImportFileFromUrl`, `GetUploadCredentials` |

### Key services
- `TableAccessService` (~1470 lines) — access control + the whole TCA introspection layer.
- `WorkspaceContextService` — optimal-workspace switching + auto-creation.
- `LanguageService` — ISO↔UID mapping from site config; empty ISO set ⇒ all translation aspects hidden.
- `SelectItemResolver`, `BaseUrlService`, `SiteInformationService`, `OAuthService`/`McpAuthenticationService`.
- Inline-relation + FlexForm write logic lives **inside** `WriteTableTool` (1200+ lines), not a service.

### Design decisions (see `Documentation/Architecture/`)
- **Workspace transparency**: custom SQL-level `WorkspaceDeletePlaceholderRestriction`, bidirectional live↔workspace UID resolution.
- **Language overlays**: deliberately TYPO3 `PageRepository` + `LanguageAspect` (unlike workspaces, which are custom).
- **Inline relations**: one atomic DataHandler call; embedded vs independent tables via TCA; `sys_file_reference` context fields forced server-side.
- **TCA-first**: any workspace-capable third-party extension works unmodified.
- **PSR-14 events**: `BeforeRecordWriteEvent` (veto/modify), `AfterRecordWriteEvent`, `Before/AfterRecordReadEvent`, `AfterSchemaLoadEvent`, `ModifyAvailableFieldsEvent`, `FilePreviewEvent`.

### Testing & versions
- Functional (~70 files, paratest/SQLite, `composer test`) incl. real `georgringer/news` integration; LLM tests via OpenRouter (`composer test:llm`, min-pass=3 majority); Playwright E2E (`Build/runTests.sh -s e2e`); CI matrix TYPO3 13.4/14.3 × PHP 8.2–8.5.
- Supports TYPO3 `^13.4 || ^14.3`, PHP ≥ 8.2, requires `typo3/cms-workspaces`.

---

## 2. Branch & Merge State

Fork setup: `origin` = BugHunter2k (fork), `upstream` = hauptsacheNet (canonical). Three layers: topic branches (`feat/*`, `fix/*`) → staging branches (`staging/all-improvements`, `staging/v14-all-improvements`, curated/rebased, so SHAs differ from topic branches) → `integration/merge-main-into-v14` (local-only, current).

### Verified facts (graph + content checks, 2026-07-08)
- `git log upstream/main ^integration` → **empty**: integration has full parity with upstream `main` (`0326a58`).
- `git log staging/all-improvements ^integration` → **empty**.
- `git log staging/v14-all-improvements ^integration` → **exactly one commit**: `53dc9bf` "fix: add CORS headers to actual MCP responses and allow MCP protocol headers" (also = origin-only branch `fix/mcp-cors-post-headers`).

### Merge matrix (topic branch → where it landed)
Upstream PR numbers inferred from `(#NN)` merge subjects in upstream/main (`gh` CLI unavailable on this host).

| Topic branch | upstream main | staging | v14-staging | integration | Landed as |
|---|:--:|:--:|:--:|:--:|---|
| fix/apache-auth-and-oauth-discovery | ✔ | ✔ | ✔ | ✔ | PR #33 |
| fix/foreign-type-notation | ✔ | ✔ | ✔ | ✔ | PR #32 |
| fix/schema-descriptions | ✔ | ✔ | ✔ | ✔ | PR #35 |
| fix/mcp-user-session-init-on-update | ✔ | ✔ | ✔ | ✔ | PR #52 |
| fix/workspace-overlay-in-page-tools | ✔ | ✔ | ✔ | ✔ | PR #36 |
| fix/inline-relation-refactoring | – | ✔ | ✔ | ✔ | `e690cc7` |
| fix/mcp-flexform-nested-dotted-keys | – | ✔ | ✔ | ✔ | `39614e2` |
| feat/schema-field-constraints | – | ✔ | ✔ | ✔ | `d9628b7` |
| feat/fal-read-and-reference | – | ✔ | ✔ | ✔ | `c443173` |
| feat/fal-upload-and-preview | – | ✔ | ✔ | ✔ | `52e9cbd` |
| fix/preview-inline-and-identifier | – | ✔ | ✔ | ✔ | `c6ef4ef` |
| feature/base-url-service | – | ✔ | ✔ | ✔ | `07e457b`+ |
| feat/workspace-record-preview | – | ✔ | ✔ | ✔ | `cec96b2` |
| docs/fix-workspace-permissions-owner-vs-member | – | ✔ | ✔ | ✔ | `c7ee7ac` |
| fix/cors-duplicate-headers | – | ✔ | ✔ | ✔ | `5060c3c` |
| feature/fal-support (monolith) | – | ✔ | ✔ | ✔ | split into FAL commits |
| fix/cors-preflight-and-auth-header-detection | – | – | ✔ | ✔ | `bd2223d`/`ff6f033` |
| **fix/mcp-cors-post-headers (origin only)** | – | – | ✔ | **✘ MISSING** | `53dc9bf` |

### Fork-carried work not (yet) in upstream (~20 commits)
v14 compatibility, complete FAL tool suite, BaseUrlService consolidation, inline-relation rewrite, workspace-overlay fix in page tools, schema field constraints, FlexForm dotted-path write fix, user-session init fix, workspace record-preview buttons, CORS preflight/header fixes, workspace-permissions docs.

### Cleanup candidates
- All 17 local topic branches are superseded snapshots (content lives in integration under reworded SHAs) → deletable after the `53dc9bf` cherry-pick.
- `fix/cors-duplicate-headers` diverged local (`021710c`) vs origin (`4f6bcf3`) — both superseded by `5060c3c`; prune both.
- `feature/fal-support` (23-commit monolith) superseded by the two focused FAL branches.
- `fix/update-crash-on-null-user-session` ≙ `fix/mcp-user-session-init-on-update` (duplicate pair).
- One stash exists but is unrelated (docs branch + LIAWEB-753 debug patch).

---

## 3. Incomplete / Open Work

### 3.1 Missing merge (action required)
- [ ] **Cherry-pick `53dc9bf`** (CORS headers on actual MCP responses + MCP protocol headers) into `integration/merge-main-into-v14`. This is the only staging work not yet integrated; without it, browser-based MCP clients get preflight-only CORS.
- [ ] Push `integration/merge-main-into-v14` to origin (currently **local-only** — single point of failure).

### 3.2 Uncommitted WIP: `GetFlexFormSchemaTool` refactor
Working tree carries a functionally complete rewrite (+145/−70): DS resolution now goes through `FlexFormTools::getDataStructureIdentifier()` / `parseDataStructureByIdentifier()` so PSR-14 DS events fire and **dynamic DataStructures (EXT:form etc.) resolve correctly**; synthesized candidate rows (`buildCandidateRows()`) with a guard against silent `default`-DS fallback; `recordUid` is now actually used. Tests updated consistently. Before commit:
- [ ] Fix stale description at `GetFlexFormSchemaTool.php:46` ("currently not used…" — no longer true) and the matching stale comment at `GetFlexFormSchemaToolTest.php:395`.
- [ ] Remove ~300 lines of dead code (pre-existing, grep-confirmed no callers): `getPointerFieldValues`, `getAvailableFlexForms`, `getFlexFormDS`, `processFlexFormDS`, `generateJsonExample`, `getExampleValueForField`, `processFlexFormField`, local `addFieldDetailsInline` wrapper.
- [ ] Run `composer test -- --filter GetFlexFormSchemaToolTest` (not yet executed — needs DB provisioning).

### 3.3 Known gaps (README project status + code findings)
- **Debug `error_log()` in production path** — `Classes/Http/McpEndpoint.php:60-88,293` logs method, all headers, query params, and token prefixes on every request. Replace with PSR-3 logger at debug level or remove.
- **Workspace selection**: always "first writable workspace"; no way to pick one.
- **Translation support**: marked experimental.
- Upstream has active `claude/*` development branches (copy/move actions for WriteTable, MCP Apps diff/publish widget, sys_note support, Symfony AI platform, page-tree optimizations, …) — worth watching before building the same features in the fork.

---

## 4. Roadmap: Future Extensions & Optimizations

### Update 2026-07-08 (after upstream sync to `2cdc3f3`)
Fork `main` on GitHub was synced with upstream; 4 new upstream commits arrived, **none covered by fork branches, none in integration yet** (content-verified):

| Commit | What | Coverage vs fork | Integration risk |
|---|---|---|---|
| `9b8800f` (#97) | SelectItemResolver: record context for itemsProcFunc | net-new (fork doesn't touch the file) | LOW — near-clean cherry-pick |
| `6830569` (#94/#98) | WriteTable: always allow language control field | net-new (fork only does ISO conversion) | LOW–MEDIUM — anchor moved in fork's WriteTableTool, one manual hunk |
| `ba4b5c8` (#101) | `mcp:oauth create` static tokens | net-new (OAuthService untouched by fork) | LOW — fork edits different regions of OAuthManageCommand |
| `2cdc3f3` (#100) | Subdirectory installs in HTTP/OAuth routing via new `RequestUrlTrait` (NormalizedParams) | **DIFFERENT mechanism, same problem space as `feature/base-url-service`** — fork's `BaseUrlService` does NOT handle path prefixes | **HIGH — direct collision** in OAuthMetadataEndpoint + all 8 touched files are fork-modified. Manual merge + design decision: fold subdirectory support into `BaseUrlService`, or migrate to `RequestUrlTrait` |

Deletable-branch verdict (content-verified against `upstream/main@2cdc3f3`):
- **Delete safely (merged upstream):** `fix/apache-auth-and-oauth-discovery` (#33), `fix/foreign-type-notation` (#32), `fix/schema-descriptions` (#35), `fix/mcp-user-session-init-on-update` (#52), `fix/workspace-overlay-in-page-tools` (#36); plus superseded snapshot `fix/update-crash-on-null-user-session`.
- **Fork-only value, NOT upstream** (branches redundant with integration, but the work exists nowhere else public): FAL suite (upstream `Classes/MCP/Tool/File/` is empty), `BaseUrlService`, schema field constraints, FlexForm dotted paths, inline-relation rewrite, PreviewFileTool, workspace record preview, CORS trio incl. un-integrated `53dc9bf`.

### Update 2026-07-08 (evening): upstream merge DONE
Commit `b602624` merged `upstream/main@2cdc3f3` into `integration/merge-main-into-v14` with the RequestUrlTrait migration (user decision: stay close to upstream):
- All request-based base URL resolution now uses upstream's `RequestUrlTrait` (endpoints, middleware, module controller, and the fork-only FAL tools `PreviewFileTool`/`GetUploadCredentialsTool`).
- `BaseUrlService` reduced to `getBaseUrlFromSiteConfiguration()`/`getConfiguredSite()` for request-less contexts (CLI `mcp:oauth`, tool fallbacks). **The fork's request-host validation against the configured site (siteRootPageId) was dropped** — candidate for a future opt-in upstream PR.
- Middleware combines upstream's site-path stripping with the fork's trailing-slash normalization.
- Module template: upstream's compact endpoint-status markup adopted (replaces the fork's endpoint-checks table).
- `composer.json`: kept fork's `paratest ^7.11`, added upstream's `b13/container ^4.0`. composer.lock is untracked — after this merge a `composer update b13/container georgringer/news -W` was required locally.
- Local `main` fast-forwarded to `2cdc3f3`; 6 local branches deleted (the 5 upstream-merged + `fix/update-crash-on-null-user-session`). Origin counterparts NOT deleted (push blocked by policy — user runs `git push origin --delete ...` manually).
- WIP fix applied (uncommitted, part of the FlexForm WIP): `buildCandidateRows()` no longer lets a real record's `default`-DS fallback shadow an explicitly requested identifier (legacy `list_type` rows vs news 14 CType registration caused NewsFlexFormTest:352 to fail).

Corrections to earlier findings:
- `SiteInstructionsService` dead DI registration in `Configuration/Services.yaml` DOES exist (earlier verification grep was broken by the rtk wrapper) — it is inherited from upstream, class exists nowhere. Cleanup PR candidate.
- Known test-infra weakness: several functional tests depend on `$GLOBALS['LANG']` being set by an earlier test class in the same paratest worker process; run in isolation they hit a TypeError in `BackendUtility::getLanguageService()` (e.g. NewsFlexFormTest via WriteTable `position`/moveRecord path). Fix candidate: initialize `$GLOBALS['LANG']` in the shared functional test setup.

### Update 2026-07-14: history rebuilt, CORS cherry-picked, staging removed
User policy: no work may exist only on the integration branch. `b602624` violated this (migration work embedded in merge resolution) and was rebuilt on `integration-rebuild` (identical tree, verified via `git diff b602624`):
- `50294fe` — merge upstream/main, conflict resolution only (rerere replayed the tested resolutions)
- `32fa0d3` on branch `refactor/base-url-request-url-trait` — the fork-only migration (FAL tools, BaseUrlService slim-down, Services.yaml), merged `--no-ff`
- `d41b642` — cherry-pick of `53dc9bf` (CORS headers on actual MCP responses) → **P1 item closed**
- Local `staging/all-improvements` + `staging/v14-all-improvements` deleted (content fully contained; `53dc9bf` now integrated)
- Old `b602624` retained as `backup/pre-history-rebuild` until the user completes the branch swap (guard-protected: rename `integration-rebuild` → `integration/merge-main-into-v14`)

### P1 — Finish the integration (short-term)
1. ~~Cherry-pick `53dc9bf`~~ DONE (`d41b642` on integration-rebuild).
2. ~~Cherry-pick the 4 upstream commits~~ DONE via merge (rebuilt as `50294fe` + `32fa0d3`).
3. ~~Fast-forward local `main`~~ DONE.
4. Commit the FlexForm WIP (§3.2 cleanups still open: stale recordUid description, dead code; full suite green as of 2026-07-08 including the candidate-order fix).
5. Delete the remaining superseded topic branches (local + origin counterparts) and `fix/mcp-cors-post-headers` after the `53dc9bf` cherry-pick; origin deletions for the 5 upstream-merged branches still pending (user runs the push).
6. ~~Upstream the fork-carried commits as PRs~~ **Decision 2026-07-14: fork features (FAL suite etc.) stay fork-only** — upstream follows its own plan; feature flow is upstream → fork. Bugfix PRs upstream remain possible case-by-case. `integration/merge-main-into-v14` is the production line consumed by LIA projects via composer.

### P2 — Hardening (medium-term)
5. Remove/replace `error_log()` debug output in `McpEndpoint` (token-prefix leakage into server logs).
6. Extract inline-relation + FlexForm write logic out of the 1200-line `WriteTableTool` into services (mirrors how `TableAccessService` centralizes TCA logic; enables reuse by future tools).
7. `TableAccessService` (1470 lines) → split TCA-introspection from permission checking.
8. Add workspace selection (client-visible parameter or per-token configured workspace) — closes the top README gap.

### P3 — Features (long-term, check upstream `claude/*` branches first to avoid duplicate work)
9. Copy/move record actions (upstream branch `claude/add-copy-move-commands` exists).
10. Stabilize translation support (currently experimental); leverage the existing LanguageService hiding mechanism.
11. sys_note support in GetPage (upstream branch exists).
12. GetSystemStatus tool via EXT:reports (upstream branch exists).
13. MCP Apps: inline diff + publish widget for WriteTable (upstream branch exists) — would give editors an in-client review step before publishing.
14. Page-tree performance: bulk queries + adaptive depth (two upstream optimization branches exist).

---

*Sources: three parallel analysis agents (architecture, merge topology, WIP) + manual verification of the merge-state claims on 2026-07-08. Note: the `SiteInstructionsService` finding was first wrongly dismissed due to a broken verification grep — it is real (dead, upstream-inherited service registration, see evening update).*
