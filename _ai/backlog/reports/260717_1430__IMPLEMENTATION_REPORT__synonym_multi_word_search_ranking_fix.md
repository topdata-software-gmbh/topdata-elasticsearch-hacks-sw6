---
filename: "_ai/backlog/reports/260717_1430__IMPLEMENTATION_REPORT__synonym_multi_word_search_ranking_fix.md"
title: "Report: Synonym Multi-Word Search and Ranking Fix"
createdAt: 2026-07-17 14:30
updatedAt: 2026-07-17 14:30
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 0
filesModified: 2
filesDeleted: 0
tags: [elasticsearch, synonyms, analyzer-chain, scoring, constant-score]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Synonym Multi-Word Search and Ranking Fix

## 1. Summary
Fixed two compounding defects that prevented a multi-word synonym rule (`WC Papier => WC-Papier`) from surfacing the hyphenated products at the top of the search results. First, the synonym token filter was injected too early in the analyzer chain (before `lowercase`), so it never matched the uppercase tokens produced by the standard tokenizer; the `.search` analyzers used by Shopware's high-boost queries also had no synonym filter at all. Second, the boost clauses in `ElasticsearchSearchSubscriber` used `boost` on `MatchPhraseQuery` which is multiplied by Lucene's length-normalised relevance score, letting unrelated matches (e.g. `Papierhandtücher`) out-rank the synonym-matched products. Wrapping the boosts in `constant_score` guarantees all synonym-matched products receive an identical large additive score.

## 1.5 Prompt used
> i added a synonym "WC Papier" --> "WC-Papier" but it does not list WC-Papier at the top of the search results.
> the synonyms are working .. i tested with "Klopapier" which the finds WC-Papier .. but entering "WC Papier" --> "WC-Papier" does not work.
> i think the problem is that "WC Papier" gets tonenized into 2 tokens - and then the synonyms not working anymore.
>
> it is better now.. 1st result is "Wc-Papier" .. but then unrelated results appear: [list of Papierhandtücher products] how can we fix this?

## 2. Files Changed
### New Files Created
* (none)

### Modified Files
* `src/Subscriber/ElasticsearchIndexConfigSubscriber.php` - Reordered the `topdata_delimiter_analyzer` filter chain to `[lowercase, topdata_synonym_filter, topdata_word_delimiter]`, added `ignore_case => true` to the synonym filter, and additionally inject `topdata_synonym_filter` into Shopware's `sw_whitespace_analyzer`, `sw_german_analyzer`, and `sw_english_analyzer` directly after the `lowercase` stage.
* `src/Subscriber/ElasticsearchSearchSubscriber.php` - Wrapped the three text-analyzer boost clauses (`match_phrase`, `match AND` on `.search`, and `match AND` on `.delimiter`) in `ConstantScoreQuery` with large fixed boosts (1M / 500K / 200K), and scaled up the wildcard/prefix boosts (15K / 15K / 1.1K) so they no longer get drowned out by the base query's `ranking`-weight scores.

### Deleted Files
* (none)

## 3. Key Changes
* **Filter chain reorder** (`ElasticsearchIndexConfigSubscriber`): `lowercase` now runs **before** `topdata_synonym_filter` so the synonym filter sees the lowercased token sequence the rules were stored against. Previously `array_unshift($filters, 'topdata_synonym_filter')` placed the filter before `lowercase`, allowing uppercase tokens (`["WC", "Papier"]`) to bypass the `wc papier => wc-papier` rule entirely.
* **`ignore_case: true`** on the `synonym` filter as a belt-and-braces safety net, so case differences in user input or rules never silently prevent a match.
* **Synonym filter on the `.search` analyzers**: `sw_whitespace_analyzer`, `sw_german_analyzer`, `sw_english_analyzer` previously had no synonym filter at all. The high-boost `match_phrase` (30×) and `match`-AND (20×) queries in `ElasticsearchSearchSubscriber` target the `.search` sub-field, so without synonyms there a multi-word query could never produce the hyphenated token that was indexed for "WC-Papier" products — meaning the synonym effectively never helped the high-boost clauses rank it #1.
* **`ConstantScoreQuery` wrapping** in `ElasticsearchSearchSubscriber`: a plain `MatchPhraseQuery(['boost' => 30])` yields `30 × length-normalised-relevance`, which differs per document (short product names score higher than long ones). Two WC-Papier products of differing name length ended up at widely different ranks. Wrapping in `constant_score { filter: matchPhrase, boost: 1_000_000 }` produces an identical constant score of `1_000_000` for every matching doc, so all synonym-matched products form a tight cluster at the top, and unrelated base-only matches (Papierhandtücher) drop below them.
* **Magnitude selection**: the boost magnitudes (`1M / 500K / 200K / 15K / 15K / 1.1K`) are tuned to always exceed the contribution of Shopware's base query (whose field `ranking` values from `product_search_config_field` typically sit in the hundreds-to-thousands range). They are intentionally spaced so the priority order of clauses remains: exact phrase > full match > delimiter match > word-boundary wildcard > prefix.
* **Wildcard / prefix kept unwrapped**: these query types already produce a constant score in Lucene (no tf/idf or length normalisation), so wrapping them is unnecessary — kept plain with elevated numeric boosts.

