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

## [2026-07-17] - Product Number Search Boosting & Leading-Zero Matching

### Context
Implement two related features in `ElasticsearchSearchSubscriber` and `ProductElasticsearchDefinitionDecorator`:
1. Boost product-number exact matches above name-only matches in search results
2. Make searching "004000" find a product with SKU "4000" (leading-zero stripping)

### Challenge 1: WildcardQuery False Positives on Product Number
An initial implementation used `WildcardQuery('productNumber', '*4000*')` (boost 2M) and `PrefixQuery('productNumber', '4000')` (boost 1.8M) to catch any product number containing the search term.

- **Problem:** Searching "4000" returned the correct product first, but the second result was a product with SKU "40001" — a different article number that merely contains "4000" as a substring. The wildcard `*4000*` was too broad.
- **Fix:** Replaced both WildcardQuery and PrefixQuery with a single `TermQuery('productNumber', '4000', ['boost' => 2_000_000.0])`. A `TermQuery` on the keyword field (which uses `sw_lowercase_normalizer`) performs an exact, case-insensitive but whole-value match. SKU "40001" no longer matches.

- **Lesson:** `WildcardQuery` with `*term*` on a keyword field matches ANY product number containing the term as a substring — including completely different article numbers. For exact SKU matching, use `TermQuery` on the keyword field.
- **Lesson:** The `productNumber` field is a keyword field with `sw_lowercase_normalizer`, so `TermQuery` comparisons are case-insensitive but require the entire value to match exactly.

### Challenge 2: SHOULD Clauses Cannot Add Documents Missing from the MUST Clause
The leading-zero stripping feature (`ltrim($term, '0')`) added a `TermQuery` for the stripped value as a `SHOULD` clause in `ElasticsearchSearchSubscriber`, expecting searching "004000" would boost the product with SKU "4000".

- **Problem:** Searching "004000" did **not** return the product with SKU "4000" at all. The `SHOULD` clause could only boost documents that the `MUST` clause (Shopware's base `query_string` query) already returned. When the base query analyzed "004000" to token `["004000"]`, it didn't match the `productNumber.search` token `["4000"]`, so the product with SKU "4000" never entered the result set. The `SHOULD` boost was completely useless — it had no document to score.

  This was not immediately obvious; the WildcardQuery variant (`*4000`) also failed for the same reason. Even when both a TermQuery and a WildcardQuery SHOULD boost were added, neither helped because the document wasn't returned by the MUST clause.

- **Fix:** Modified `ProductElasticsearchDefinitionDecorator::buildTermQuery()` — the method whose return value becomes the **MUST clause** (via `ElasticsearchHelper::addTerm` → `$search->addQuery($query)` which defaults to `MUST`). For purely numeric terms with leading zeros, wrap the original term query and a `TermQuery('productNumber', $stripped)` in a `BoolQuery` with both as `SHOULD` and `minimum_should_match: 1`:

  ```php
  $wrapper = new BoolQuery();
  $wrapper->add($query, BoolQuery::SHOULD);
  $wrapper->add(new TermQuery('productNumber', $stripped), BoolQuery::SHOULD);
  $wrapper->addParameter('minimum_should_match', 1);
  return $wrapper;
  ```

  Since this `BoolQuery` is placed in the `MUST` clause, a document now matches if EITHER the original Shopware query OR the exact stripped-SKU `TermQuery` matches. The document enters the result set via the `TermQuery`, and the `SHOULD` boost in `ElasticsearchSearchSubscriber` (1.5M) then pushes it to the top.

- **Lesson:** **`SHOULD` clauses in a `bool` query with a `MUST` clause can only raise the score of documents the `MUST` clause already returned. They CANNOT introduce new documents into the result set.** If a document doesn't match the `MUST` clause, no amount of `SHOULD` boosting will make it appear.
- **Lesson:** To make a document match that the base query would otherwise miss, you must modify the query that goes into the `MUST` clause — typically by wrapping it in a `bool.should` with `minimum_should_match: 1` containing alternative match paths.
- **Lesson:** `ElasticsearchHelper::addTerm()` calls `$search->addQuery($query)` which defaults to `BoolQuery::MUST`. So whatever `buildTermQuery()` returns becomes a MUST clause. Decorating `buildTermQuery` is the correct extension point to broaden document matching.
- **Lesson:** The matching layer (does the document match at all) and the ranking layer (where does it rank) are separate concerns. The decorator handles matching; the subscriber handles ranking. Both are needed for the feature to work end-to-end.

### Challenge 3: Two-Layer Architecture for Leading-Zero Search
The final architecture required two coordinated changes:
1. **Matching layer** (`ProductElasticsearchDefinitionDecorator::buildTermQuery`): wraps the base query with `bool.should` + `minimum_should_match: 1` including a `TermQuery` for the stripped SKU → ensures the document enters the result set.
2. **Ranking layer** (`ElasticsearchSearchSubscriber`): adds a `SHOULD` boost `TermQuery` for the stripped value (1.5M) → ensures the matched product ranks at the top.

- **Lesson:** When a search feature requires both (a) making a document appear that the base query wouldn't return AND (b) ranking it at the top, you need two coordinated changes. The matching fix alone puts the document somewhere in the results; the ranking fix alone can't help a document that doesn't appear.
- **Lesson:** Existing decorators that already override a method (like `buildTermQuery`) are ideal extension points. Adding behavior there avoids needing to introduce new event subscribers or compiler passes.

### Challenge 4: Plan Adaptation When Codebase Has Evolved
An implementation plan (`260717_1320__strip-leading-zeros-from-product-number-search.md`) proposed adding `TermQuery('productNumber', $term, boost 30)`, `WildcardQuery('productNumber', '*{term}*', boost 8)`, and `WildcardQuery('productNumber', '*{stripped}', boost 25)`. But the plan was written before the codebase had:
- `TermQuery` already added (boost 2M) by a previous plan
- `ConstantScoreQuery` wrappers with large boosts

- **Lesson:** Plans become outdated as the codebase evolves. Always read the current file state before applying a plan's code verbatim. Adapt boost values, query types, and placement to match the current architecture.
- **Lesson:** Avoid blindly replacing `TermQuery` with `WildcardQuery` or vice versa based on a stale plan. Each query type has different false-positive characteristics that may conflict with user requirements established in later conversations.

### Key Takeaways

- **`SHOULD` vs `MUST` semantics in Elasticsearch:** `SHOULD` clauses in the presence of a `MUST` clause are purely **scoring** — they never add documents. To broaden document matching, modify the `MUST` clause via `bool.should` + `minimum_should_match: 1`.
- **Exact SKU matching:** Use `TermQuery` on the keyword field (`productNumber`), not `WildcardQuery` with `*term*`. The wildcard catches unwanted substrings like "40001" for "4000".
- **Leading-zero matching is two-layered:** The decorator makes the document match (via `TermQuery` on stripped value in a `bool.should`/`minimum_should_match: 1` wrapper); the subscriber ranks it (via high-boost `SHOULD` `TermQuery`).
- **`buildTermQuery` is the right extension point for matching:** Its return value becomes the `MUST` clause. Decorating it lets you add alternative match paths without restructuring the entire search.
- **Cache vs re-index:** Pure query-time changes (subscriber/decorator PHP code) need `php bin/console cache:clear` only. No `es:reset`/`es:index` is required unless the ES mapping changes.
- **Plan staleness:** Always reconcile a plan with the current codebase state before implementing. Boost values, query types, and architecture may have shifted since the plan was written.
