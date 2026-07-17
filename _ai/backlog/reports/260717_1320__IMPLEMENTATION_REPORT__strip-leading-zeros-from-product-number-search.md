---
filename: "_ai/backlog/reports/260717_1320__IMPLEMENTATION_REPORT__strip-leading-zeros-from-product-number-search.md"
title: "Report: Strip Leading Zeros from Product Number in Elasticsearch Search"
createdAt: 2026-07-17 17:00
updatedAt: 2026-07-17 17:00
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesModified: 2
filesCreated: 0
filesDeleted: 0
tags: [elasticsearch, shopware6, product-number, search, leading-zeros]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Strip Leading Zeros from Product Number in Elasticsearch Search

## 1. Summary

Added leading-zero stripping to the product-number search logic in `ElasticsearchSearchSubscriber`. When the search term is purely numeric (e.g. `"4000"`), leading zeros are stripped in PHP and a `WildcardQuery` with suffix pattern `*{stripped}` is issued on the `productNumber` keyword field. This catches products with leading-zero article numbers (e.g. `"004000"`) without requiring any index-time mapping changes or re-indexing.

## 2. Changes

### File Modified: `src/Subscriber/ElasticsearchSearchSubscriber.php`

Added a `ctype_digit` guard + `ltrim` block (8 lines) after the existing exact-match `TermQuery` on `productNumber`:

```php
if (ctype_digit($lowerTerm)) {
    $stripped = ltrim($lowerTerm, '0');
    if ($stripped !== '') {
        $search->addQuery(
            new WildcardQuery('productNumber', sprintf('*%s', $stripped), ['boost' => 1_500_000.0]),
            BoolQuery::SHOULD
        );
    }
}
```

**Edge case handling:**

| Input | `ctype_digit` | `ltrim(, '0')` | Wildcard pattern | Effect |
|-------|---------------|----------------|------------------|--------|
| `"4000"` | true | `"4000"` | `*4000` | Matches `"004000"`, `"4000"` |
| `"004000"` | true | `"4000"` | `*4000` | Matches `"004000"`, `"4000"` |
| `"0"` or `"00"` | true | `""` | skipped | No query emitted |
| `"ABC123"` | false | — | skipped | Name-based queries only |
| `"4000WD/F"` | false | — | skipped | Name-based queries only |

Also updated the class-level PHPDoc to document the boost hierarchy (now 7 tiers) and the leading-zero rationale.

### File Modified: `AGENTS.md`

Updated the "Query boosting" bullet to reflect current boost values and `ConstantScoreQuery` usage. Added a new "Product number search" bullet documenting the exact-match `TermQuery` and leading-zero suffix `WildcardQuery`.

## 3. Verification

- PHP syntax check: **passed**
- No index changes needed — search-time only
- Functional edge cases verified by code review: empty stripped string, non-numeric terms, leading-zero variants

## 4. Files Changed

| File | Action | Summary |
|------|--------|---------|
| `src/Subscriber/ElasticsearchSearchSubscriber.php` | MODIFY | Added leading-zero stripping block after TermQuery, updated PHPDoc |
| `AGENTS.md` | MODIFY | Updated query boosting bullet, added product-number search doc |
