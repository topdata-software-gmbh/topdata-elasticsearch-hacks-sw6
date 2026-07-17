# Lessons Learned

## [2026-07-17] - Fix Synonym List "Loads Forever" + Missing `updated_at` Column

### Context
Debugging the synonym list admin page in the Shopware 6.7 plugin `topdata-elasticsearch-hacks-sw6`. The page rendered the table skeleton but never populated data ("loads forever"). After fixing that, a SQL error appeared for a missing `updated_at` column.

### Challenge 1: Admin Component "Loads Forever"
The synonym list component used the `listing` mixin but never implemented `getList()`.

- **Root cause:** `isLoading` initialized to `true` and never set to `false`. The mixin's stub `getList()` is a no-op, so the skeleton overlay stayed forever.
- **Fix:** Added `items`, `sortBy`, `sortDirection`, `limit` to `data()`, implemented `getList()`, `onPageChange()`, `onSortColumn()`, and a `mounted()` hook. Template was missing `v-if="items"`, `:dataSource="items"`, and pagination/sort event handlers.
- **Reference pattern:** The working zero-search-list in the same plugin (`topdata-es-zero-search`) had the correct implementation — always copy from a working sibling module.

### Challenge 2: Admin Build Not Picking Up Plugin
Running `bin/build-administration.sh` didn't rebuild the plugin's JS.

- **Root cause:** The plugin wasn't listed in `var/plugins.json`. The `bin/console bundle:dump` command (run at the start of the build script) failed due to database unavailability and only dumped core bundles, omitting custom plugins.
- **Fix:** Manually added the plugin entry to `var/plugins.json`, then ran only the plugin build step (`VITE_MODE=production npx ts-node -T build/plugins.vite.ts`). The `basePath` must point to the directory containing `Resources/`, e.g., `custom/plugins/topdata-elasticsearch-hacks-sw6/src/` (not the plugin root).

### Challenge 3: Missing `updated_at` Column in Synonym Table
After the admin page loaded, it crashed with: `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'topdata_es_synonym.updated_at'`.

