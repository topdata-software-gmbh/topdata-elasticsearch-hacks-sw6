---
filename: "_ai/backlog/reports/260718_1700__IMPLEMENTATION_REPORT__implement_scoped_synonyms.md"
title: "Report: Implement Scoped-Single-Table Synonyms for Category and Product Search"
createdAt: 2026-07-18 17:00
updatedAt: 2026-07-18 17:00
planFile: "_ai/backlog/active/260718_1700__IMPLEMENTATION_PLAN__implement_scoped_synonyms.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 3
filesModified: 10
filesDeleted: 0
tags: [shopware, elasticsearch, synonyms, category-search, reports]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The scoped-single-table synonym approach has been successfully implemented. The synonyms table (`topdata_es_synonym`) now has a `scope` column supporting 'global', 'product', and 'category' settings. Elasticsearch index configuration only grabs 'global' and 'product' scoped synonyms, while category searches are expanded at query time via PHP parsing using matching 'global' or 'category' rules. The Administration panel has been updated to support and display the scope field. CLI commands have been aligned with Topdata Foundation standards using `CliLogger`.

## 2. Files Changed
- **New Files:**
  - `src/Migration/Migration1752840000AddScopeToSynonymTable.php` (DB structure migration)
  - `_ai/backlog/reports/260718_1700__IMPLEMENTATION_REPORT__implement_scoped_synonyms.md` (Self)
  - `_ai/technical_decisions/ADR__2000-scoped-single-table-synonyms.md` (ADR)
- **Modified Files:**
  - `src/Entity/Synonym/SynonymEntity.php` (DAL object model — added `$scope` property + getter/setter)
  - `src/Entity/Synonym/SynonymEntityDefinition.php` (DAL field definition — added `StringField('scope')`)
  - `src/Service/SynonymService.php` (Scoping, export logic, query expansion, file parser upgrades)
  - `src/Resources/config/services.xml` (Constructor DI — injected SynonymService into CategorySearchService)
  - `src/Service/CategorySearchService.php` (Query-time OR filtering via `getExpandedTerms`)
  - `src/Subscriber/ElasticsearchIndexConfigSubscriber.php` (Product config — scope filter `exportToArray('product')`)
  - `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts` (Scope column configuration & default)
  - `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/synonym-list.html.twig` (Scope column template + modal select)
  - `src/Resources/app/administration/src/snippet/de-DE.json` (German scope UI translations)
  - `src/Resources/app/administration/src/snippet/en-GB.json` (English scope UI translations)
  - `src/Command/Command_ImportSynonyms.php` (Migrated to TopdataFoundation CliLogger)
  - `src/Command/Command_ListSynonyms.php` (Scope column in table output, CliLogger)

## 3. Key Changes
- Extends synonym configuration mapping with a backward-compatible bracket prefix parser (`[product] term => synonyms`).
- Directs ES index configuration to skip indexing synonyms explicitly scoped to `'category'`.
- Introduces real-time database-driven synonym queries on the Storefront category search via automated SQL `OrFilter` expansion.
- Provides unified, localized admin interface labels matching standard Shopware design components.
- CLI commands now use `TopdataFoundationSW6` as base class with `CliLogger` for consistent output.

## 4. Technical Decisions
- **Single-Table Approach**: One `scope` column added to `topdata_es_synonym` — avoids schema bloat from separate tables per scope.
- **Query-Time Expansion vs Sync-Time Categories**: Category Search runs on direct relational DB (not ES), so query-time expansion was selected for decoupled, performant implementation.
- **Explicit Scoping Prefix Format**: Bracketed prefixes (`[scope]`) in text files preserve simple single-line formatting while offering robust metadata specification.
- **Backward Compatibility**: Existing synonyms without scope default to `global`. Text files without `[scope]` prefixes also default to `global`.
