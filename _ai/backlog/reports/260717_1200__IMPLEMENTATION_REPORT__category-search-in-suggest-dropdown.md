---
filename: "_ai/backlog/reports/260717_1200__IMPLEMENTATION_REPORT__category-search-in-suggest-dropdown.md"
title: "Report: Add category results above products in search suggest dropdown"
createdAt: 2026-07-17 12:00
createdBy: opencode
updatedAt: 2026-07-17 20:45
updatedBy: opencode
planFile: "_ai/backlog/active/260717_1200__IMPLEMENTATION_PLAN__category-search-in-suggest-dropdown.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 4
filesModified: 3
filesDeleted: 0
tags: [search, categories, storefront, suggest]
documentType: IMPLEMENTATION_REPORT
---

## Summary

Added category search results to the storefront search suggest dropdown. When a user types a search term that matches category names, matching categories now appear with a "Kategorien" section title. The layout is configurable: categories can appear above products (default) or to the left side. The implementation follows the existing pattern used by the `topdata-topfinder-pro-sw6` plugin.

## Files Changed

### New Files
- `src/Subscriber/CategorySuggestSubscriber.php` — Subscribes to `SuggestPageLoadedEvent`, searches categories via `SalesChannelRepository`, attaches results to page extension
- `src/Resources/views/storefront/layout/header/search-suggest.html.twig` — Template override that renders category section with configurable layout
- `src/Resources/snippet/storefront.de-DE.json` — German translations for new strings
- `src/Resources/snippet/storefront.en-GB.json` — English translations for new strings

### Modified Files
- `src/Resources/config/services.xml` — Registered `CategorySuggestSubscriber` with `sales_channel.category.repository`
- `src/Resources/config/config.xml` — Added `categorySuggestLayout` config option (`above`/`left`, default `above`)
- `AGENTS.md` — Documented the new category suggest feature and config options

## Key Changes

- New `CategorySuggestSubscriber` listens to `SuggestPageLoadedEvent` and searches categories using `ContainsFilter` on `name`
- Categories are filtered by: active=true, visible=true, type=page, respecting `excludedCategories` config and sales channel root categories
- Results limited to 5, sorted by level (top-level categories first)
- Template override prepends a `<ul>` with category results before `{{ parent() }}`
- "Kategorien" section title uses same bold style as "Produkte" title (matching TopFinder plugin convention)
- Removed duplicate "Produkte" title that was caused by overriding `layout_search_suggest_results` block
- Layout is configurable via `categorySuggestLayout` system config:
  - `above` (default): Categories stack above products in single column, overrides TopFinder's flex layout using `:has()` CSS selector
  - `left`: Categories appear in left column alongside products (30% width with border separator), compatible with TopFinder's two-column flex layout
- Inline `<style>` block only rendered when categories are present (conditional on extension data)

## Technical Decisions

- **Event choice**: Used `SuggestPageLoadedEvent` instead of `ProductSuggestCriteriaEvent` because we need to add data to the page object (via extensions), not modify the product criteria
- **Repository choice**: Used `SalesChannelRepository` (injected as `sales_channel.category.repository`) instead of the DAL `CategoryRepository` to respect sales channel visibility and permissions
- **Inline styles**: Chose inline `<style>` in the Twig template over a separate SCSS file to avoid requiring a storefront Vite build
- **`:has()` CSS selector**: Used modern CSS `:has()` pseudo-class to conditionally apply layout styles only when category results are present, avoiding the need for JavaScript class manipulation
- **Extension name**: Used `topdata_category_suggest` (snake_case) for the page extension name, following Shopware convention
- **Category type filter**: Only `TYPE_PAGE` categories are shown (excludes `TYPE_FOLDER` and `TYPE_LINK`)
- **No "Produkte" title override**: Removed the `layout_search_suggest_results` block override to avoid duplicating the "Produkte" title that the TopFinder plugin already adds in combined mode

## Testing Notes

1. Clear cache: `php bin/console cache:clear`
2. Search for a term matching a category name
3. Verify "Kategorien" title appears with same bold style as "Produkte"
4. Verify "Produkte" title appears only once (not duplicated)
5. Verify default "above" layout stacks categories above products in single column
6. Verify "left" layout shows categories in left column with border separator
7. Verify excluded categories do not appear
8. Verify inactive/invisible categories do not appear
9. Verify search with < 2 characters does not trigger category search
10. Verify normal product-only search still works when no categories match

## Documentation Updates

- Updated `AGENTS.md` with category suggest feature description
- Added configuration table documenting `categorySuggestLayout` option
- Added storefront view documentation for the search suggest template

## Next Steps

- Consider adding category result count badge next to category names
- Consider highlighting the matching portion of the category name
- Consider adding a system config option to enable/disable category suggest independently
