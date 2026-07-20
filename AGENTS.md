# AGENTS.md — topdata/elasticsearch-hacks-sw6

## Overview

Shopware 6.7 plugin that optimizes Elasticsearch tokenization (hyphenated term matching), manages search synonyms, tracks zero-result searches, and excludes categories from search.

Namespace: `Topdata\TopdataElasticsearchHacksSW6`  
Plugin class: `src/TopdataElasticsearchHacksSW6.php` (registers `ElasticsearchAnalysisCompilerPass`)

## Key Architecture

- **Compiler pass** (`DependencyInjection/ElasticsearchAnalysisCompilerPass.php`) injects `topdata_word_delimiter` filter and `topdata_delimiter_analyzer` into `elasticsearch.analysis` parameter.
- **Product definition decorator** (`Elasticsearch/ProductElasticsearchDefinitionDecorator.php`) adds `.delimiter` sub-field to product name mapping, and also wraps `buildTermQuery` so purely numeric terms with leading zeros (e.g. `"004000"`) also match the stripped product number (e.g. `"4000"`) via a `should`+`minimum_should_match: 1` wrapper around the original query.
- **Query boosting** (`Subscriber/ElasticsearchSearchSubscriber.php`): match_phrase (1M), topseller flag (750K), match AND (500K), delimiter AND (200K), wildcard (15K), prefix (1.1K). Boosts wrapped in `ConstantScoreQuery` for name fields to avoid length normalisation. Topseller boost applies to products with `customFields.topdata_is_topseller = true` (from `topdata-product-flags-sw6` plugin).
- **Product number search** (`Subscriber/ElasticsearchSearchSubscriber.php`): exact match via `TermQuery` on `productNumber` (boost 2M). For purely numeric terms, leading zeros are stripped in PHP and a second `TermQuery` for the stripped value (boost 1.5M) is added as a SHOULD clause so the stripped-SKU product is also ranked at the top once matched (matching itself is established by the decorator wrapper above).
- **Synonym injection** (`Subscriber/ElasticsearchIndexConfigSubscriber.php`): reads from `topdata_es_synonym` table, adds `topdata_synonym_filter` analyzer filter.
- **Zero-result tracking** (`Subscriber/ProductSearchSubscriber.php`): raw SQL upsert to `topdata_es_zero_search`.
- **Category exclusion** (`Subscriber/SearchCriteriaSubscriber.php`): `NotFilter` on `categoryTree` from system config.
- **Category suggest** (`Subscriber/CategorySuggestSubscriber.php`): listens to `SuggestPageLoadedEvent`, searches categories via `SalesChannelRepository` with `ContainsFilter` on `name` (filtered by active, visible, page-type, respecting `excludedCategories` config and sales channel root categories). Results attached to `SuggestPage` via `topdata_category_suggest` extension. Template override in `views/storefront/layout/header/search-suggest.html.twig` renders categories above products with section titles.

## Database

| Table | Entity Definition | Notes |
|-------|-------------------|-------|
| `topdata_es_zero_search` | `Entity/ZeroSearch/ZeroSearchEntityDefinition.php` | term, count, created_at, last_searched_at |
| `topdata_es_synonym` | `Entity/Synonym/SynonymEntityDefinition.php` | term, synonyms, created_at |

**Gotcha**: `updated_at` columns exist in both tables (added by migrations `Migration1752590000` and `Migration1752600000`) but are **not defined** in the entity definitions. If adding admin editing, sync entity fields with actual schema.

**ADR** (`_ai/technical_decisions/260604_0000__use_orm_for_querying_raw_sql_for_upsert.md`): ORM entity definitions for reads/admin API; raw SQL `ON DUPLICATE KEY UPDATE` for storefront write hot path.

## Setup / Reindex

```bash
php bin/console database:migrate TopdataElasticsearchHacksSW6 --all
php bin/console cache:clear
php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias
```

## Console Commands (prefix: `topdata:es-hacks:`)

| Command | Purpose |
|---------|---------|
| `export-zero-results` | List/failures formatted for LLM prompt |
| `validate-synonyms <file>` | Check syntax of synonym file |
| `import-synonyms <file>` | Import (use `--dry-run` first) |
| `list-synonyms` | List registered (use `--filter`, `--limit`) |
| `export-synonyms` | Backup to file (`--output`) |
| `delete-synonym <term>` | Remove one mapping |
| `clear-synonyms` | Wipe all (`--force` to skip confirm) |
| `topdata:es-hacks:debug-search <term>` | Debug ES scoring with explain output |

**Synonym file format**: `term => synonym1, synonym2` (one per line, `#` or `//` for comments).

After importing synonyms, run `es:reset && es:index --no-queue && es:create:alias` to apply them.

## Administration Modules

- **Zero Search Results**: Content → Zero Search Results (privilege: `system.zero_search.viewer`)
- **Synonyms**: Under Zero Search Results nav (privilege: `system.zero_search.viewer`)

Routes: `topdata.es.zero.search.list`, `topdata.es.synonym.list`

Admin API: `GET /api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export` (params: `limit`, `minCount`, `format=json|csv|markdown`)

## Storefront Date/Time Formatting

- Use `sw-time-ago` Twig component, **not** `|date` filter, for human-facing dates.
- `<sw-time-ago :date="item.createdAt" />` renders relative time with full-date tooltip.
- Pass `:date-time-format` for custom tooltip formatting.

## Storefront Views

`src/Resources/views/storefront/` — extend `@Storefront/storefront/page/content-section.html.twig`.

- **Search suggest**: `src/Resources/views/storefront/layout/header/search-suggest.html.twig` — extends core search-suggest template, prepends category results above products when matching categories exist.

## Configuration

`src/Resources/config/config.xml`:

| Key | Type | Default | Purpose |
|-----|------|---------|---------|
| `excludedCategories` | sw-entity-multi-id-select | — | Categories excluded from product search |
| `categorySuggestLayout` | sw-single-select (`above`/`left`) | `above` | Layout of category results in search suggest dropdown |

## Routes

PHP 8 attributes with `#[Route]`. Controllers in `src/Controller/` use `_routeScope` defaults (`api` or `storefront`). Routes auto-imported via `routes.xml`.

## Tests

`tests/` directory is empty. No test framework set up yet.

## Build / Admin Assets

Administration JS in `src/Resources/app/administration/src/`. Built output goes to `src/Resources/public/administration/` (gitignored).

## Future Direction

An active implementation plan (`_ai/backlog/active/260702_1334__IMPLEMENTATION_PLAN__rebrand_and_abstract_to_better_search.md`) plans to:
- Rebrand to `topdata-better-search-sw6`
- Replace subscribers with decorated `ProductSearchRoute`/`ProductSuggestRoute`
- Introduce `SearchBackendInterface` with swappable backends (Core, Meilisearch, Qdrant)
- Rename DB tables to `tdbs_` prefix with data migration
- Replace old commands with `tdbs:` prefix variants using `CliLogger`
