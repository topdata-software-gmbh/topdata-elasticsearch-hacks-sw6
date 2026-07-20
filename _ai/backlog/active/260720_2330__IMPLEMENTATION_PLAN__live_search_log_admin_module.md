---
filename: "_ai/backlog/active/260720_2330__IMPLEMENTATION_PLAN__live_search_log_admin_module.md"
title: "Live Search Log Admin Module"
createdAt: 2026-07-20 23:30
updatedAt: 2026-07-20 23:30
status: draft
priority: low
tags: [shopware, admin, ux, logging]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Live Search Log Admin Module

## 1. Problem Description

The plugin already logs every search/suggest query to `tdeh_search_log` in real time, but there is no admin UI to view these raw logs. The only way to inspect them is via direct database queries. This makes debugging difficult — a merchant or developer cannot see what users are actually typing, whether logging is working, or spot unusual traffic patterns without SQL access.

## 2. Executive Summary

Add a **read-only, auto-refreshable admin listing page** for the `tdeh_search_log` table under the existing "Topdata ES" navigation group. The view displays unfiltered raw search queries with a prominent notice that logs are transient (purged hourly by the consolidation task). Includes a term filter for searching specific queries, a manual refresh button, and `sw-time-ago` columns for timestamps.

**Key design decisions:**
- Fully read-only (no edit, delete, or inline edit) — logs are append-only system data
- Default sort by `createdAt DESC` (most recent first) since this is a "live" view
- Term filter for debugging specific queries
- Warning banner explaining transient nature (to avoid confusion when rows disappear)

No new database tables or migrations are needed — the `tdeh_search_log` table already exists.

---

## 3. Project Environment Details

- Same as parent plugin (see `AGENTS.md`)
- The `tdeh_search_log` table schema is already deployed
- No new DB changes required

---

## 4. Implementation Phases

### Phase 1: Entity Definition for `tdeh_search_log`

The table has no Shopware ORM entity yet. We need one so the admin listing can use `sw-entity-listing` with the `repositoryFactory`.

#### [NEW FILE] `src/Entity/SearchLog/SearchLogEntity.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchLog;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SearchLogEntity extends Entity
{
    use EntityIdTrait;

    protected string $sessionToken;
    protected string $term;
    protected int $resultCount;

    public function getSessionToken(): string
    {
        return $this->sessionToken;
    }

    public function setSessionToken(string $sessionToken): void
    {
        $this->sessionToken = $sessionToken;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getResultCount(): int
    {
        return $this->resultCount;
    }

    public function setResultCount(int $resultCount): void
    {
        $this->resultCount = $resultCount;
    }
}
```

#### [NEW FILE] `src/Entity/SearchLog/SearchLogCollection.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                   add(SearchLogEntity $entity)
 * @method void                   set(string $key, SearchLogEntity $entity)
 * @method SearchLogEntity[]      getIterator()
 * @method SearchLogEntity[]      getElements()
 * @method SearchLogEntity|null   get(string $key)
 * @method SearchLogEntity|null   first()
 * @method SearchLogEntity|null   last()
 */
class SearchLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SearchLogEntity::class;
    }
}
```

#### [NEW FILE] `src/Entity/SearchLog/SearchLogEntityDefinition.php`

Note: No `updatedAt` field — the table has no `updated_at` column (logs are append-only).

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SearchLogEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdeh_search_log';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SearchLogEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SearchLogCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('session_token', 'sessionToken'))->addFlags(new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new IntField('result_count', 'resultCount'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
        ]);
    }
}
```

---

### Phase 2: Admin Module

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-log/index.ts`

```typescript
import './page/search-log-list';

