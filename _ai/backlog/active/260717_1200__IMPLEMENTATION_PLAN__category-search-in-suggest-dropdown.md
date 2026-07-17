---
filename: "_ai/backlog/active/260717_1200__IMPLEMENTATION_PLAN__category-search-in-suggest-dropdown.md"
title: "Add category results above products in search suggest dropdown"
createdAt: 2026-07-17 12:00
createdBy: opencode [qwen3.7-plus]
updatedAt: 2026-07-17 12:00
updatedBy: opencode [qwen3.7-plus]
status: draft
priority: high
tags: [search, categories, storefront, suggest, elasticsearch]
project: topdata-elasticsearch-hacks-sw6
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Add Category Results Above Products in Search Suggest Dropdown

## Problem Statement

The storefront search suggest dropdown currently only shows product results. When a user searches for a term like "Fensterwischer", they only see matching products. If there are categories that match the search term (e.g., a "Fensterwischer" category), these are not shown at all. The goal is to display matching categories **above** the product results in the search suggest dropdown, so users can quickly navigate to a category page when their search term matches a category name.

## Implementation Notes

### Environment
- **Project**: `topdata-elasticsearch-hacks-sw6` (Shopware 6.7 plugin)
- **Plugin namespace**: `Topdata\TopdataElasticsearchHacksSW6`
- **Backend root**: `src/`
- **PHP Version**: 8.2 / 8.3 / 8.4
- **Symfony**: 7.4
- **Shopware**: 6.7.x

