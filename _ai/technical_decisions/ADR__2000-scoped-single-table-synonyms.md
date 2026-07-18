---
title: Scoped-Single-Table Synonyms for Category and Product Search
status: Accepted
date: 2026-07-18
deciders: Topdata
tags: [shopware, elasticsearch, synonyms, category-search, architecture]
---

# Scoped-Single-Table Synonyms

## Context
Search synonyms were previously only used for Elasticsearch product indexing. Category search uses direct DAL `ContainsFilter` queries on the database, so synonym mappings had no effect on category results. Reusing synonym mappings for category search was desirable, but some rules might be specific to products (ES indexing) vs categories (DB queries), while most are globally relevant. There was no way to categorize synonym rules, and no synonym lookup in category search.

## Decision
We extended the existing `topdata_es_synonym` table with a `scope` column (`global`, `product`, `category`), keeping a single table rather than splitting. The `SynonymService` now:
- Parses bracketed scope prefixes (`[product] term => synonyms`) in text file imports (backward-compatible — bare `term => synonyms` defaults to `global`).
- Exports scoped rules for ES index config: only `global` + `product` scoped rules are injected into the Elasticsearch synonym filter.
- Provides `getExpandedTerms()` for run-time query expansion: category search queries the DB for `global` or `category` scoped rules matching the user's search term, building an `OrFilter` across all expanded terms.

## Consequences
- **Positive**: Single table, minimal schema change (one column). Existing synonyms default to `global` (backward compatible). File import format remains simple with optional bracket prefix. Category search now finds categories via synonyms.
- **Negative**: Category search pays a small per-query cost for DB lookup (single indexed query on `term`). Admin UI needs scope column & selector. The two query paths (ES index time, DB query time) use different expansion mechanisms, adding a second access pattern to maintain.

## Alternatives Considered
- **Separate tables per scope**: Rejected as schema bloat (three nearly identical tables, more migrations, more entity definitions).
- **Store scope in a separate mapping table**: Rejected as over-engineering for three enum values.
- **Index categories in ES**: Rejected because category search in SW 6.7 runs primarily on the relational DB; moving to ES would be a much larger effort with questionable benefit for the category suggest use case.

## Related Decisions
- `260604_0000__use_orm_for_querying_raw_sql_for_upsert.md` — established the hybrid ORM/raw-SQL pattern that this synonyms feature also follows.
