---
filename: "_ai/backlog/reports/260525_1730__IMPLEMENTATION_REPORT__synonym_management_suite.md"
title: "Report: Custom Elasticsearch Synonym Management & Zero-Result Analytics Suite"
createdAt: 2026-05-25 17:45
updatedAt: 2026-05-25 17:45
planFile: "_ai/backlog/archive/260525_1730__IMPLEMENTATION_PLAN__synonym_management_suite.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 12
filesModified: 2
filesDeleted: 0
tags: [shopware6, elasticsearch, synonyms, analytics, cli, api, migrations]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
Implemented a full command-line control suite for search optimization, consisting of a Search Failure Tracker that logs zero-result terms via event subscribers, and a Synonym Manager that interacts with dedicated database tables for CRUD operations on explicit mapping rules. Seven modular CLI commands and one REST API endpoint were added.

## 2. Files Changed
* **Created:**
  * `src/Migration/Migration1716652800CreateZeroSearchTable.php` — Creates `topdata_es_zero_search` and `topdata_es_synonym` tables.
  * `src/Subscriber/ProductSearchSubscriber.php` — Listens to `ProductSearchResultEvent` and logs zero-result terms.
  * `src/Service/SearchExportFormatter.php` — Formats zero-result data as JSON, CSV, Markdown, or LLM prompts.
  * `src/Service/ZeroSearchService.php` — Fetches and exports zero-result data from the database.
  * `src/Service/SynonymService.php` — Full CRUD for synonym mappings: list, delete, clear, import, export, validate.
  * `src/Command/Command_ExportZeroResults.php` — CLI to export zero-result search terms.
  * `src/Command/Command_ImportSynonyms.php` — CLI to bulk-import synonyms with dry-run support.
  * `src/Command/Command_ListSynonyms.php` — CLI to list/filter synonyms with pagination.
  * `src/Command/Command_DeleteSynonym.php` — CLI to delete a specific synonym by term.
  * `src/Command/Command_ClearSynonyms.php` — CLI to truncate all synonyms (with confirmation).
  * `src/Command/Command_ExportSynonyms.php` — CLI to export synonyms to a backup file.
  * `src/Command/Command_ValidateSynonyms.php` — CLI to validate synonym file syntax.
  * `src/Controller/ZeroResultsExportController.php` — REST API endpoint for zero-result export.
* **Modified:**
  * `src/Resources/config/services.xml` — Registered all new services, subscriber, commands, and controller.
  * `README.md` — Added full command reference guide, installation steps, and API docs.

## 3. Key Changes
* **Dual-table schema:** `topdata_es_zero_search` tracks failed searches with deduplication and counts; `topdata_es_synonym` stores explicit term-to-synonym mappings.
* **Event-driven logging:** `ProductSearchSubscriber` fires on `ProductSearchResultEvent`, records zero-result terms with upsert logic, and silently catches exceptions to avoid storefront degradation.
* **LLM prompt generation:** `SearchExportFormatter::formatLlmPrompt()` produces a structured prompt for AI-driven synonym suggestion from zero-result data.
* **Transactional imports:** `SynonymService::importFromString()` wraps bulk inserts in a transaction with validation-first approach and ON DUPLICATE KEY UPDATE for idempotency.

## 4. Deviations from Plan
* None. All five phases implemented as specified.

## 5. Technical Decisions
* **Symfony `#[AsCommand]` attributes:** Used PHP 8 attributes for command registration instead of YAML/XML service definitions, matching the existing `ExampleCommand` convention.
* **AbstractController for REST endpoint:** Extended `AbstractController` with `setContainer` call (same pattern as existing controllers) rather than injecting the full service container.
* **ON DUPLICATE KEY UPDATE for both tables:** Ensures idempotent upserts on re-import and avoids duplicate key violations on repeated zero-result searches.

## 6. Testing Notes
* **Verification:**
  1. Run `php bin/console database:migrate TopdataElasticsearchHacksSW6 --all` to create tables.
  2. Run `php bin/console topdata:es-hacks:export-zero-results --limit=10` to verify command registration.
  3. Run `php bin/console topdata:es-hacks:validate-synonyms` with a test file to verify validation logic.
  4. Test API endpoint: `GET /api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export?format=json`

## 7. Usage Examples
```bash
# Export zero-result terms for LLM analysis
php bin/console topdata:es-hacks:export-zero-results --format=llm-prompt --output=var/log/prompt.txt

# Validate, dry-run, then import synonyms
php bin/console topdata:es-hacks:validate-synonyms var/log/synonyms.txt
php bin/console topdata:es-hacks:import-synonyms var/log/synonyms.txt --dry-run
php bin/console topdata:es-hacks:import-synonyms var/log/synonyms.txt

# Manage synonyms
php bin/console topdata:es-hacks:list-synonyms --filter="papier"
php bin/console topdata:es-hacks:delete-synonym "wc-papier"
php bin/console topdata:es-hacks:export-synonyms --output=var/log/backup.txt
php bin/console topdata:es-hacks:clear-synonyms --force
```

## 8. Documentation Updates
* `README.md` updated with full command reference guide, database migration instructions, and API integration docs.
