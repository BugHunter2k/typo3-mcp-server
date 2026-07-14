# Plan: LIADEV-586 Round 2 — MCP Fixes & Extensions

## Metadata
- Status: in-progress
- Created: 2026-07-14 16:30
- Plan-ID: b2149fa9-dc79-4d9e-b4b4-742fb75b34e0
- Current Phase: 1/4
- Ticket: LIADEV-586 (primary)
- Structure: Complex (folder, plan.md only)
- Repo: /home/hollmann/public_html/public/typo3_ext/typo3-mcp-server
- Base branch: `integration/merge-main-into-v14` (d41b642, pushed to origin with tracking)

## Background & Problem Context

`hn/typo3-mcp-server` is a TYPO3 extension exposing an MCP server so LLM clients can
edit content (all writes via DataHandler into workspaces, live UIDs only). This repo is
the **BugHunter2k fork** — the LIA production line consumed via composer by 5 LIA
projects. Upstream is hauptsacheNet.

**Decision (2026-07-14, Ingo):** Fork features (FAL suite etc.) stay fork-only — no
upstream PRs for features. Feature flow is upstream → fork. See memory
`fork-strategy-no-fal-upstream`.

**Branch policy (Ingo):** No work may exist only on the integration branch. Every
change lives on a topic/feature branch merged `--no-ff` into
`integration/merge-main-into-v14`. Merge commits contain conflict resolution only.

This plan covers round 2 of LIADEV-586 findings — the open items from the ticket's 26
comments, analyzed 2026-07-14 (see also `specs/2026-07-08-extension-analysis-and-roadmap.md`):

1. **FlexForm write bug** (K26, Jessica Grab): writing `{"persistence": {"storagePid": "..."}}`
   into `pi_flexform` is silently discarded.
2. **Gateway domain mapping** (K25, Jenny): links like `partner.hormann.gr` cannot be
   mapped to a project by the MCP gateway.
3. **Anchor navigation** (K24, Vanessa Jung): LLM cannot build tables of contents with
   anchor links to content elements.
4. **Verification batch** (K20, Vanessa Jung, 2026-03-30): several old findings are
   presumably fixed by upstream #90/#94/#98 and fork workspace fixes — verify with
   regression tests on the current integration state.

### Root-cause analysis already done (2026-07-14)

**Phase 1 (verified in code, sharpened by ensemble review 2026-07-14):**
- `Classes/MCP/Tool/Record/WriteTableTool.php:1541-1552` — flexform JSON→XML conversion
  dot-flattens ONLY the `settings` subtree (`flattenFlexFormSettings`). **Any other
  nested object (`persistence`, `view`, …) is silently dropped** (loop skips
  `is_array($val)` for non-settings keys). Everything is hardcoded into sheet `sDEF`.
- **CRITICAL (review finding, cross-model confirmed): partial updates are destructive.**
  The tool builds a brand-new flex structure from ONLY the submitted fields and passes
  it to DataHandler as a pre-serialized XML **string**. DataHandler's merge-with-current-
  value logic (`checkValueForFlex`, `DataHandler.php:2395-2457`) only runs for **array**
  input — for strings the passthrough branch (`:2397-2400`) stores the XML as-is. Net
  effect: updating any single flexform field replaces the whole XML and **deletes all
  other existing flexform values**. The fix MUST fetch + merge the current value (see
  Phase 1 Task 3).
- Why correct sheet placement matters (corrected rationale): NOT DataHandler validation
  (which never runs on the string path) — but (a) TYPO3 FormEngine renders fields in
  their sheet's backend tab, and (b) EXT:form's DS events inject finisher-override
  fields under specific dynamically-created sheet keys. The read side
  (`FlexFormService::convertFlexFormContentToArray`) flattens across all sheets.
- `Classes/MCP/Tool/Record/ReadTableTool.php:589-628` — read side has the same
  settings-only special-casing (incl. catch block returning `[]` on parse failure);
  Jessica's read output showed the mangled flat key `persistencestoragePid`.