### Key Architecture Points
- The search suggest dropdown is rendered by `@Storefront/storefront/layout/header/search-suggest.html.twig`
- Data flows through: `SearchController::suggest()` → `SuggestPageLoader::load()` → `SuggestPageLoadedEvent` → template
- `SuggestPage` has `searchResult` (products) and `searchTerm` properties
- Additional data can be attached via `$page->addExtension()` (Shopware's extension system)
- The `topdata-topfinder-pro-sw6` plugin already demonstrates the pattern of prepending a section above products in the dropdown by overriding `layout_search_suggest_container`
- Categories are searched via `SalesChannelRepository<CategoryCollection>` with `ContainsFilter` on `name`
- The existing `SearchCriteriaSubscriber` already handles `excludedCategories` config — category search should respect this

### Relevant Files
- `src/Subscriber/ElasticsearchSearchSubscriber.php` — existing product search boosts
- `src/Subscriber/SearchCriteriaSubscriber.php` — existing category exclusion logic
- `src/Resources/config/services.xml` — DI configuration
- `src/Resources/views/storefront/` — storefront templates (currently only has `example.html.twig`)

### Existing Plugin Override Pattern (topdata-topfinder-pro-sw6)
The TopFinder plugin overrides `layout_search_suggest_container` to prepend a `<ul>` with device results, then calls `{{ parent() }}`. It also overrides `layout_search_suggest_results` to add a "Produkte" title when in combined mode. This is the exact pattern we will follow.

### Commands
```bash
# After implementation, clear cache
php bin/console cache:clear

# No database migrations needed (no new tables)
# No ES reindex needed (categories are searched via DAL, not Elasticsearch)
```

---

## Phase 1: Category Search Subscriber

### Objective
Create a subscriber that listens to `SuggestPageLoadedEvent`, searches for matching categories, and attaches the results to the `SuggestPage` via an extension.

### Tasks

#### Task 1.1: Create `CategorySuggestSubscriber`

[NEW FILE] `src/Subscriber/CategorySuggestSubscriber.php`

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Suggest\SuggestPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CategorySuggestSubscriber implements EventSubscriberInterface
{
    private const CATEGORY_LIMIT = 5;

    /**
     * @param SalesChannelRepository<CategoryCollection> $categoryRepository
     */
    public function __construct(
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SuggestPageLoadedEvent::class => 'onSuggestPageLoaded',
        ];
    }

    public function onSuggestPageLoaded(SuggestPageLoadedEvent $event): void
    {
        $term = $event->getRequest()->query->get('search', '');

        if ($term === '' || mb_strlen($term) < 2) {
            return;
        }

        $salesChannelContext = $event->getSalesChannelContext();
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        $criteria = new Criteria();
        $criteria->setLimit(self::CATEGORY_LIMIT);
        $criteria->addFilter(new ContainsFilter('name', $term));
        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addFilter(new EqualsFilter('type', CategoryDefinition::TYPE_PAGE));
        $criteria->addSorting(new FieldSorting('level', FieldSorting::ASCENDING));
        $criteria->addAssociations(['media', 'seoUrls']);

        $excludedCategories = $this->systemConfigService->get(
            'TopdataElasticsearchHacksSW6.config.excludedCategories',
            $salesChannelId
        );

        if (!empty($excludedCategories) && \is_array($excludedCategories)) {
            $criteria->addFilter(
                new NotFilter(
                    NotFilter::CONNECTION_AND,
                    [new EqualsAnyFilter('id', $excludedCategories)]
                )
            );
        }

        $rootIds = array_filter([
            $salesChannelContext->getSalesChannel()->getNavigationCategoryId(),
            $salesChannelContext->getSalesChannel()->getFooterCategoryId(),
            $salesChannelContext->getSalesChannel()->getServiceCategoryId(),
        ]);

        if ($rootIds !== []) {
            $rootFilter = new OrFilter();
            foreach ($rootIds as $rootId) {
                $rootFilter->addQuery(new EqualsFilter('id', $rootId));
                $rootFilter->addQuery(new ContainsFilter('path', '|' . $rootId . '|'));
            }
            $criteria->addFilter($rootFilter);
        }

        $categories = $this->categoryRepository->search($criteria, $salesChannelContext);

        if ($categories->count() === 0) {
            return;
        }

        $event->getPage()->addExtension('topdata_category_suggest', new ArrayEntity([
            'categories' => $categories->getEntities(),
            'total' => $categories->getTotal(),
        ]));
    }
}
```

#### Task 1.2: Register the subscriber in services.xml

[MODIFY] `src/Resources/config/services.xml`

Add the following service definition before the closing `</services>` tag:

```xml
        <!-- Category Suggest Subscriber (adds category results to search dropdown) -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\CategorySuggestSubscriber">
            <argument type="service" id="sales_channel.category.repository" key="$categoryRepository"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService" key="$systemConfigService"/>
            <tag name="kernel.event_subscriber"/>
        </service>
```

---

## Phase 2: Storefront Template Override

### Objective
Create a Twig template that overrides the search suggest dropdown to display matching categories above the product results, following the same pattern as `topdata-topfinder-pro-sw6`.

### Tasks

#### Task 2.1: Create search-suggest.html.twig override

[NEW FILE] `src/Resources/views/storefront/layout/header/search-suggest.html.twig`

```twig
{% sw_extends '@Storefront/storefront/layout/header/search-suggest.html.twig' %}

