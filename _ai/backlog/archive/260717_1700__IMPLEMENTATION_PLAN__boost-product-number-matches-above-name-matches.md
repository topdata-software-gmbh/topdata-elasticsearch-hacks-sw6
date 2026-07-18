---
filename: "_ai/backlog/active/260717_1700__IMPLEMENTATION_PLAN__boost-product-number-matches-above-name-matches.md"
title: "Boost Product Number Exact/Substring Matches Above Name Matches in Elasticsearch Search"
createdAt: 2026-07-17 17:00
createdBy: AI [deepseek-v4-pro]
updatedAt: 2026-07-17 17:00
updatedBy: AI [deepseek-v4-pro]
status: completed
completedAt: 2026-07-17 16:39
priority: critical
tags: [elasticsearch, search-ranking, product-number, scoring, boost]
project: topdata-elasticsearch-hacks-sw6
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Boost Product Number Exact/Substring Matches Above Name Matches

## 1. Problem Statement

Recent commit `1fb0a0d` (`fix: wrap synonym boost queries in constant_score and fix analyzer chain order`) fixed synonym matching by wrapping name-field queries in `ConstantScoreQuery` with very large boosts (1,000,000 for match_phrase, 500,000 for match AND, 200,000 for delimiter). This successfully elevated synonym-matched products (e.g., "WC Papier" → "WC-Papier") to the top of search results.

**However**, the `ElasticsearchSearchSubscriber` only boosts queries on the **product name** field. There are zero boost queries targeting the **`productNumber`** field. As a result, a search for `"4000"` produces:

1. **Scosche magicPACK Powerbank 4000 mAh** — matches via name-field boosts (contains "4000" in name → gets 1M+500K+15K ≈ 1.5M boost)
2. **COLOP 4000WD/F** — only gets Shopware's base query score on `productNumber.search` (ranking weight: 1000), no custom boost
3. **COLOP 4000WD/I** — same
4. **COLOP 4000WD/D** — same
5. **EDELWEISS WC-Papier** — boosted by name-field synonym matching

The exact product number matches (positions 2–4) are outranked by a product that merely has `"4000"` in its name. The product with an exact article number match should always appear first.

## 2. Executive Summary

Add two new `SHOULD` boost queries to `ElasticsearchSearchSubscriber` targeting the `productNumber` field with constant scores **higher than** the existing name-field boosts, applied **outside** the language-ID loop (since `productNumber` is language-agnostic). This ensures that any product whose product number contains the search term outranks products that only match in the name.

### Boost hierarchy (after fix):

| Rank | Query | Field | Effective Boost |
|------|-------|-------|-----------------|
| 1st | Wildcard contains | `productNumber` | 2,000,000 |
| 2nd | Prefix starts-with | `productNumber` | 1,800,000 |
| 3rd | ConstantScore MatchPhrase | `name.*.search` | 1,000,000 |
| 4th | ConstantScore Match AND | `name.*.search` | 500,000 |
| 5th | ConstantScore Match AND | `name.*.delimiter` | 200,000 |
| 6th | Wildcard substring | `name.*` | 15,000 |
| 7th | Prefix | `name.*` | 1,100 |

## 3. Project Environment

- **Project Name**: topdata-elasticsearch-hacks-sw6 (SW6.7 Plugin)
- **Backend root**: `src`
- **PHP Version**: 8.2 / 8.3 / 8.4
- **Elasticsearch field**: `productNumber` (keyword, `sw_lowercase_normalizer`)
  - Sub-field: `productNumber.search` (text, `sw_whitespace_analyzer`)
  - Sub-field: `productNumber.ngram` (text, `sw_ngram_analyzer`)

## 4. Phase 1: Add Product Number Boost Queries

### Objective

Add WildcardQuery and PrefixQuery targeting the `productNumber` keyword field with boosts higher than the name-field queries, placed outside the language-ID loop.

### Implementation

**File**: `src/Subscriber/ElasticsearchSearchSubscriber.php`