- **DS-resolution context gap (review finding):** `convertDataForStorage(string $table,
  array $data)` (`WriteTableTool.php:1488`) receives neither UID nor record row. On
  update, `$data` may not contain `CType`/`list_type`, so no DS can be resolved. The
  UID is available at all 4 call sites (`createRecord`:336, `updateRecord`:431, inline
  children :1073/:1098) and must be threaded through.
- The working tree carries an uncommitted **GetFlexFormSchemaTool WIP**: DS resolution
  via `FlexFormTools::getDataStructureIdentifier()` / `parseDataStructureByIdentifier()`
  (so DS events fire, EXT:form works), synthesized candidate rows, and a candidate-order
  fix (real record's `default`-DS fallback must not shadow an explicitly requested
  identifier — found via NewsFlexFormTest:352 with news 14). Full suite green with WIP:
  628 tests / 0 errors / 0 failures (2026-07-08).

**Phase 2 (from ticket attachment `mcp-gateway-architecture.md`, saved at
`~/tmp/ai/jira/LIADEV-586/mcp-gateway-architecture.md`; repo facts verified via GitLab
API during ensemble review 2026-07-14):**
- The gateway is a transparent routing proxy; its allowlist is lazy-built from GitLab
  projects with topic `mcp-server` reading their `.mcp.json` (24h TTL cache,
  stale-while-revalidate). Auth passes through 1:1.
- **Gateway repo (verified live): `git@gitlab.louis-net.de:lia/lia-mcp-gateway.git`**,
  default branch `main`, actively maintained (last activity 2026-06-25). File tree:
  `lia_typo3_mcp_gateway/`, `mcp-proxy.example.yaml`, `specs/`, `tests/`. Do NOT trust
  the architecture doc's `infra/mcp-proxy` clone URL (404) or GitLab searches for
  "mcp-proxy"/topic `mcp-server` (zero results).
- **Gateway domain: `mcp-proxy.burritodev.de` is authoritative** (confirmed by Ingo
  2026-07-14). The architecture doc's `mcp-proxy.louis-net.de` is stale; the doc
  (dated 2026-03-20, "Draft") may have diverged from the implementation in other
  details too — verify against the actual repo before changing gateway code.
- The gateway only knows hosts listed in `.mcp.json` files — that's why
  `partner.hormann.gr` (an additional domain of Hörmann production) can't be mapped.
- `SiteInformationService::getAllDomains()` (`SiteInformationService.php:110-144`)
  already collects base hosts + `getBaseVariants()` with dedup — the extension-side
  task is a thin CLI wrapper around it.

## Context & Questions

**Clarifications (2026-07-14, Ingo via AskUserQuestion):**

Q: Phase 2 scope — how far does domain mapping go?
A: **".mcp.json + Extension"** — the extension generates/delivers the domain list
   (base + baseVariants of all sites) that goes into the projects' `.mcp.json`; the
   gateway reads it when building the allowlist. Gateway adaptation is a separate part
   of the phase.

Q: Finalize + commit the GetFlexFormSchema WIP as part of Phase 1?
A: **Yes** — including the two known cleanups (stale recordUid description, dead code).

Q: How is staging-ki switched to `dev-integration/merge-main-into-v14` for Phase 4?
A: **Ingo does the deployment himself** — the plan treats it as a prerequisite.

**Earlier decisions this plan builds on:**
- Upstream FAL PRs will NOT happen (fork-only strategy) — former roadmap item removed.
- `staging/v14-all-improvements` must NOT be deleted until the 5 consumer projects
  switch constraints (memory: `staging-branch-used-by-projects`).

**Third-Party Dependencies:** None new. Everything uses TYPO3 core APIs
(`FlexFormTools`, `SiteFinder`) already present.

**Architectural Decisions:**

