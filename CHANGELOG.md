# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Fixed

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
