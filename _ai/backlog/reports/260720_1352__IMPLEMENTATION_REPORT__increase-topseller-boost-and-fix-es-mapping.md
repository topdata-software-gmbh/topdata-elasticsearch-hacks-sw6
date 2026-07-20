---
filename: "_ai/backlog/reports/260720_1352__IMPLEMENTATION_REPORT__increase-topseller-boost-and-fix-es-mapping.md"
title: "Report: Increase topseller boost and fix ES custom field mapping"
createdAt: 2026-07-20 13:52
updatedAt: 2026-07-20 13:52
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 1
filesModified: 2
filesDeleted: 0
tags: [elasticsearch, topseller, scoring, custom-fields, mapping]
documentType: IMPLEMENTATION_REPORT
---

# Report: Increase topseller boost and fix ES custom field mapping

## Summary

Fixed topseller ranking in Elasticsearch search results. The `topdata_is_topseller` custom field was never actually indexed into ES because `ElasticsearchFieldMapper::formatCustomField()` silently removes fields whose type is not registered in the custom fields mapping. Created a new subscriber to register the field type via `ElasticsearchCustomFieldsMappingEvent` and increased the topseller boost from 750K to 2.5M per language to ensure topsellers dominate generic terms like "Papier".

## Prompt used

> Bei Papier sind die Topseller (Edelweiss WC Papier) leider nicht ganz oben.. kann ich den score aber nochmal anpassen. https://focusshop.ch/search?search=Papier
> bei "beste ergebnisse" wird der gesamtscore berechnet .. und danach sortiert .. muss ich gewichtung fuer is_topseller groesser stellen.. please update the score and give me a table with all the scores.

## Files Changed

### Created
- `src/Subscriber/ElasticsearchCustomFieldsMappingSubscriber.php` — new subscriber that registers `topdata_is_topseller` as `CustomFieldTypes::BOOL` in the ES custom fields mapping via `ElasticsearchCustomFieldsMappingEvent`

### Modified
- `src/Subscriber/ElasticsearchSearchSubscriber.php` — topseller boost increased from `750_000` → `1_200_000` → `2_500_000`
- `src/Resources/config/services.xml` — registered `ElasticsearchCustomFieldsMappingSubscriber` as `kernel.event_subscriber`

## Key Changes

1. **Root cause identified**: `ElasticsearchFieldMapper::formatCustomField()` (vendor/shopware/elasticsearch/Framework/ElasticsearchFieldMapper.php:194-196) silently removes custom fields from the indexed ES document if their type is not in the types map. The `getCustomFieldTypes()` SQL query only includes fields where `include_in_search = 1`, used in sorting/streams, or from app-owned sets. The product-flags-sw6 plugin's `CustomFieldInstaller` does not set `include_in_search`, so `topdata_is_topseller` was never indexed into ES — making the TermQuery boost in `ElasticsearchSearchSubscriber` a no-op.

2. **Fix**: Added `ElasticsearchCustomFieldsMappingSubscriber` that listens to `ElasticsearchCustomFieldsMappingEvent` and sets `topdata_is_topseller` as `CustomFieldTypes::BOOL` for the `product` entity.

3. **Boost increased**: To ensure topsellers clearly dominate generic search terms, the topseller boost was raised to `2,500,000` — well above `match_phrase` (1,000,000) and `match AND` (500,000) name boosts per language.

## Deviations from Plan

No formal plan existed for this task. The fix was discovered during investigation — what initially appeared to be a mere boost-tuning issue turned out to be a data-indexing bug.

## Technical Decisions

- Used `ElasticsearchCustomFieldsMappingEvent` (the official extension point) rather than modifying the product-flags-sw6 plugin's `CustomFieldInstaller`. This keeps the concern of "what gets indexed for search" in the search-hacks plugin where it belongs.
- Chose `2,500,000` as the boost value because it clearly exceeds `match_phrase (1M) + match AND (500K)` = 1.5M per language, ensuring even products with strong name matches cannot outrank a topseller on generic terms.

## Testing Notes

After deploying:
1. Clear cache: `php bin/console cache:clear`
2. Full ES reindex (required — existing documents must be re-indexed with the field now present):
   ```bash
   php bin/console es:reset && php bin/console es:index --no-queue && php bin/console es:create:alias
   ```
3. Search for "Papier" on the storefront — topseller products (e.g. Edelweiss WC Papier) should appear at the top of "Beste Ergebnisse".
4. Search for "WC-Papier" and "Papierhandtücher" as regression check — topsellers should remain at top.

## Score Table

| # | Clause | Field | Boost | Type |
|---|--------|-------|-------|------|
| 1 | productNumber exact | `productNumber` | 2,000,000 | TermQuery |
| 1b | productNumber stripped | `productNumber` | 1,500,000 | TermQuery (numeric only) |
| 2 | name match_phrase | `name.{lang}.search` | 1,000,000 | ConstantScore+MatchPhrase |
| **3** | **topseller flag** | `customFields.{lang}.topdata_is_topseller` | **2,500,000** | TermQuery |
| 4 | name match AND | `name.{lang}.search` | 500,000 | ConstantScore+Match |
| 5 | name delimiter AND | `name.{lang}.delimiter` | 200,000 | ConstantScore+Match |
| 6 | name wildcard | `name.{lang}` | 15,000 | WildcardQuery (x2) |
| 7 | name prefix | `name.{lang}` | 1,100 | PrefixQuery |

All boosts are added as `BoolQuery::SHOULD` clauses. Scores are additive per matching clause at query time (subject to Elasticsearch's coord factor for SHOULD clauses).
