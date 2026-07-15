---
filename: "_ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md"
title: "Report: Synonym Administration Module and Dynamic Elasticsearch Index Integration"
createdAt: 2026-07-15 15:21
updatedAt: 2026-07-15 15:35
planFile: "_ai/backlog/active/260715_1520__IMPLEMENTATION_PLAN__synonym_admin_module_and_index_integration.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 7
filesModified: 4
filesDeleted: 1
tags: [elasticsearch, admin-module, synonyms, dal]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Synonym Administration Module and Dynamic Elasticsearch Index Integration

## 1. Summary
Implemented a full synonym management interface in the Shopware 6.7 Admin area using the standard DAL pattern. Hooked into the `ElasticsearchIndexConfigEvent` to dynamically inject the synonyms from the database into the Elasticsearch index settings during index creation.

## 2. Files Changed
### New Files Created
* `src/Entity/Synonym/SynonymEntity.php` - DAL entity for the `topdata_es_synonym` table
* `src/Entity/Synonym/SynonymCollection.php` - Entity collection class
* `src/Entity/Synonym/SynonymEntityDefinition.php` - DAL definition mapping DB columns to entity fields
* `src/Subscriber/ElasticsearchIndexConfigSubscriber.php` - Event subscriber that injects synonym rules into ES index settings via `ElasticsearchIndexConfigEvent`
* `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts` - Admin list page component with add/edit/delete modal
* `src/Resources/app/administration/src/module/topdata-es-synonym/index.ts` - Admin module registration
* `_ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md` - This report

### Modified Files
* `src/Resources/config/services.xml` - Registered `SynonymEntityDefinition` and `ElasticsearchIndexConfigSubscriber`
* `src/Service/SynonymService.php` - Added `exportToArray()` method for Elasticsearch-compatible synonym rule formatting
* `src/Resources/app/administration/src/snippet/de-DE.json` - Added German translations for synonym admin module
* `src/Resources/app/administration/src/snippet/en-GB.json` - Added English translations for synonym admin module
* `src/Resources/app/administration/src/main.ts` - Imported the synonym admin module

### Deleted Files
* `src/Elasticsearch/IndexCreatorDecorator.php` - Removed; replaced by event subscriber approach

## 3. Key Changes
* Registered `topdata_es_synonym` as a first-class DAL entity enabling standard Shopware API operations (pagination, searching, sorting, CRUD)
* Subscribed to `ElasticsearchIndexConfigEvent` to pull dynamically defined synonym lines from the database and append them to Elasticsearch analyzer properties as an inline `synonym` token filter
* Installed a dedicated administration dashboard panel under **Content > Zero Search Results > Search Synonyms** for listing, creating, editing, and deleting synonym rules inline
* The synonym filter (`topdata_synonym_filter`) is applied to the `topdata_delimiter_analyzer` before any casing/stemming, ensuring synonym expansion applies to hyphenated and compound terms

## 4. Deviations from Plan
* **Replaced `IndexCreator` decorator with event subscriber:** The plan proposed extending `IndexCreator` and overriding `createIndex()`. However, SW 6.7's `IndexCreator::createIndex()` takes `Context $context` as its 4th parameter (not `array $config`). Shopware 6.7 dispatches `ElasticsearchIndexConfigEvent` with a mutable config array before index creation, which is the intended extension point. The `ElasticsearchIndexConfigSubscriber` listens to this event and modifies the config in place, which is simpler and more maintainable than a decorator.

## 5. Technical Decisions
* Chose inline `synonym` token filter over file-based synonyms to avoid container-compilation-time database queries, which would break CI/CD pipelines
* Applied synonym filter before stemming/casing in the analyzer chain (`array_unshift`) to ensure synonym expansion happens on raw tokens
* Used the `ElasticsearchIndexConfigEvent` rather than decorating `IndexCreator` because the event carries a mutable config array that can be modified without re-implementing the parent's index creation logic

## 6. Testing Notes
Verify the implementation:
```bash
# Re-index all database records to apply synonym filter
php bin/console es:reset
php bin/console es:index --no-queue
php bin/console es:create:alias

# Test that synonyms are applied during search
php bin/console topdata:debug:search "klopapier"
```