[MODIFY]

Add two product-number boost queries between the guard clauses and the language-ID loop:

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\Compound\ConstantScoreQuery;
use OpenSearchDSL\Query\FullText\MatchPhraseQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use OpenSearchDSL\Query\TermLevel\WildcardQuery;
use Shopware\Elasticsearch\Framework\DataAbstractionLayer\Event\ElasticsearchEntitySearcherSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ElasticsearchSearchSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchEntitySearcherSearchEvent::class => 'onProductSearchBeforeQuery',
        ];
    }

    public function onProductSearchBeforeQuery(ElasticsearchEntitySearcherSearchEvent $event): void
    {
        if ($event->getDefinition()->getEntityName() !== 'product') {
            return;
        }

        $term = $event->getCriteria()->getTerm();

        if ($term === null || $term === '' || mb_strlen($term) < 2) {
            return;
        }

        $search = $event->getSearch();
        $lowerTerm = mb_strtolower($term);
        $languageIdChain = $event->getContext()->getLanguageIdChain();

        // ── Product number matching (language-agnostic, highest priority) ──
        // WildcardQuery and PrefixQuery already produce a constant score in
        // Lucene (no tf/idf or length normalisation), so wrapping them in
        // constant_score is unnecessary. Boosts are set higher than the name-
        // field ConstantScoreQuery boosts (1,000,000) so that products whose
        // product number contains the search term always rank above products
        // that only match via the name field.
        $search->addQuery(
            new WildcardQuery('productNumber', sprintf('*%s*', $lowerTerm), ['boost' => 2_000_000.0]),
            BoolQuery::SHOULD
        );

        $search->addQuery(
            new PrefixQuery('productNumber', $lowerTerm, ['boost' => 1_800_000.0]),
            BoolQuery::SHOULD
        );

        foreach ($languageIdChain as $languageId) {
            $analyzedField = sprintf('name.%s.search', $languageId);
            $delimiterField = sprintf('name.%s.delimiter', $languageId);
            $keywordField = sprintf('name.%s', $languageId);

            // The boost queries below are added as SHOULD clauses to the boolean
            // search that Shopware builds in ElasticsearchHelper::addTerm. That
            // base (MUST) query keeps matching loosely relevant documents (e.g.
            // prefix/ngram matches on individual query tokens), so docs that do
            // NOT match our boost clauses can still be returned - they just don't
            // get the additive score. Previously the boost magnitudes were small
            // (30, 20, ...) and Lucene length-normalised the inner relevance,
            // so a short-name "WC-Papier" product would beat a longer-name one
            // for the same boost, while both could be overtaken by unrelated
            // products that scored well through the base query (Papierhandtuecher
            // matched "papier" via prefix -> large ranking-weighted base score).
            //
            // Mitigation: wrap the boost clauses in a constant_score query, which
            // yields a CONSTANT score for every matching document (independent of
            // field length and term frequency). This guarantees that all products
            // whose analyzed tokens match the synonym-expanded query (e.g. both
            // "BULKYSOFT WC-Papier" and "EDELWEISS WC-Papier Classic") receive an
            // IDENTICAL large additive score, so they crowd to the top regardless
            // of their name length, and unrelated base-only matches rank below.
            // Magnitudes are chosen large enough to dominate realistic base scores
            // (Shopware weights each search field by its `ranking` config value,
            // which is typically in the hundreds-to-thousands range).
            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchPhraseQuery($analyzedField, $lowerTerm),
                    ['boost' => 1_000_000.0]
                ),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($analyzedField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 500_000.0]
                ),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new ConstantScoreQuery(
                    new MatchQuery($delimiterField, $lowerTerm, ['operator' => 'and']),
                    ['boost' => 200_000.0]
                ),
                BoolQuery::SHOULD
            );

            // Wildcard and prefix queries already produce a constant score
            // (Lucene does not apply tf/idf or length normalisation to them),
            // so wrapping them in constant_score is unnecessary. They are kept
            // as a secondary ordering hint for products whose keyword-typed
            // name field contains the search term as a substring / prefix.
            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('* %s *', $lowerTerm), ['boost' => 15_000.0]),
                BoolQuery::SHOULD
            );
            $search->addQuery(
                new WildcardQuery($keywordField, sprintf('%s *', $lowerTerm), ['boost' => 15_000.0]),
                BoolQuery::SHOULD
            );

            $search->addQuery(
                new PrefixQuery($keywordField, $lowerTerm, ['boost' => 1_100.0]),
                BoolQuery::SHOULD
            );
        }
    }
}
```

### Rationale for Boost Values

- **WildcardQuery `*4000*` on `productNumber`** (2,000,000): Catches any product number containing the search term. Higher than name match_phrase (1,000,000) to guarantee product-number matches outrank name-only matches.
- **PrefixQuery `4000*` on `productNumber`** (1,800,000): Additional boost for products whose number starts with the search term. Lower than wildcard to avoid double-boosting starts-with matches over contains-only matches by too much. A product number like "4000WD/F" gets both 2M + 1.8M = 3.8M, while a product like "PWR-4000" gets only 2M — this gives a slight edge to leading-digit matches, which is desirable.

### Why Outside the Language Loop

The `productNumber` field is not language-translated. Placing the queries outside `foreach ($languageIdChain ...)` avoids redundant duplicate query additions per language and makes the intent clearer.

### Why Not `ConstantScoreQuery` Wrapper

`WildcardQuery` and `PrefixQuery` inherently produce constant scores in Lucene (no tf/idf or field-length normalization is applied to term-level wildcard/prefix queries). Wrapping them in `ConstantScoreQuery` would be redundant and adds unnecessary nesting to the ES query DSL.

## 5. Phase 2: Verification

### Objective

Verify that searching for `"4000"` places products with `"4000"` in their product number above products with `"4000"` only in their name.

### Steps

1. Clear cache: `php bin/console cache:clear`
2. Ensure ES is running and indices are current
3. Use the debug search command to inspect scoring:
   ```bash
   php bin/console topdata:es-hacks:debug-search "4000"
   ```
4. Verify the order:
   - Products like **COLOP 4000WD/F**, **COLOP 4000WD/I**, **COLOP 4000WD/D** should appear in positions 1–3
   - **Scosche magicPACK Powerbank 4000 mAh** should appear below them
5. Also test with `"WC Papier"` to confirm synonym matching still works correctly (regression check)
6. Test other numeric searches: `"40"`, `"1000"` to verify broader product-number matching works

### Expected ES Query Shape (for "4000")

```json
{
  "query": {
    "bool": {
      "must": [
        { "...": "base Shopware query (MUST clause) ..." }
      ],
      "should": [
        { "wildcard": { "productNumber": { "value": "*4000*", "boost": 2000000 } } },
        { "prefix": { "productNumber": { "value": "4000", "boost": 1800000 } } },
        { "constant_score": { "filter": { "match_phrase": { "name.<lang>.search": "4000" } }, "boost": 1000000 } },
        { "constant_score": { "filter": { "match": { "name.<lang>.search": { "query": "4000", "operator": "and" } } }, "boost": 500000 } },
        { "constant_score": { "filter": { "match": { "name.<lang>.delimiter": { "query": "4000", "operator": "and" } } }, "boost": 200000 } },
        { "wildcard": { "name.<lang>": { "value": "* 4000 *", "boost": 15000 } } },
        { "wildcard": { "name.<lang>": { "value": "4000 *", "boost": 15000 } } },
        { "prefix": { "name.<lang>": { "value": "4000", "boost": 1100 } } }
      ]
    }
  }
}
```

## 6. Phase 3: Report

Write an implementation report to `_ai/backlog/reports/260717_1700__IMPLEMENTATION_REPORT__boost-product-number-matches-above-name-matches.md`.