- **D1 — Shared DS resolution service.** Extract `resolveDataStructure()` /
  `buildCandidateRows()` from `GetFlexFormSchemaTool` into a small
  `Classes/Service/FlexFormStructureService` used by both the schema tool and
  WriteTable's sheet mapping. Rationale: two consumers, avoids duplicating the
  candidate-row logic. Alternative (duplicate logic in WriteTable) rejected: drift risk.
- **D2 — DS-aware sheet mapping on write, with fetch-and-merge semantics.** FlexForm
  JSON input is a **partial patch, not a full replacement**: fetch the record's current
  flexform value (workspace-resolved), parse it, overlay the incoming dotted-path
  values, then serialize. For each dotted field path, find its sheet by traversing the
  parsed DS (`$structure['sheets'][<sheetKey>]['ROOT']['el']` keys = flexform field
  names → map field name → sheet key; on duplicate field names across sheets, first
  sheet wins + log-free explicit error if ambiguous). Unknown fields produce an
  explicit error result instead of a silent drop. Rationale for sheet correctness:
  FormEngine tab rendering + EXT:form dynamic finisher sheets (NOT DataHandler
  validation — that never runs for string input). Alternative (send nested array to
  DataHandler and let its own `checkValueForFlex` merge) considered — rejected for now
  because the whole pipeline (incl. inline children) is built around string dataMaps;
  revisit if the merge implementation gets hairy.
- **D2b — DS resolution context.** Extend `convertDataForStorage()` (and its 4 call
  sites) to accept the workspace UID; on update, fetch the current record row for
  pointer-field context (CType/list_type), merged with pending `$data` (pending wins —
  covers CType changes in the same payload). On create, `$data` already contains CType
  (validated earlier). Mirrors `GetFlexFormSchemaTool::buildCandidateRows()`'s
  real-record path.
- **D3 — Domain list via CLI command** (`mcp:domains`, JSON output listing every site's
  base + baseVariants hosts). The list is embedded into the project's `.mcp.json`
  (new `domains` key) which the gateway already fetches. Rationale: fits the existing
  lazy-load architecture, no new HTTP endpoint/auth surface. Alternative (public
  discovery endpoint) rejected: gateway would need N extra requests + the data is
  quasi-static.
- **D4 — Anchor convention `#c<uid>`.** TYPO3 frontend renders content elements with
  `id="c<uid>"`. GetPage exposes the anchor per content element; tool descriptions
  document `t3://page?uid=<pid>#c<uid>` for RTE links. No new tool needed.

**Relevant patterns found:**
- `Classes/MCP/Tool/Record/GetFlexFormSchemaTool.php` (WIP) — FlexFormTools-based DS
  resolution incl. candidate rows + allowDefault guard.
- `Classes/Service/TableAccessService.php` — service style for TCA introspection.
- `Tests/Functional/NewsExtension/NewsFlexFormTest.php` + `PluginContentTrait` —
  version-agnostic plugin fixtures; the pattern for Phase 1 tests.
- `Tests/Functional/MCP/Tool/WriteTableToolTest.php` / `NonAdminWriteTest.php` —
  patterns for Phase 4 regression tests.
- Test assertions must use `$this->assertFalse($result->isError, json_encode($result->jsonSerialize()));`
  (project CLAUDE.md).

## Applicable Skills

| Phase | Skills | Validation Criteria |
|-------|--------|---------------------|
| 1, 3, 4 | lia-t3-backend:lia-t3-backend | TYPO3 service/TCA patterns, DataHandler usage |
| all | lia-git-tools:commit | Commit format on feature branches |
| 2 | lia-gitlab:gitlab | Locate gateway repo, MRs, .mcp.json in projects |
| 4 | lia-jira:jira | German ticket comment draft (posting only after explicit OK) |
| all | verify | End-to-end verification before commits |

## Implementation Plan

**Branching rule for every phase:** create the phase's feature branch from
`integration/merge-main-into-v14`, implement + test there, merge `--no-ff` back.
Never commit work directly on integration.