## 4. Deviations from Plan
* No formal plan file was prepared up-front (user reported the issue conversationally). The fix was driven by inspecting the actual `TokenQueryBuilder`, `ProductSearchQueryBuilder`, `ElasticsearchHelper::addTerm`, the cached DI container analysis config, and Shopware's `elasticsearch.yaml`. The two-file fix was scoped to the plugin's existing subscriber surface so it required no new services, no DAL schema changes, and no new admin module.
* **No filter removal**: After the second round (Papierhandtücher results appearing below the WC-Papier ones), a more aggressive option — completely removing the loosely-matched `Papierhandtücher` documents from the result set — was explicitly avoided. That would require decorating `ProductSearchQueryBuilder` / `ElasticsearchProductSearchBuilder` to suppress fall-back single-token prefix matches, which is more invasive. The constant-score approach was preferred because it keeps the existing search broadness intact (only the *order* is changed), which is safer for other queries that legitimately rely on prefix fall-back.

## 5. Technical Decisions
* **Why `lowercase` before `topdata_synonym_filter`**: Elasticsearch's `synonym` token filter is case-sensitive out of the box. `SynonymService::importFromString()` lowercases both the rule term and its synonyms, so the query tokens must also be lowercase when the synonym filter sees them. Placing `lowercase` first (with `ignore_case` as fallback) is the canonical pattern recommended by the Elasticsearch docs for synonym filters that consume multi-token rules.
* **Why `word_delimiter` is kept last**: the synonym filter emits the single condensed token `wc-papier` as output. Running `word_delimiter` afterward splits it into additional tokens (`wc`, `papier`, `wc-papier`, `wcpapier`), so substring/word matches still work for partial queries — without losing the condensed form that fulfils the synonym intent.
* **Why inject into `sw_*` analyzers too, not only `topdata_delimiter_analyzer`**: the `.search` field that the user-facing search queries actually target is analysed by Shopware's language-specific analyzer (`sw_german_analyzer` for the German shop). Without synonyms being added there the high-boost `match_phrase` query could never "see" the synonym expansion at query time.
* **Why `ConstantScoreQuery` over inflated numeric `boost`**: `MatchPhraseQuery` with `boost: N` multiplies `N` by Lucene's relevance score, which is length-normalised — meaning short-name documents get more points than long-name ones for *the same synonym match*. `ConstantScoreQuery` produces a flat `boost` score per match, independent of field length or term frequency, which is exactly what is needed to cluster all synonym-matched products at the same rank.
* **Why magnitude `1_000_000`**: Lucene/OpenSearch scores are unbounded floats, but Shopware's base term query scores realistically sit below ~10K (the product search config ranking weights are single-to-low-thousands). Picking `1M` for the top clause guarantees the cluster dominates any realistic base score while preserving a strict ordering across the remaining clauses via the descending magnitudes.

## 6. Testing Notes
Verify the synthesis and ranking behaviour end-to-end:
```bash
# 1. Apply the changed analyzer config (requires a fresh index, not just cache clear).
#    The analyzer change touches index-time settings, so a full reindex is needed.
php bin/console cache:clear
php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias

# 2. Sanity-check analyzers visually (optional):
php bin/console es:test:analyzer "WC Papier"
# Expected: tokens include "wc-papier" (single condensed token) when using
# sw_german_analyzer or topdata_delimiter_analyzer.

# 3. Debug score breakdown on the storefront search for the multi-word synonym:
php bin/console topdata:es-hacks:debug-search "WC Papier"
# Expected: top hits show constant-score contribution of ~1_000_000 for every
# product where name.search analyzer emits "wc-papier"; all such products
# crowd to the top with identical scores, followed by delimiter (200_000)
# boosters, then wildcards (15_000), then prefix (1_100), then Shopware base.

# 4. Manual storefront check:
#   - Search "WC Papier" -> WC-Papier products at #1 and #2, Papierhandtücher below.
#   - Search "Klopapier" -> still finds WC-Papier (regression check).
#   - Search "WC-Papier" -> still finds WC-Papier (hyphenated form).

# 5. Verify unrelated multi-token queries are NOT broken (regression check):
#   - "Papierhandtücher" -> Papierhandtücher products top.
#   - "Spender" -> Spender products top (single-token, no synonym).
```

## 7. Usage Examples
```bash
# Apply the config and reindex (mandatory after this change):
php bin/console cache:clear
php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias

# Inspect the new analyzer chain in the live index:
curl -s 'http://<ES_HOST>/<INDEX_PREFIX>_product/_settings?filter_path=*.settings.analysis' | jq

# Confirm a synonym rule is registered (must be re-imported after changes to synonyms):
php bin/console topdata:es-hacks:list-synonyms --filter wc
```

## 8. Documentation Updates
* No changes to `AGENTS.md`, `README.md`, or `_ai/technical_decisions/`. The existing ADR (`260604_0000__use_orm_for_querying_raw_sql_for_upsert.md`) was not affected.
* An existing in-code observation worth recording: any future edit to the synonym analyzer chain requires a full `es:reset && es:index` again (this was already true before, but is now also true for the `.search` analyzers, which are used in every product search).

## 9. Next Steps (optional)
* **Strict-filtering option**: an alternative future direction would be to decorate `ProductSearchQueryBuilder` (or `AbstractProductSearchQueryBuilder`) to detect when a synonym expansion has occurred and demote or remove pure single-token prefix matches from the base query, so the result list is exclusively synonym-relevant rather than just rank-sorted.
* **Configurable boosts**: the magnitudes are hard-coded for now; if different sales channels need different ranking behaviour they should be moved to `systemConfigService` keys in `config.xml`.
* **Synonym refresh workflow**: add a one-shot console command (`topdata:es-hacks:apply-synonyms`) that atomically performs `cache:clear && es:reset && es:index --no-queue && es:create:alias`, since the "synonyms changed → must rebuild index" workflow is now even more impactful (also re-configures the `.search` analyzers used in every product query).