---
filename: "_ai/backlog/reports/260715_1514__IMPLEMENTATION_REPORT__boost_delimiter_and_debug_alignment.md"
title: "Report: Boost Delimiter Fallback Weights and Align Debug CLI Search Command"
createdAt: 2026-07-15 15:15
updatedAt: 2026-07-15 15:15
planFile: "_ai/backlog/active/260715_1514__IMPLEMENTATION_PLAN__boost_delimiter_and_debug_alignment.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 1
filesModified: 3
filesDeleted: 0
tags: [elasticsearch, shopware, scoring, debugging]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Boost Delimiter Fallback Weights and Align Debug CLI Search Command

## 1. Summary
The implementation successfully updated the search subscriber query boosting coefficients to elevate hyphenated and catenated product terms (such as `"WC-Papier"`) to the top of the storefront search results. The search CLI debug utility was also aligned with these updated queries to ensure consistency between developer debug metrics and storefront outputs.

## 2. Files Changed
### New Files Created
* `_ai/backlog/reports/260715_1514__IMPLEMENTATION_REPORT__boost_delimiter_and_debug_alignment.md`: This report summarizing the implementation details.

### Modified Files
* `src/Subscriber/ElasticsearchSearchSubscriber.php`: Increased boosting weights for `MatchPhraseQuery` on the clean field, `MatchQuery` on the clean and delimiter fields, and wildcard standalone matches.
* `src/Command/Command_DebugSearch.php`: Aligned query building with the updated subfields and boosting weights used in the subscriber.
* `README.md`: Updated developer and user documentation regarding Option B architecture and query boosting.

### Deleted Files
* None.

## 3. Key Changes
* Raised `MatchPhraseQuery` clean field boost to `30.0`.
* Raised `MatchQuery` clean field (AND) boost to `20.0`.
* Raised `MatchQuery` delimiter field (AND) fallback match boost to `15.0`.
* Raised Standalone Word Wildcard queries on the keyword field to `15.0` to defeat BM25 length normalization on actual matching targets.
* Resolved mapping discrepancy in CLI `topdata:es-hacks:debug-search` by adding the delimiter fallback and wildcard clauses to match the storefront logic.

## 4. Deviations from Plan
* None. All phases followed the outlined steps and parameters.

## 5. Technical Decisions
* Increasing the custom boosting metrics to double-digit coefficients (e.g. `15.0` to `30.0`) was necessary to consistently overpower default Shopware `OR` queries across multiple mapped entity properties.

## 6. Testing Notes
Verify the results using:
```bash
php bin/console topdata:es-hacks:debug-search "WC Papier"
php bin/console topdata:es-hacks:debug-search "Papierhandtücher"
```

## 7. Documentation Updates
* Adjusted the Features overview in the repository `README.md` to mention the dedicated subfield mapping and customized analyzed query boosting factors.