### Phase 1: FlexForm — DS-aware read/write (branch `fix/flexform-ds-aware-write`)
Estimated: 1–1.5 days. Fixes K26 (Jessica).

- [x] `[MED]` Finalize and commit the GetFlexFormSchema WIP (already in working tree):
  fix stale `recordUid` description in `GetFlexFormSchemaTool.php` getSchema()
  ("currently not used but accepted for compatibility" — it IS used now) and the
  matching stale comment in `Tests/Functional/MCP/Tool/GetFlexFormSchemaToolTest.php`
  (~line 395); delete the grep-verified dead methods `getPointerFieldValues`,
  `getAvailableFlexForms`, `getFlexFormDS`, `processFlexFormDS`, `generateJsonExample`,
  `getExampleValueForField`, `processFlexFormField` and the local
  `addFieldDetailsInline` wrapper. Acceptance: `vendor/bin/phpunit -c phpunit.xml.dist
  --filter "FlexFormSchema"` green (17 tests), no references to deleted methods.
- [x] `[HIGH]` Extract DS resolution into `Classes/Service/FlexFormStructureService`
  (per D1): move `resolveDataStructure()` + `buildCandidateRows()` +
  `dataStructureHasFields()` out of `GetFlexFormSchemaTool`; the tool delegates. Add a
  method returning the field→sheet map for a record row (parsed DS sheets keyed by
  dotted field name). Acceptance: schema tool tests stay green; new service has
  functional coverage via existing + one direct test.
- [x] `[LOW]` Multi-sheet test fixture: neither `news_pi1` (single sheet `sDEF`, no
  `persistence` field — verified in `flexform_news_list.xml`) nor any existing fixture
  covers the target scenario. Register a test-only CType in the functional test
  fixtures with a two-sheet FlexForm DS (sheet `sDEF` with `settings.*` fields, second
  sheet containing `persistence.storagePid`), following the existing fixture-extension
  pattern under `Tests/Functional/Fixtures/Extensions/`. Acceptance: GetFlexFormSchema
  lists both sheets for the fixture CType.
- [x] `[HIGH]` Generalize WriteTable flexform JSON→XML (`WriteTableTool.php:1516-1562`,
  per D2 + D2b): **fetch-and-merge** — load the record's current flexform XML
  (workspace-resolved), overlay incoming dotted paths, serialize; dot-flatten ALL
  nested subtrees (not only `settings`); resolve the record's DS via
  `FlexFormStructureService` (thread workspace UID into `convertDataForStorage()` and
  its 4 call sites: createRecord :336, updateRecord :431, inline children :1073/:1098)
  and place each field into its correct sheet; fields not present in the DS → explicit
  error in the tool result (no silent drop). Keep raw-XML passthrough. Acceptance
  (against the multi-sheet fixture): (a) writing `{"persistence": {"storagePid":
  "109,142"}, "settings": {"contacts": ""}}` stores `<field
  index="persistence.storagePid">` in the correct sheet; (b) **partial-update test:
  write field A, then update only field B — field A survives** (guards the CRITICAL
  destructive-replace bug); (c) unknown field returns isError naming the field.
- [x] `[MED]` Symmetric read side (`ReadTableTool.php:588-628`): replace the
  settings-only simplification with generic nesting for all dotted paths/sheets
  (`persistence.storagePid` → `{"persistence": {"storagePid": ...}}`). Decision: on
  flexform parse failure return an explicit tool error instead of the current silent
  `[]` (matches "don't write forgiving code"). Add a write→read round-trip functional
  test (K26 scenario). Update WriteTable/ReadTable tool descriptions (currently
  mention only `settings.fieldName`).
- **REVIEW GATE:** full suite `composer83 test` green; K26 round-trip test green;
  present diff summary → human approval → merge `--no-ff` into integration.

### Phase 2: Domain mapping — extension, .mcp.json, gateway (branch `feat/site-domains-export`)
Estimated: 1–2 days. Fixes K25 (partner.hormann.gr).
⚡ PARALLEL: independent of Phase 1; may run before/alongside it.

