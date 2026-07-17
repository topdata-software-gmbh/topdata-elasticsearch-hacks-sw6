# Elasticsearch Query Structure

How the product search query is built and how MUST/SHOULD/minimum_should_match work.

## Query Building Pipeline

```
User types "004000"
       │
       ▼
ProductSearchCriteriaEvent     ← Criteria can be modified here (SearchCriteriaSubscriber)
       │
       ▼
ElasticsearchHelper::addTerm() ← calls buildTermQuery() which goes through the decorator
       │
       ├─ ProductElasticsearchDefinitionDecorator::buildTermQuery()  ← MAY wrap the query (see below)
       │      │
       │      └─ ProductSearchQueryBuilder::build()  ← builds the actual term query (query_string, dismax, etc.)
       │
       └─ $search->addQuery($result)  ← added as BoolQuery::MUST
       │
       ▼
ElasticsearchEntitySearcherSearchEvent  ← our subscriber modifies the Search
       │
       └─ ElasticsearchSearchSubscriber  ← adds SHOULD boosts (TermQuery, ConstantScoreQuery, WildcardQuery, PrefixQuery)
       │
       ▼
Search::toArray() → JSON → sent to Elasticsearch
```

## The Final ES Query Structure

For a search like `"004000"`, the resulting ES JSON looks like this:

```json
{
  "bool": {
    "must": [
      {
        "bool": {
          "should": [
            { "query_string": { "query": "004000", "fields": ["name.*.search^1000", "productNumber.search^1000", ...] } },
            { "term": { "productNumber": { "value": "4000" } } }
          ],
          "minimum_should_match": 1
        }
      }
    ],
    "should": [
      { "term": { "productNumber": { "value": "004000", "boost": 2000000 } } },
      { "term": { "productNumber": { "value": "4000", "boost": 1500000 } } },
      { "constant_score": { "filter": { "match_phrase": { "name.<lang>.search": "004000" } }, "boost": 1000000 } },
      { "constant_score": { "filter": { "match": { "name.<lang>.search": { "query": "004000", "operator": "and" } } }, "boost": 500000 } },
      { "constant_score": { "filter": { "match": { "name.<lang>.delimiter": { "query": "004000", "operator": "and" } } }, "boost": 200000 } },
      { "wildcard": { "name.<lang>": { "value": "* 004000 *", "boost": 15000 } } },
      { "prefix": { "name.<lang>": { "value": "004000", "boost": 1100 } } }
    ]
  }
}
```

## MUST vs SHOULD Semantics

| Clause | Meaning | Effect on Results |
|--------|---------|-------------------|
| **MUST** | Document **must** match every `must` clause | Failing `must` = document **excluded**. No amount of `should` scoring can bring it back. |
| **SHOULD** | Document **should** match the `should` clause | Matching `should` = extra **score** added. Failing `should` = still returned (as long as `must` matches), just with lower score. |
| **FILTER** | Like `must` but no scoring | Same matching semantics as `must`, but doesn't affect `_score`. Used for caching-friendly filters. |
| **MUST_NOT** | Document **must not** match | Excludes matching documents entirely. |

### Key Rule

> In a `bool` query with at least one `MUST` or `FILTER` clause, `SHOULD` clauses are **purely scoring** — they add score to documents the `must` already returned but **never introduce new documents** into the result set.

This means: if the `must` clause doesn't return a document, no `should` boost can make it appear.

## minimum_should_match

When a `bool` query has **only** `should` clauses (no `must`, no `filter`), ES automatically requires at least one `should` to match. Setting `minimum_should_match` explicitly makes the behavior clear.

```json
{
  "bool": {
    "should": [
      { "query_string": { "query": "004000", ... } },
      { "term": { "productNumber": "4000" } }
    ],
    "minimum_should_match": 1
  }
}
```

A document matches if it satisfies **at least 1** of the `should` clauses. This is how we broaden the `must` clause to accept documents matching EITHER the original query OR the product number TermQuery.

If `minimum_should_match` were set to `2`, a document would need to match **both** clauses — that would be too restrictive.

## The Leading-Zero Problem (Why the Decorator Is Needed)

When searching `"004000"`:

1. **Without the decorator wrapper:** The `must` clause contains only Shopware's `query_string` for `"004000"`. This analyzes to token `["004000"]` and searches `productNumber.search` (whitespace analyzer), which contains token `["4000"]` for the product with SKU `"4000"`. These don't match, so the product with SKU `"4000"` **never enters the result set**. The subscriber's `should` boosts are useless — there's no document to score.

2. **With the decorator wrapper:** The `must` clause now contains:
   ```
   bool { should: [original query_string, TermQuery(productNumber, "4000")], minimum_should_match: 1 }
   ```
   The product with SKU `"4000"` matches through the `TermQuery` (exact keyword match). Now it's in the result set. The subscriber's `should` TermQuery with boost 1.5M scores it at the top.

## When to Use Each Query Type

| Query Type | Purpose | Constant Score? | Used For |
|------------|---------|-----------------|----------|
| `TermQuery` | Exact match on keyword/numeric field | Yes (inherent) | `productNumber` exact SKU matching |
| `WildcardQuery` | Pattern match (`*`, `?`) on keyword field | Yes (inherent) | Name-field substring matching (spaces prevent false positives) |
| `PrefixQuery` | Prefix match on keyword field | Yes (inherent) | Name-field prefix matching |
| `MatchQuery` | Full-text match on analyzed text field | No (length-normalised) | Used inside `ConstantScoreQuery` wrapper |
| `MatchPhraseQuery` | Exact phrase in analyzed text field | No (length-normalised) | Used inside `ConstantScoreQuery` wrapper |
| `ConstantScoreQuery` | Wraps any query to give flat score | Yes (guaranteed) | Name-field boost queries to avoid length bias |

### Why `ConstantScoreQuery` Wrapping for Name Fields

`MatchQuery` and `MatchPhraseQuery` produce scores that are **multiplied by Lucene's length normalisation**. A product with a short name (e.g., "WC-Papier") gets a higher score for the same `MatchQuery` than a product with a long name (e.g., "BULKYSOFT WC-Papier Classic"). Wrapping them in `ConstantScoreQuery` gives all matching documents the **same** additive score regardless of field length — critical for synonym matching where you want all matches to rank identically.

`TermQuery`, `WildcardQuery`, and `PrefixQuery` are **term-level** queries in Lucene — they inherently produce constant scores (no tf/idf, no length normalisation), so wrapping them in `ConstantScoreQuery` is redundant.

## Component Responsibilities

### ProductElasticsearchDefinitionDecorator
- **Handles MATCHING** (does the document enter the result set?)
- Wraps `buildTermQuery` with alternative match paths when needed
- Only intervenes for purely numeric terms with leading zeros (e.g. `"004000"` → `"4000"`)

### ElasticsearchSearchSubscriber
- **Handles RANKING** (where does the matched document appear?)
- Adds `SHOULD` queries with high boosts
- All boosts are `SHOULD` — they can only score documents that the `MUST` clause already returned
- If the matching layer doesn't bring the document in, the boosts here are useless
