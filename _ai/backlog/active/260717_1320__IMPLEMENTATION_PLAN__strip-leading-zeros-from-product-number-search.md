---
filename: "_ai/backlog/active/260717_1320__IMPLEMENTATION_PLAN__strip-leading-zeros-from-product-number-search.md"
title: "Strip Leading Zeros from Product Number in Elasticsearch Search"
createdAt: 2026-07-17 13:20
updatedAt: 2026-07-17 13:20
status: draft
priority: high
tags: [shopware6, elasticsearch, product-number, search, leading-zeros]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description

Products in the shop have article numbers (product numbers) with **leading zeros**, e.g., `"004000"`. When customers search for the number without leading zeros — e.g., `"4000"` — the product is **not found** or ranks very poorly.

The root cause: the `ElasticsearchSearchSubscriber` (`src/Subscriber/ElasticsearchSearchSubscriber.php:38-71`) only boosts queries against `name.*` fields. There are **no queries** against the `productNumber` field at all. Even if there were, an exact `term` query for `"4000"` would not match a keyword `"004000"`.

## 2. Executive Summary

**No index-time changes needed.** Only the search subscriber is modified:

When the search term is purely numeric (e.g., `"4000"`), the subscriber strips leading zeros in PHP and adds a `WildcardQuery` on the `productNumber` keyword field. The pattern `*{strippedTerm}` (e.g., `*4000`) matches any product number ending with those digits — so both `"004000"` and `"4000"` are found.

Additionally, a `TermQuery` on `productNumber` is always added (for exact keyword hits), and a `WildcardQuery` with `*{term}*` (for partial substring matches).

Only **one file** is modified. No mapping changes, no compiler pass changes, no re-indexing required.

## 3. Project Environment Details

- **Project Name:** SW6.7 Plugin — topdata-elasticsearch-hacks-sw6
- **Plugin Namespace:** `Topdata\TopdataElasticsearchHacksSW6`
- **Backend root:** `src/`
- **PHP Version:** 8.2 / 8.3 / 8.4
- **Symfony Version:** 7.4
- **Shopware Version:** 6.7.*
- **Elasticsearch / OpenSearch:** 7.8+ / 2.x / 3.x
- **Only file to modify:**
  - `src/Subscriber/ElasticsearchSearchSubscriber.php` (73 lines)

---

## 4. Implementation Phases

### Phase 1: Add productNumber queries to `ElasticsearchSearchSubscriber`

**File:** `src/Subscriber/ElasticsearchSearchSubscriber.php`

**Change type:** [MODIFY]

**Import to add** (top of file, after existing imports):

```php
use OpenSearchDSL\Query\TermLevel\TermQuery;
```

**New code to add** — after line 70 (after the end of the `foreach ($languageIdChain ...)` loop), product number queries are **language-independent** so they go **outside** the language loop. Insert after the `}` closing the foreach but before the method's closing `}`:

```php
$search->addQuery(
    new TermQuery('productNumber', $lowerTerm, ['boost' => 30.0]),
    BoolQuery::SHOULD
);

$search->addQuery(
    new WildcardQuery('productNumber', sprintf('*%s*', $lowerTerm), ['boost' => 8.0]),
    BoolQuery::SHOULD
);

if (ctype_digit($lowerTerm)) {
    $stripped = ltrim($lowerTerm, '0');
    if ($stripped !== '') {
        $search->addQuery(
            new WildcardQuery('productNumber', sprintf('*%s', $stripped), ['boost' => 25.0]),
            BoolQuery::SHOULD
        );
    }
}
```

**Logic explained:**

| Query | When | Boost | What it does |
|-------|------|-------|-------------|
| `TermQuery('productNumber', $lowerTerm)` | always | 30.0 | Exact keyword match — catches direct hits (e.g., `"4000"` → `"4000"`) |
| `WildcardQuery('productNumber', '*{term}*')` | always | 8.0 | Low-boost substring match for partials |
| `WildcardQuery('productNumber', '*{stripped}')` | only when `ctype_digit` and stripping produced a non-empty result | 25.0 | Matches any product number **ending with** the stripped digits — `*4000` catches both `"004000"` and `"4000"` |

**Edge cases handled by `ctype_digit` + `ltrim`:**

| User types | `ctype_digit` | `ltrim(, '0')` | Query pattern | Matches productNumber |
|------------|---------------|-----------------|---------------|----------------------|
| `"4000"` | true | `"4000"` | `*4000` | `"004000"`, `"4000"` |
| `"004000"` | true | `"4000"` | `*4000` | `"004000"`, `"4000"` |
| `"0"` | true | `""` (empty) | skipped | — (no query, empty stripped) |
| `"00"` | true | `""` (empty) | skipped | — (no query, empty stripped) |
| `"ABC123"` | false | — | not added | name-based queries only |
| `"foo"` | false | — | not added | name-based queries only |

**Performance note:** `WildcardQuery` with a leading `*` is less performant than a `term` query, but for numeric search terms (which are a small fraction of total searches) this is acceptable. The alternative (index-time normalizer) would require a full re-index and more code.

---

### Phase 2: Update AGENTS.md

**File:** `AGENTS.md` (at project root)

**Change type:** [MODIFY]

Add a note to the "Key Architecture" section. After the existing bullet about query boosting, insert:

```markdown
- **Product number search:** `ElasticsearchSearchSubscriber` adds `TermQuery` (exact keyword match, boost 30) and `WildcardQuery` (substring, boost 8) on `productNumber`. When the term is purely numeric, leading zeros are stripped in PHP and a `WildcardQuery *{stripped}` (boost 25) is added so `"4000"` finds products with number `"004000"`.
```

---

### Phase 3: Write Implementation Report

**File:** `_ai/backlog/reports/260717_1320__IMPLEMENTATION_REPORT__strip-leading-zeros-from-product-number-search.md`

**Change type:** [NEW FILE]

After all changes are implemented, generate the report.

## 5. Verification

1. **Code review:** Ensure the subscriber correctly uses `ctype_digit`, `ltrim`, strips leading zeros, and skips empty stripped strings.
2. **Functional test (storefront):** Search for `"4000"` — the product with article number `"004000"` should appear in results.
3. **Functional test (edge cases):**
   - Search for `"004000"` — should still find the product.
   - Search for `"0"` or `"00"` — should NOT trigger the wildcard query (empty stripped string), no crash.
   - Search for a non-numeric term like `"foo"` — product-number queries are still added (term + substring wildcard) with lower boost, should not interfere with name-based results.
4. **No re-index needed** — changes only affect search-time query construction.

## 6. Files Changed

| File | Action | Summary |
|------|--------|---------|
| `src/Subscriber/ElasticsearchSearchSubscriber.php` | MODIFY | Add `TermQuery` and `WildcardQuery` for `productNumber`, with leading-zero stripping for numeric terms |
| `AGENTS.md` | MODIFY | Document the product number search feature |
| `_ai/backlog/reports/...` | NEW | Implementation report |