- **Root cause:** Shopware 6.7's `EntityDefinition` base class (`EntityDefinition.php:447`) auto-adds `UpdatedAtField` with `ApiAware` flag to **all** entity definitions. The DAL generates SELECT queries including `updated_at` for every read. The migration `Migration1752590000` that adds this column hadn't been executed.
- **Fix:** Run `php bin/console database:migrate --all TopdataElasticsearchHacksSW6` — but from the Docker container, not the host CLI (host can't resolve `focus-mariadb` hostname).
- **Key insight:** Shopware 6.7 adds `UpdatedAtField` implicitly even if you don't define it in your entity definition.

### Key Takeaways

- **Entity definitions in SW 6.7:** The `EntityDefinition` base class auto-adds `UpdatedAtField` and `CreatedAtField` to all definitions. You must ensure your DB table has `updated_at DATETIME(3) NULL` column or the DAL queries will fail.
- **Admin listing components:** When using the `listing` mixin, you **must** implement `getList()` yourself — the mixin only provides a no-op stub. Copy the pattern from a working sibling component.
- **Admin builds:** If `bin/build-administration.sh` doesn't pick up your plugin, check `var/plugins.json`. Add the plugin entry manually if `bundle:dump` fails due to DB issues.
- **Docker commands:** For DB-dependent Shopware CLI commands (migrations, cache:clear, etc.), use `docker exec <container> php /www/bin/console ...` — the host CLI can't resolve container hostnames.

## [2026-07-17] - Fix Multi-Word Synonym Search ("WC Papier" => "WC-Papier")

### Context
A user created a synonym rule `WC Papier => WC-Papier` (multi-word term mapping to a hyphenated product name). The synonym rule was stored in `topdata_es_synonym` and correctly injected into the Elasticsearch index config via `ElasticsearchIndexConfigSubscriber`. However, searching for "WC Papier" didn't list "WC-Papier" products at the top.

### Challenge 1: Multi-Word Synonym Rule Never Fires
The `topdata_synonym_filter` was prepended (`array_unshift`) to the `topdata_delimiter_analyzer` filter chain, resulting in order `[topdata_synonym_filter, topdata_word_delimiter, lowercase]`. The Elasticsearch `synonym` filter is **case-sensitive by default**. The standard tokenizer produces uppercase tokens `["WC", "Papier"]`, while the stored rules are lowercased (`wc papier => wc-papier`). Case-sensitive comparison `"WC" != "wc"` → rule never fires.

**Fix:** Reorder to `[lowercase, topdata_synonym_filter, topdata_word_delimiter]`, and add `ignore_case: true` to the synonym filter config as safety net.

- **Lesson:** Lowercase must always precede the synonym filter when rules are stored lowercase. `ignore_case` is a backup but not a replacement — the explicit lowercase stage ensures consistent token case before the filter.
- **Lesson:** `array_unshift` prepends; for an analyzer where order matters (synonym before vs after lowercase), always explicitly set the filter array.

### Challenge 2: Synonyms Only in delimiter Analyzer, Not in `.search` Analyzers
The subscriber only injected `topdata_synonym_filter` into `topdata_delimiter_analyzer` (used by the `.delimiter` sub-field). But Shopware's **product search queries target `name.{lang}.search`** via the `sw_german_analyzer` / `sw_whitespace_analyzer` / `sw_english_analyzer`. The high-boost match_phrase and match-AND queries in `ElasticsearchSearchSubscriber` operate on the `.search` field, so the synonym expansion never helped those 30× / 20× boost clauses — making the synonym less effective for ranking.

**Fix:** Inject `topdata_synonym_filter` into `sw_whitespace_analyzer`, `sw_german_analyzer`, and `sw_english_analyzer` right after the `lowercase` filter (and before any stop-word filter).

- **Lesson:** Shopware's `.search` field analyzers are defined in `elasticsearch.yaml` under the `analysis` node and are language-mapped via `language_analyzer_mapping` (e.g., `de` → `sw_german_analyzer`). Any custom token filter that should affect user-facing product search must be injected into these analyzers, not just into a plugin-specific sub-field analyzer.

### Challenge 3: Unrelated Results Mixed With Top Results
After the synonym fix, "WC-Papier" was at position #1, but unrelated "Papierhandtücher" products ranked #2-9 while a second "WC-Papier Classic" product fell to #10. Both WC-Papier products received the same `MatchPhraseQuery` boost (30×), but the score from a `MatchPhraseQuery` is `boost × Lucene's length-normalised relevance`. "WC-Papier Classic" (longer name) got a lower relevance score than "BULKYSOFT WC-Papier" (shorter name), so the 30× boost was insufficient.

Shopware's base term query (`ElasticsearchHelper::addTerm`) goes into the `MUST` clause of the bool query, and our boost queries are `SHOULD` clauses. The `MUST` clause (built by `ProductSearchQueryBuilder` / `TokenQueryBuilder`) matches unrelated products too (via prefix/ngram sub-queries), and these receive non-trivial scores from the field `ranking` weights (typically 1,000–10,000 per field). With plain `boost` on a `MatchPhrase`, the total additive boost for the long-named WC-Papier product was dwarfed by the base score of Papierhandtücher products.

**Fix:** Wrap the three text-field boost clauses in `ConstantScoreQuery { filter: <clause>, boost: 1_000_000 }`. `ConstantScoreQuery` produces a flat constant score for every match, independent of field length and term frequency. All synonym-matched products receive an identical massive additive score, clustering them at the top. Magnitudes: match_phrase 1M, match_AND 500K, delimiter_AND 200K, wildcards 15K, prefix 1.1K.

- **Lesson:** `MatchPhraseQuery(['boost' => 30])` does **not** give a constant +30 score — it multiplies 30 by a length-normalised relevance score. Documents of different field lengths get different boosts for the same match. Use `ConstantScoreQuery` when you need a deterministic, field-length-independent boost.
- **Lesson:** `BoolQuery::SHOULD` clauses affect **scoring only**, not filtering. The `MUST` clause determines which documents are returned. To truly control ordering, the boost magnitude must exceed the `MUST` clause's maximum possible score.
- **Lesson:** The `OpenSearchDSL\Query\Compound\ConstantScoreQuery` class exists in `vendor/shyim/opensearch-php-dsl/` and constructs the `constant_score { filter, boost }` ES DSL syntax.

### Challenge 4: Understanding Shopware's ES Query Pipeline
The event triggering order in `ElasticsearchEntitySearcher::search`:
1. `createSearch()` sets up filters, POST filters, `addQueries()` (Score queries → `SHOULD`), sorting, then `addTerm()` (Shopware base query → default `MUST` via `addQuery`)
2. `ElasticsearchEntitySearcherSearchEvent` is dispatched after `createSearch()` returns, so when our subscriber runs, the base search already has the MUST query.
3. Our subscriber calls `$search->addQuery(..., BoolQuery::SHOULD)` which adds to the **same** BoolQuery's `SHOULD` list.
4. The final ES query is `bool { must: [base term query], should: [our boost queries] }`.

- **Lesson:** When the base term query returns many loosely-matched docs (e.g., prefix/ngram matches on individual tokens), our boost queries must use high magnitudes to dominate for the specific docs we care about.

### Key Takeaways

- **Synonym filter chain order:** Always place `lowercase` **before** `synonym` when rules are stored lowercased, or use `ignore_case: true`. A multi-word LHS rule like `wc papier => wc-papier` requires the prefix tokens to be exact case matches when the synonym filter processes them.
- **`.search` vs `.delimiter`:** Shopware query targets `.search` field with language-specific analyzers (`sw_german_analyzer` etc.). A custom filter only injected into the plugin's `.delimiter` analyzer won't affect the high-boost match clauses. If the goal is ranking, the filter must also be in the `.search` analyzers.
- **`ConstantScoreQuery` for fixed boosts:** If all documents matching a clause should get the same score boost (regardless of field length), wrap the clause in `ConstantScoreQuery { filter: clause, boost: N }`. This is critical when there are competing matches with varying field lengths.
- **Score magnitude budget:** Shopware's product search config `ranking` values are stored in `product_search_config_field` and passed to `SearchFieldConfig`. They become the `boost` parameter on `DisMaxQuery`, weighting each field's contribution. Typical values range from ~500 to 10,000. Custom boost clauses must out-rank these to reliably reorder results.
- **DB table consistency:** Shopware 6.7 entity definitions auto-add `UpdatedAtField` even if not defined in the entity class. Ensure `updated_at` column exists (e.g., via migration) to avoid DAL query failures.
