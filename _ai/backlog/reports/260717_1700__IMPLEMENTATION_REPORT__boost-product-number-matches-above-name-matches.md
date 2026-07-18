---
filename: "_ai/backlog/reports/260717_1700__IMPLEMENTATION_REPORT__boost-product-number-matches-above-name-matches.md"
title: "Report: Boost Product Number Exact/Substring Matches Above Name Matches"
createdAt: 2026-07-17 17:00
updatedAt: 2026-07-17 17:00
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesModified: 1
filesCreated: 0
filesDeleted: 0
tags: [elasticsearch, search-ranking, product-number, scoring, boost]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Boost Product Number Exact/Substring Matches Above Name Matches

## 1. Summary

Added two `SHOULD` boost queries (`WildcardQuery` containing, `PrefixQuery` starts-with) targeting the `productNumber` field in `ElasticsearchSearchSubscriber`, placed **outside** the language-ID loop (since product numbers are language-agnostic). The boosts (2,000,000 and 1,800,000) are set higher than the existing name-field `ConstantScoreQuery` boosts (1,000,000) to ensure that any product whose product number contains the search term outranks products that only match via the name field.

## 2. Changes

### File Modified: `src/Subscriber/ElasticsearchSearchSubscriber.php`

Added 10 lines of PHP between the guard clauses and the `foreach ($languageIdChain ...)` loop:

- **`WildcardQuery` on `productNumber`** with `*{term}*` pattern and `boost => 2_000_000.0` — catches any product number containing the search term as a substring.
- **`PrefixQuery` on `productNumber`** with `boost => 1_800_000.0` — additional boost for product numbers that start with the search term.

Both are term-level queries that inherently produce constant scores in Lucene (no tf/idf or length normalisation), so no `ConstantScoreQuery` wrapper is needed.

### Why Outside the Language Loop

`productNumber` is not language-translated. Placing these queries before `foreach ($languageIdChain ...)` avoids redundant duplicate query additions per language.

### Updated Boost Hierarchy

| Rank | Query | Field | Effective Boost |
|------|-------|-------|-----------------|
| 1st | Wildcard contains | `productNumber` | 2,000,000 |
| 2nd | Prefix starts-with | `productNumber` | 1,800,000 |
| 3rd | ConstantScore MatchPhrase | `name.*.search` | 1,000,000 |
| 4th | ConstantScore Match AND | `name.*.search` | 500,000 |
| 5th | ConstantScore Match AND | `name.*.delimiter` | 200,000 |
| 6th | Wildcard substring | `name.*` | 15,000 |
| 7th | Prefix | `name.*` | 1,100 |

## 3. Verification

- PHP syntax check: **passed** (`php -l` reports no syntax errors)
- Full runtime verification (debug search command) was not possible as the environment lacks database connectivity (`focus-mariadb` host unreachable)

### To verify manually on a running system:

```bash
php bin/console cache:clear
php bin/console topdata:es-hacks:debug-search "4000"
```

Expected: COLOP 4000WD/F, COLOP 4000WD/I, COLOP 4000WD/D appear in positions 1–3, above "Scosche magicPACK Powerbank 4000 mAh".

## 4. Files Changed

| File | Action |
|------|--------|
| `src/Subscriber/ElasticsearchSearchSubscriber.php` | Modified (added 10 lines) |