Shopware.Module.register('topdata-es-search-log', {
    type: 'plugin',
    name: 'SearchLog',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-search-log.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-search-log.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-search-log-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-elasticsearch-hacks-sw6',
        label: 'TopdataElasticsearchHacksSW6.nav.mainTitle',
        color: '#189eff',
        icon: 'default-shopping-search',
        position: 100,
        parent: 'sw-content',
    }, {
        id: 'topdata-es-search-log-list',
        label: 'TopdataElasticsearchHacksSW6.nav.searchLog',
        color: '#189eff',
        path: 'topdata.es.search.log.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-log/page/search-log-list/index.ts`

```typescript
import template from './search-log-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-search-log-list', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'createdAt',
            sortDirection: 'DESC',
            limit: 25,
            termFilter: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdeh_search_log');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'resultCount',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.columnResultCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'sessionToken',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.columnSessionToken'),
                allowResize: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },
    },

    mounted() {
        this.getList();
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            if (this.termFilter) {
                criteria.addFilter(Criteria.contains('term', this.termFilter));
            }

            this.repository.search(criteria).then((result) => {
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onPageChange(params) {
            this.page = params.page;
            this.limit = params.limit;
            this.getList();
        },

        onSortColumn(column) {
            this.sortBy = column.dataIndex ?? column.property;
            this.sortDirection = column.sortDirection ?? 'ASC';
            this.getList();
        },

        onRefresh() {
            this.getList();
        },

        onSearchTerm() {
            this.page = 1;
            this.getList();
        },
    },
});
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-log/page/search-log-list/search-log-list.html.twig`

```html
<sw-page class="topdata-es-search-log-list-page">
    <template #smart-bar-header>
        <h2>{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button variant="primary" @click="onRefresh">
            {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.buttonRefresh') }}
        </sw-button>
    </template>

    <template #search-bar>
        <sw-search-bar
            :placeholder="$tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.searchPlaceholder')"
            :initial-search="termFilter"
            @search="onSearchTerm"
        />
    </template>

    <template #content>
        <sw-alert variant="info" appearance="notification" :show-icon="true">
            {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-log.transientNotice') }}
        </sw-alert>

        <sw-entity-listing
            v-if="items"
            :dataSource="items"
            :columns="columns"
            :repository="repository"
            identifier="topdata-es-search-log"
            :show-settings="true"
            :show-selection="false"
            :allow-view="false"
            :allow-edit="false"
            :allow-delete="false"
            :allow-inline-edit="false"
            :full-page="true"
            :sort-by="sortBy"
            :sort-direction="sortDirection"
            :is-loading="isLoading"
            @page-change="onPageChange"
            @column-sort="onSortColumn"
        >
            <template #column-createdAt="{ item }">
                <sw-time-ago :date="item.createdAt" />
            </template>

            <template #column-sessionToken="{ item }">
                <code style="font-size: 0.85em; word-break: break-all;">{{ item.sessionToken }}</code>
            </template>
        </sw-entity-listing>
    </template>
</sw-page>
```

---

### Phase 3: Registration & Snippets

#### [MODIFY] `src/Resources/app/administration/src/main.ts`

Add the import for the new module:

```typescript
import './module/topdata-es-search-stats';
import './module/topdata-es-search-log';
import './module/topdata-es-zero-search';
import './module/topdata-es-synonym';
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/en-GB.json`

Add the `searchLog` nav key and the `topdata-es-search-log` section:

```json
{
    "TopdataElasticsearchHacksSW6": {
        "nav":                    {
            "mainTitle":         "Topdata ES",
            "zeroSearchResults": "Zero Search Results",
            "searchStats":       "Search Statistics",
            "searchLog":         "Search Log",
            "synonyms":          "Synonyms"
        },
        "topdata-es-search-log": {
            "title":                "Search Log",
            "description":          "Live view of raw search queries",
            "columnTerm":           "Search Term",
            "columnResultCount":    "Results",
            "columnSessionToken":   "Session",
            "columnCreatedAt":      "Searched At",
            "buttonRefresh":        "Refresh",
            "searchPlaceholder":    "Filter by search term…",
            "transientNotice":      "This log is transient. Raw search queries are automatically consolidated and purged hourly by the background task. Data shown here is a live snapshot and may disappear after the next consolidation run."
        }
    }
}
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/de-DE.json`

```json
{
    "TopdataElasticsearchHacksSW6": {
        "nav":                    {
            "mainTitle":         "Topdata ES",
            "zeroSearchResults": "Null-Suchergebnisse",
            "searchStats":       "Suchstatistiken",
            "searchLog":         "Suchprotokoll",
            "synonyms":          "Synonyme"
        },
        "topdata-es-search-log": {
            "title":                "Suchprotokoll",
            "description":          "Live-Ansicht der rohen Suchanfragen",
            "columnTerm":           "Suchbegriff",
            "columnResultCount":    "Ergebnisse",
            "columnSessionToken":   "Sitzung",
            "columnCreatedAt":      "Gesucht am",
            "buttonRefresh":        "Aktualisieren",
            "searchPlaceholder":    "Nach Suchbegriff filtern…",
            "transientNotice":      "Dieses Protokoll ist transient. Rohe Suchanfragen werden stündlich automatisch konsolidiert und gelöscht. Die angezeigten Daten sind eine Live-Momentaufnahme und können nach der nächsten Konsolidierung verschwinden."
        }
    }
}
```

---

### Phase 4: Service Registration

#### [MODIFY] `src/Resources/config/services.xml`

Add the `SearchLogEntityDefinition` service alongside the existing entity definitions:

```xml
<!-- SearchLog Entity Definition -->
<service id="Topdata\TopdataElasticsearchHacksSW6\Entity\SearchLog\SearchLogEntityDefinition">
    <tag name="shopware.entity.definition"/>
</service>
```

Insert it after the `ZeroSearchEntityDefinition` block (around line 10), keeping alphabetical grouping.

---

## 5. Housekeeping & Validation

1. Run the admin build: `./bin/build-administration.sh` (or the project's equivalent)
2. Navigate to Content → Topdata ES → Search Log in the admin
3. Verify the listing loads with data ordered by `createdAt DESC`
4. Test the term filter — type a partial term and verify results narrow
5. Verify the transient info banner is visible at the top
6. Verify all columns render correctly, especially `sw-time-ago` for timestamps
7. Verify no edit/delete actions appear in the listing context menu
