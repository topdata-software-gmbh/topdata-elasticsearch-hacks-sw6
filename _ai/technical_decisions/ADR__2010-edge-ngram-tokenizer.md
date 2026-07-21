# ADR: Switch to Edge N-Gram Tokenizer

**Date:** 2026-07-21
**Status:** Accepted
**Context:** topdata-elasticsearch-hacks-sw6

## Decision

Replace the default `sw_ngram_tokenizer` type from `ngram` (mid-word substring matching) to `edge_ngram` (prefix-only matching) in the Elasticsearch index configuration. The swap is performed dynamically in `ElasticsearchIndexConfigSubscriber::onIndexConfig()` before any synonym processing.

## Rationale

### Why edge n-gram
- **Reduced false positives:** Standard n-gram matches any substring position (e.g. "123" matches "91239" and "812300"), creating excessive noise. Edge n-gram only matches from the start of a token, which aligns with how customers naturally type search queries.
- **Smaller index footprint:** Edge n-gram produces far fewer tokens per field than full n-gram, reducing Elasticsearch heap memory and disk usage.
- **Better Swiss multilingual behavior:** French and German search queries benefit from prefix-only matching since users type left-to-right, making mid-word syllable matches undesirable.

### Implementation approach
- The tokenizer type is mutated on the `ElasticsearchIndexConfigEvent` — no changes to individual field mappings are needed.
- The `min_gram` (4) and `max_gram` (5) settings are preserved from the default configuration.
- The change executes on every index build, independent of whether synonym rules exist.

## Consequences

### Positive
- Lower false-positive rate in search results for all languages (DE, FR, EN)
- Reduced indexing time and Elasticsearch resource consumption
- Cleaner search results that better match user intent

### Negative
- Products whose names do not share a prefix with the query term will no longer match via the ngram sub-field (e.g. "rot" will not match "Farot"). For such cases, other analyzers (whitespace, language-specific) still provide fallback matching.

## Alternatives Considered

| Approach | Pros | Cons |
|----------|------|------|
| **Edge n-gram (chosen)** | Prefix-only matching, lower noise, smaller index | Loses mid-word substring matches |
| Keep standard n-gram | Mid-word matching preserved | Excessive noise, large index, false positives |
| Remove ngram sub-field entirely | Maximal index savings | Loses all partial matching capability |

## Related Decisions

`ADR__2000-scoped-single-table-synonyms.md`