{% block layout_search_suggest_container %}
    {% if page.extensions and page.extensions.topdata_category_suggest %}
        {% set categoryData = page.extensions.topdata_category_suggest %}
        {% set categories = categoryData.categories %}
        {% set categoriesTotal = categoryData.total %}

        {% if categories|length > 0 %}
            <ul class="search-suggest-container search-suggest-container-categories">
                <li class="search-suggest-product js-result suggest-title">
                    {{ 'TopdataElasticsearchHacksSW6.search.titleCategories'|trans }}
                </li>
                {% for category in categories %}
                    <li class="search-suggest-product js-result">
                        <a href="{{ seoUrl('frontend.navigation.page', {navigationId: category.id}) }}"
                           title="{{ category.translated.name }}"
                           class="search-suggest-product-link">
                            <div class="row align-items-center g-0">
                                <div class="col-auto search-suggest-product-image-container">
                                    {% if category.media and category.media.url %}
                                        {% sw_thumbnails 'search-suggest-category-image-thumbnails' with {
                                            media: category.media,
                                            sizes: {
                                                'default': '100px'
                                            },
                                            attributes: {
                                                'class': 'search-suggest-product-image',
                                                'alt': category.translated.name,
                                                'title': category.translated.name
                                            }
                                        } %}
                                    {% else %}
                                        {% sw_icon 'folder' style {
                                            'size': 'lg'
                                        } %}
                                    {% endif %}
                                </div>
                                <div class="col search-suggest-product-name">
                                    {{ category.translated.name }}
                                </div>
                            </div>
                        </a>
                    </li>
                {% endfor %}
                {% if categoriesTotal > categories|length %}
                    <li class="js-result search-suggest-total">
                        <div class="row align-items-center g-0">
                            <div class="col">
                                <a href="{{ path('frontend.search.page') }}?search={{ page.searchTerm }}"
                                   title="{{ 'header.searchAllResults'|trans|striptags }}"
                                   class="search-suggest-total-link">
                                    {% sw_icon 'arrow-head-right' style { 'size': 'sm' } %}
                                    {{ 'TopdataElasticsearchHacksSW6.search.categoryResultsLink'|trans|sw_sanitize }}
                                </a>
                            </div>
                            <div class="col-auto search-suggest-total-count">
                                {{ 'header.searchResults'|trans({
                                    '%count%': categoriesTotal,
                                })|sw_sanitize }}
                            </div>
                        </div>
                    </li>
                {% endif %}
            </ul>
        {% endif %}
    {% endif %}

    {{ parent() }}
{% endblock %}

{% block layout_search_suggest_results %}
    {% if page.extensions and page.extensions.topdata_category_suggest and page.extensions.topdata_category_suggest.categories|length > 0 %}
        <li class="search-suggest-product js-result suggest-title">
            {{ 'TopdataElasticsearchHacksSW6.search.titleProducts'|trans }}
        </li>
    {% endif %}
    {{ parent() }}
{% endblock %}
```

---

## Phase 3: Snippet Files

### Objective
Create storefront snippet files for the new translatable strings used in the template.

### Tasks

#### Task 3.1: Create German snippet file

[NEW FILE] `src/Resources/snippet/storefront.de-DE.json`

```json
{
    "TopdataElasticsearchHacksSW6": {
        "search": {
            "titleCategories": "Kategorien",
            "titleProducts": "Produkte",
            "categoryResultsLink": "Alle Kategorie-Ergebnisse anzeigen"
        }
    }
}
```

#### Task 3.2: Create English snippet file

[NEW FILE] `src/Resources/snippet/storefront.en-GB.json`

```json
{
    "TopdataElasticsearchHacksSW6": {
        "search": {
            "titleCategories": "Categories",
            "titleProducts": "Products",
            "categoryResultsLink": "Show all category results"
        }
    }
}
```

---

## Phase 4: Styling

### Objective
Add minimal SCSS styling for the category section in the search dropdown. The existing `.search-suggest-*` classes from Shopware core handle most of the styling, so we only need a few additions for the section title and category-specific container.

### Tasks

#### Task 4.1: Create storefront SCSS file

[NEW FILE] `src/Resources/app/storefront/src/scss/search-suggest-categories.scss`

```scss
.search-suggest-container-categories {
    .suggest-title {
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c757d;
        padding: 0.5rem 1rem;
        border-bottom: 1px solid #dee2e6;
        cursor: default;

        &:hover {
            background-color: transparent;
        }
    }
}
```

#### Task 4.2: Create storefront JS main entry (if not exists)

Check if `src/Resources/app/storefront/src/main.js` exists. If not, create it to import the SCSS:

[NEW FILE] `src/Resources/app/storefront/src/main.js`

```javascript
import './scss/search-suggest-categories.scss';
```

#### Task 4.3: Create Vite entry config

[NEW FILE] `src/Resources/app/storefront/build/webpack.config.js`

Actually, SW6.7 uses Vite via `pentatrion/vite-bundle`. The storefront build is configured via the plugin's `src/Resources/app/storefront/build/` directory. However, for a simple SCSS import, we can rely on Shopware's automatic SCSS discovery. Let me check if we need a build config.

Since the plugin currently has no storefront JS build, and we only need SCSS, we should use Shopware's built-in SCSS loading. In SW6.7, plugin SCSS files in `src/Resources/app/storefront/src/scss/` are automatically picked up if there is a `main.js` that imports them.

Alternatively, we can inline the styles in the Twig template using a `<style>` block to avoid the build step entirely. This is simpler and avoids the need for a storefront build.

**Decision**: Use inline `<style>` in the Twig template to avoid requiring a storefront JS build. This keeps the implementation simple and avoids the Vite build dependency.

[DELETE] `src/Resources/app/storefront/src/main.js` (not needed)
[DELETE] `src/Resources/app/storefront/src/scss/search-suggest-categories.scss` (not needed)

Instead, add inline styles to the template:

[MODIFY] `src/Resources/views/storefront/layout/header/search-suggest.html.twig`

Add a `<style>` block at the top of the file (after the `{% sw_extends %}` line):

```twig
{% sw_extends '@Storefront/storefront/layout/header/search-suggest.html.twig' %}

