# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Changed

- GetFlexFormSchema resolves DataStructures through TYPO3's FlexFormTools,
  so DataStructure identifier events run. Dynamic DataStructures such as
  EXT:form (form_formframework) now expose runtime-injected fields
  (available forms, finisher override sheets). The recordUid parameter is
  now used to resolve record-dependent schemas.

### Removed

- Dead legacy code paths in GetFlexFormSchemaTool (raw-file DS reading).