- [ ] `[MED]` Extension: new CLI command `mcp:domains` (per D3) in
  `Classes/Command/` (follow `OAuthManageCommand` style, register in
  `Configuration/Services.yaml`): outputs JSON `{"domains": ["www.example.com",
  "stage.example.com", ...]}` from `SiteFinder` — every site's base host + all
  baseVariant hosts, deduplicated; reuse/align with
  `SiteInformationService::getAllDomains()` (already exists, check coverage of
  baseVariants). Functional test with multi-site + baseVariant fixture.
- [ ] `[MED]` `.mcp.json` schema: add optional `domains` array; document the key and
  the `mcp:domains` generator in README.md (integrator track) and update the
  `enabling-typo3-ai-gateway` skill at
  `~/.claude/skills/enabling-typo3-ai-gateway/SKILL.md` (standalone skill directory —
  NOT part of the louis-claude-marketplace repo) so new setups include domains.
- [ ] `[MED]` Gateway: extend the allowlist builder to also index the `domains` arrays
  (domain → project/backend mapping) and use it to resolve incoming URLs (e.g. a
  pasted backend link `https://partner.hormann.gr/typo3/...`) to the correct backend —
  suggested behavior: the 403 path answers with the matched project's canonical MCP URL
  when a domain matches. **Gateway repo:
  `git@gitlab.louis-net.de:lia/lia-mcp-gateway.git`, branch `main`** (verified live —
  ignore the architecture doc's stale `infra/mcp-proxy` URL); gateway domain is
  `mcp-proxy.burritodev.de`. Read the repo's `specs/` and `tests/` first to align with
  its conventions; implement + unit test there, deliver as MR.
- **REVIEW GATE:** `mcp:domains` output verified against a real multi-domain site
  config (Hörmann has partner.* variants); gateway test proves domain→project lookup;
  human approval; extension part merged `--no-ff`; gateway change as MR in its repo.

### Phase 3: Anchor navigation (branch `feat/anchor-navigation`)
Estimated: 0.5 day. Fixes K24 (TOC/anchors).
⚡ PARALLEL: independent of Phases 1–2.

- [ ] `[MED]` GetPage: per content element expose the frontend anchor (`#c<uid>`, per
  D4) and `header_link` when set, in the content summary output
  (`Classes/MCP/Tool/GetPageTool.php`); functional test asserting anchors appear for a
  page with several elements.
- [ ] `[LOW]` Tool descriptions: document the anchor convention in GetPage and
  WriteTable (bodytext RTE links: `t3://page?uid=<pid>#c<uid>`), so the LLM can build
  a TOC without guessing; extend an existing WriteTable functional test to write a
  bodytext containing such a link and read it back intact.
- **REVIEW GATE:** suite green; manual sanity: GetPage output contains anchors;
  human approval → merge `--no-ff`.

### Phase 4: Verification batch K20 on staging-ki (branch `test/k20-regression-batch`)
Estimated: 0.5–1 day.
**Prerequisite (Ingo, manual):** louis-website staging-ki runs
`hn/typo3-mcp-server: dev-integration/merge-main-into-v14`.

K20 finding → regression test mapping (source: LIADEV-586 comment #459960, Vanessa
Jung, 2026-03-30):

| K20 finding | Covered by task | Expected state |
|---|---|---|
| "Umbau" Text-Media → Teaserbox: WriteTable update failed | Task 1 (CType change) | fixed by upstream #94/#98 |
| Subheader field could not be filled | Task 2 | fixed by upstream #90 |
| Page creation in workspace failed | Task 2 | fixed by fork workspace fixes |
| bodytext must be emptied manually | Task 2 | works via update with `""` — prove it |
| "Ich kann permanente Löschungen nicht durchführen" | Task 3 (delete UX) | tool description issue |
| FAQ answers not filled on element replace | Task 4 (live verification) | LLM/prompt behavior, observe |

- [ ] `[MED]` Regression test: CType change with inline relations (K20 "Umbau
  Text-Media → Teaserbox failed") — functional test switching CType on update incl.
  image reference; expected already fixed by upstream #94/#98 (language control field);
  fix if red.
- [ ] `[MED]` Regression tests: subheader writable (expected fixed by upstream #90
  showitem-driven schema), page creation in workspace, bodytext emptied via update
  with `""` (K20 items) — add to `WriteTableToolTest`/`NonAdminWriteTest`; fix if red.
- [ ] `[MED]` Delete UX (K20 "kann nicht löschen"): sharpen the WriteTable `delete`
  action description — deletion is staged in the workspace (delete placeholder),
  not permanent until publish; verify annotations (destructiveHint) don't make clients
  refuse; functional test for delete-in-workspace already exists → extend if gaps.
- [ ] `[LOW]` Live verification on staging-ki via MCP client against the K20 scenarios;
  draft a German ticket comment for LIADEV-586 summarizing verified/fixed/open
  (jira-comment-writer skill; **no posting without explicit OK**).
- **REVIEW GATE:** all K20 findings either covered by green regression tests or
  documented with evidence; human approval → merge `--no-ff`.

## Validation Pipeline

After each phase (on the feature branch):

1. Syntax: `php -l` on changed files (system PHP 8.3 — `lit-php` does not work in this
   standalone repo, no bootstrap.conf).
2. Targeted tests first: `vendor/bin/phpunit -c phpunit.xml.dist --filter "<Area>"`.
3. Full suite (background, ~5 min): `composer83 test` — **use `composer83`, bare
   `composer` is rejected on this server.** Baseline: 628 tests, 0 errors, 0 failures
   (14 pre-existing PHP warnings are known: `$LANG`/doktype — see gotcha below).
4. E2E when HTTP/routing behavior is touched (Phase 2): `Build/runTests.sh -s e2e`
   (Docker; `-n` for local fallback).
5. Commit via lia-git-tools:commit skill; CHANGELOG.md entry proposal before commit
   (per global rules — repo has no CHANGELOG.md yet; create on first fix commit).

**Gotchas:**
- rtk shell wrapper mangles `git log A ^B`, `git -C`, and multi-path/`-r` grep — verify
  negative results with `rtk proxy git ...` (memory: `rtk-wrapper-git-quirks`).
- Several functional tests depend on `$GLOBALS['LANG']` being set by an earlier class in
  the same paratest worker; isolated runs can TypeError in
  `BackendUtility::getLanguageService()`. Judge red/green on the full paratest run.
- `composer.lock` is untracked; after dependency-affecting merges run
  `composer83 update <pkg> -W`.

## Success Criteria

- K26: `persistence.storagePid` write→read round-trip works (functional test), Jessica's
  scenario re-tested OK on staging-ki.
- K25: a partner-domain URL resolves to the correct project via gateway (test + manual
  check with Hörmann domains).
- K24: GetPage exposes `#c<uid>` anchors; TOC creation prompt works on staging-ki.
- K20: every item has a green regression test or documented evidence; German summary
  comment drafted for the ticket.
- All work on feature branches, merged `--no-ff`; integration pushed; full suite green.

## Context Loss Recovery

After /clear: read this plan, then `specs/2026-07-08-extension-analysis-and-roadmap.md`
(repo/branch state), check `git status` for the Phase-1 WIP (GetFlexFormSchemaTool.php +
test modified = WIP not yet committed → start with Phase 1 task 1). Memories:
`fork-strategy-no-fal-upstream`, `staging-branch-used-by-projects`,
`rtk-wrapper-git-quirks`. Ticket details: `fetch-issue.py LIADEV-586` (comments K20,
K24, K25, K26 are the drivers).

## Time Tracking

### 2026-07-14
- [13:25-] - Phase 1: FlexForm DS-aware read/write (Task 1: WIP finalization)
  Commits: (pending)
  Context: continuous

## Progress Log

- [2026-07-14 16:30] Plan created
- [2026-07-14 18:00] Ensemble review (2× Claude + 1× Codex, consensus 6/10 "has gaps")
  — all 6 findings incorporated: CRITICAL destructive partial-update replace (D2
  fetch-and-merge + partial-update test), DS context threading (D2b), gateway repo
  verified as lia/lia-mcp-gateway + domain mcp-proxy.burritodev.de confirmed by Ingo,
  multi-sheet test fixture task added, root-cause rationale corrected
  (FormEngine/EXT:form instead of DataHandler validation), skill path corrected to
  ~/.claude/skills/. Status: review-fixes applied, ready for implementation.
- [2026-07-14 13:35] Task: Finalize GetFlexFormSchema WIP (Phase 1, Task 1)
  - Branch `fix/flexform-ds-aware-write` created from integration (d41b642)
  - Cleanups: recordUid description updated (it IS used now), stale test comments
    fixed, 8 dead methods deleted (getPointerFieldValues, getAvailableFlexForms,
    getFlexFormDS, processFlexFormDS, generateJsonExample, getExampleValueForField,
    processFlexFormField, addFieldDetailsInline wrapper), unused imports
    (FlexFormService in tool, GeneralUtility in test) removed, non-capturing
    catches, trailing newline in test
  - Verification: dead methods grep-verified unreferenced; targeted suite
    17/17 green (81 assertions); full suite 628 tests / 0 errors / 0 failures
    (14 known baseline warnings)
  - Confidence: self-review + full-suite evidence
- [2026-07-14 13:40] Task 1 committed as 11e4332 (user approved; CHANGELOG.md created)
- [2026-07-14 14:00] Task: FlexFormStructureService extracted (Phase 1, Task 2)
  - New Classes/Service/FlexFormStructureService.php: resolveDataStructure()
    (identifier-based, moved), resolveDataStructureForRecord() (row-based, for
    write path), getFieldSheetMap(), private resolveFromCandidates()/
    buildCandidateRows()/dataStructureHasFields(); tool delegates
  - IMPORTANT finding (env = TYPO3 13.4.27 + news 14.0.3): legacy-shape plugin
    rows (CType=list, list_type=news_pi1) fall back to the DEFAULT DS in
    FlexFormTools (ds key `*,news_pi1` never tried for legacy pointer values).
    resolveDataStructureForRecord() therefore walks candidates: real row
    (non-default) → synthesized rows from the row's plugin identifier → real
    row accepting default as last resort. Task 4 MUST use this method (not
    plain FlexFormTools) or sheet mapping breaks for exactly the records our
    tests create via PluginContentTrait.
  - Verification: VERIFIED by critic agent (confidence 5/5, behavior-identical
    extraction confirmed against git show HEAD); 6 direct service tests; full
    suite 634 tests / 0 errors / 0 failures
- [2026-07-14 14:05] Task: Multi-sheet test fixture (Phase 1, Task 3)
  - New fixture extension Tests/Functional/Fixtures/Extensions/test_flexform:
    CType `test_multisheetflex`, DS sheets sDEF (settings.contacts,
    settings.sortOrder) + persistence (persistence.storagePid)
  - Acceptance test green: GetFlexFormSchema lists both sheets (18/18)
  - Gotcha: stale functional-instance dirs / sqlite files from interrupted
    parallel runs cause "Can not link extension folder" — remove the stale
    functional-<hash> dir and rerun
- [2026-07-14 14:35] Task: DS-aware fetch-and-merge write path (Phase 1, Task 4)
  - convertDataForStorage() got a ?int $recordUid param (workspace UID on
    update, null on create); all 4 call sites threaded; updateRecord resolves
    the workspace UID BEFORE conversion now
  - New convertFlexFormValueForStorage(): loads current record + XML, resolves
    DS via FlexFormStructureService (row = record + pending data, pending
    wins), flattens ALL subtrees, validates against field→sheet map (unknown →
    ValidationException listing available fields; ambiguous → error), merges
    current values (canonical re-sheeting heals old sDEF-only writes), keeps
    raw-XML passthrough; new extractFlexFormValues() parses stored XML
    (parse failure → explicit error, fix via raw-XML passthrough)
  - Gotcha: ValidationException takes array of errors, not string
  - 3 legacy tests adapted (wrote undeclared/legacy fields, relied on silent
    drop): NewsFlexFormTest::testDifferentNewsPluginModes (+Complex...),
    WriteTableToolTest::testFlexFormFieldHandling
  - Acceptance green: sheet placement, partial-update survival (CRITICAL),
    unknown-field error (WriteTableToolFlexFormTest, 4 tests)
- [2026-07-14 14:45] Task: Symmetric read side (Phase 1, Task 5)
  - ReadTableTool returns FlexFormService::convertFlexFormContentToArray()
    directly (core API nests ALL dotted paths across sheets — the old
    settings-only loop was legacy un-mangling for pre-fix writes and produced
    Jessica's `persistencestoragePid`); parse failure now throws McpException
    422 instead of silent []
  - Tool descriptions updated: WriteTable (partial-patch semantics, nested
    JSON example, GetFlexFormSchema pointer), ReadTable (nested output shape)
  - K26 round-trip test green (write persistence.storagePid as JSON → read
    back same nested JSON)
  - Full suite: 639 tests / 0 errors / 0 failures (15 warnings = known
    $LANG/doktype family, +1 group from new test classes)
- [2026-07-14 15:15] Critic verification Tasks 4+5: ISSUES_FOUND (confidence
  5/5) — both confirmed and fixed:
  1. Read-side parse-failure catch was UNREACHABLE: FlexFormService swallows
     xml2array's error string via `['data'] ?? []` and returns [] silently.
     Fix: explicit xml2array() validation in ReadTableTool before conversion,
     throw McpException 422 on error string. New regression test
     testUnparseableStoredFlexFormReturnsReadError green.
  2. extractFlexFormValues foreach-warning on empty sheets (xml2array parses
     empty sheet as 'lDEF' => '' string — the 15th suite warning was OURS,
     not baseline). Fix: is_array guard like core's FlexFormService.
  - All other requirements: complete with evidence (14 evidence items);
    reorder of resolveToWorkspaceUid verified side-effect-free; pre-existing
    stale docblock in ensureL10nStateForTranslation noted (unchanged, out of
    scope)

## Implementation Checklist

### Phase 1: FlexForm DS-aware read/write
- [x] WIP finalized + committed (cleanups done)
      (committed 11e4332)
- [x] FlexFormStructureService extracted
- [x] Multi-sheet test fixture extension registered
- [x] Write path generalized (fetch-and-merge, DS-aware sheets, no silent drops)
- [x] Partial-update survival test green (CRITICAL guard)
- [x] Read path symmetric + round-trip test + parse errors explicit
- [ ] **REVIEW GATE:** suite green, human approval, merged --no-ff

### Phase 2: Domain mapping
- [ ] `mcp:domains` command + test
- [ ] `.mcp.json` `domains` key documented (README + gateway skill)
- [ ] Gateway allowlist/domain lookup + test (separate repo MR)
- [ ] **REVIEW GATE:** human approval, merged --no-ff

### Phase 3: Anchor navigation
- [ ] GetPage anchors + header_link exposed + test
- [ ] Tool descriptions (t3://…#c<uid>) + link round-trip test
- [ ] **REVIEW GATE:** human approval, merged --no-ff

### Phase 4: K20 verification batch
- [ ] Prerequisite: staging-ki on dev-integration (Ingo)
- [ ] CType-change regression test
- [ ] Subheader/page-create/bodytext-empty regression tests
- [ ] Delete-UX description + verification
- [ ] Live verification + German ticket comment draft
- [ ] **REVIEW GATE:** human approval, merged --no-ff