{% block layout_head_javascript %}
    {{ parent() }}
    <style>
        .search-suggest-container-categories .suggest-title {
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6c757d;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #dee2e6;
            cursor: default;
        }
        .search-suggest-container-categories .suggest-title:hover {
            background-color: transparent;
        }
    </style>
{% endblock %}
```

Wait — `layout_head_javascript` is not the right block for inline styles. Let me use a different approach. The cleanest way in Shopware is to add a `<style>` block directly in the template. Since this template is loaded via AJAX and injected into the DOM, inline styles within the template will work.

[MODIFY] `src/Resources/views/storefront/layout/header/search-suggest.html.twig`

Add inline styles at the top of the `layout_search_suggest` block:

```twig
{% block layout_search_suggest %}
    <style>
        .search-suggest-container-categories .suggest-title {
            font-weight: 700;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #6c757d;
            padding: 0.5rem 1rem;
            border-bottom: 1px solid #dee2e6;
            cursor: default;
        }
        .search-suggest-container-categories .suggest-title:hover {
            background-color: transparent;
        }
    </style>
    {{ parent() }}
{% endblock %}
```

---

## Phase 5: Verification

### Tasks

#### Task 5.1: Verify the implementation

1. Clear cache: `php bin/console cache:clear`
2. Search for a term that matches a category name (e.g., "Fensterwischer" if a category with that name exists)
3. Verify that:
   - Categories appear above products in the search dropdown
   - The "Kategorien" title is shown above category results
   - The "Produkte" title is shown above product results (when categories are present)
   - Category links navigate to the correct category page
   - Excluded categories (from config) do not appear
   - Inactive/invisible categories do not appear
   - Only page-type categories (not folder/link types) appear
   - The dropdown still works normally when no categories match

#### Task 5.2: Edge cases to verify

- Search term < 2 characters → no category search fires
- No matching categories → only products shown (no "Kategorien" title)
- Category with no media → folder icon placeholder shown
- Multiple categories match → up to 5 shown, sorted by level (top-level first)
- Excluded categories config is respected

---

## Phase 6: Documentation Update

### Objective
Update the AGENTS.md to document the new category search feature.

### Tasks

#### Task 6.1: Update AGENTS.md

[MODIFY] `AGENTS.md`

Add to the "Key Architecture" section:

```markdown
- **Category suggest** (`Subscriber/CategorySuggestSubscriber.php`): listens to `SuggestPageLoadedEvent`, searches categories via `SalesChannelRepository` with `ContainsFilter` on `name` (filtered by active, visible, page-type, respecting `excludedCategories` config and sales channel root categories). Results attached to `SuggestPage` via `topdata_category_suggest` extension. Template override in `views/storefront/layout/header/search-suggest.html.twig` renders categories above products with section titles.
```

Add to the "Storefront Views" section:

```markdown
- **Search suggest**: `src/Resources/views/storefront/layout/header/search-suggest.html.twig` — extends core search-suggest template, prepends category results above products when matching categories exist.
```

---

## Phase 7: Implementation Report

### Objective
Write the implementation report documenting what was done.

### Tasks

#### Task 7.1: Write report

[NEW FILE] `_ai/backlog/reports/260717_1200__IMPLEMENTATION_REPORT__category-search-in-suggest-dropdown.md`

```markdown
---
filename: "_ai/backlog/reports/260717_1200__IMPLEMENTATION_REPORT__category-search-in-suggest-dropdown.md"
title: "Report: Add category results above products in search suggest dropdown"
createdAt: 2026-07-17 12:00
createdBy: opencode [qwen3.7-plus]
updatedAt: 2026-07-17 12:00
updatedBy: opencode [qwen3.7-plus]
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

Added category search results to the storefront search suggest dropdown. When a user types a search term that matches category names, matching categories now appear above the product results with a "Kategorien" section title. The implementation follows the existing pattern used by the `topdata-topfinder-pro-sw6` plugin.

## Files Changed

### New Files
- `src/Subscriber/CategorySuggestSubscriber.php` — Subscribes to `SuggestPageLoadedEvent`, searches categories via `SalesChannelRepository`, attaches results to page extension
- `src/Resources/views/storefront/layout/header/search-suggest.html.twig` — Template override that renders category section above products
- `src/Resources/snippet/storefront.de-DE.json` — German translations for new strings
- `src/Resources/snippet/storefront.en-GB.json` — English translations for new strings

### Modified Files
- `src/Resources/config/services.xml` — Registered `CategorySuggestSubscriber` with `sales_channel.category.repository`
- `AGENTS.md` — Documented the new category suggest feature

## Key Changes

- New `CategorySuggestSubscriber` listens to `SuggestPageLoadedEvent` and searches categories using `ContainsFilter` on `name`
- Categories are filtered by: active=true, visible=true, type=page, respecting `excludedCategories` config and sales channel root categories
- Results limited to 5, sorted by level (top-level categories first)
- Template override prepends a `<ul>` with category results before `{{ parent() }}`
- Section titles ("Kategorien" / "Produkte") shown when both categories and products are present
- Inline `<style>` block for category title styling (avoids need for storefront JS build)

## Technical Decisions

- **Event choice**: Used `SuggestPageLoadedEvent` instead of `ProductSuggestCriteriaEvent` because we need to add data to the page object (via extensions), not modify the product criteria
- **Repository choice**: Used `SalesChannelRepository` (injected as `sales_channel.category.repository`) instead of the DAL `CategoryRepository` to respect sales channel visibility and permissions
- **Inline styles**: Chose inline `<style>` in the Twig template over a separate SCSS file to avoid requiring a storefront Vite build
- **Extension name**: Used `topdata_category_suggest` (snake_case) for the page extension name, following Shopware convention
- **Category type filter**: Only `TYPE_PAGE` categories are shown (excludes `TYPE_FOLDER` and `TYPE_LINK`)

## Testing Notes

1. Clear cache: `php bin/console cache:clear`
2. Search for a term matching a category name
3. Verify categories appear above products with correct section titles
4. Verify excluded categories do not appear
5. Verify inactive/invisible categories do not appear
6. Verify search with < 2 characters does not trigger category search
7. Verify normal product-only search still works when no categories match

## Documentation Updates

- Updated `AGENTS.md` with category suggest feature description
- Added storefront view documentation for the search suggest template

## Next Steps

- Consider adding category result count badge next to category names
- Consider highlighting the matching portion of the category name
- Consider adding a system config option to enable/disable category suggest independently
```
