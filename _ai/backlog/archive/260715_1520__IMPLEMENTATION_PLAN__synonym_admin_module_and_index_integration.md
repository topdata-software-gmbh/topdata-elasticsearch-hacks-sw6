---
filename: "_ai/backlog/active/260715_1520__IMPLEMENTATION_PLAN__synonym_admin_module_and_index_integration.md"
title: "Synonym Administration Module and Dynamic Elasticsearch Index Integration"
createdAt: 2026-07-15 15:20
updatedAt: 2026-07-15 15:20
status: completed
completedAt: 2026-07-15 15:28
priority: medium
tags: [elasticsearch, admin-module, synonyms, dal]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

## 1. Problem Description
While the database schema contains a synonym table (`topdata_es_synonym`) and various CLI commands exist to manage it, there is currently:
1. **No Data Abstraction Layer (DAL) integration:** The synonyms cannot be managed via the standard Shopware API.
2. **No Admin Interface:** Merchants have no way to view, create, edit, or delete synonyms in the Shopware Administration area.
3. **No Elasticsearch Application:** The synonyms stored in the database are never actually applied to the Elasticsearch index settings, making them inactive in storefront searches.

---

## 2. Executive Summary
This plan delivers a complete admin interface for managing synonym rules and dynamically applies those rules to the Elasticsearch indices during indexing.

To safely achieve this without querying the database during container compilation (which would break CI pipelines and deployments), we will decorate Shopware's `IndexCreator` service. During index creation, we fetch the database synonyms and dynamically inject them into the Elasticsearch analyzer settings using the inline `synonym` token filter.

---

## 3. Project Environment Details
- Project Name: SW6.7 Plugin
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4

---

## 4. Phased Implementation Plan

### Phase 1: Expose Synonym Table to Shopware DAL
To allow standard Admin API operations (pagination, searches, sorting, and inline-editing), we will register the `topdata_es_synonym` table in Shopware's Data Abstraction Layer (DAL).

#### 1. Create Synonym Entity class:
```php
// [NEW FILE] src/Entity/Synonym/SynonymEntity.php
```
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SynonymEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected string $synonyms;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getSynonyms(): string
    {
        return $this->synonyms;
    }

    public function setSynonyms(string $synonyms): void
    {
        $this->synonyms = $synonyms;
    }
}
```

#### 2. Create Synonym Collection class:
```php
// [NEW FILE] src/Entity/Synonym/SynonymCollection.php
```
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                 add(SynonymEntity $entity)
 * @method void                 set(string $key, SynonymEntity $entity)
 * @method SynonymEntity[]      getIterator()
 * @method SynonymEntity[]      getElements()
 * @method SynonymEntity|null   get(string $key)
 * @method SynonymEntity|null   first()
 * @method SynonymEntity|null   last()
 */
class SynonymCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SynonymEntity::class;
    }
}
```

#### 3. Create Synonym Definition class:
```php
// [NEW FILE] src/Entity/Synonym/SynonymEntityDefinition.php
```
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SynonymEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'topdata_es_synonym';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SynonymEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SynonymCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new LongTextField('synonyms', 'synonyms'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
        ]);
    }
}
```

#### 4. Register Entity in Dependency Injection:
```xml
<!-- [MODIFY] src/Resources/config/services.xml -->
```
```xml
        <!-- Synonym Entity Definition -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym\SynonymEntityDefinition">
            <tag name="shopware.entity.definition"/>
        </service>
```

---

### Phase 2: Create Dynamic Synonym Array Fetcher
We will add a helper method to `SynonymService` to safely retrieve and format synonym mappings specifically for the Elasticsearch analyzer payload.

```php
// [MODIFY] src/Service/SynonymService.php
```
```php
    /**
     * Fetches all synonym rules from the database and formats them as strings for Elasticsearch
     * E.g., ["wc-papier => toilettenpapier, klopapier", "towel => handtuch"]
     *
     * @return string[]
     */
    public function exportToArray(): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $rules = [];

        foreach ($rows as $row) {
            $rules[] = sprintf('%s => %s', trim($row['term']), trim($row['synonyms']));
        }

        return $rules;
    }
```

---

### Phase 3: Build IndexCreator Decorator to Inject Synonyms
We will decorate Shopware's `IndexCreator` service to intercept index generation, load our database synonyms, and dynamically append them to the Elasticsearch settings schema.

```php
// [NEW FILE] src/Elasticsearch/IndexCreatorDecorator.php
```
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Elasticsearch;

use Elasticsearch\Client;
use Shopware\Elasticsearch\Framework\AbstractElasticsearchDefinition;
use Shopware\Elasticsearch\Framework\Indexing\IndexCreator;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

class IndexCreatorDecorator extends IndexCreator
{
    private IndexCreator $decorated;
    private SynonymService $synonymService;

    public function __construct(
        IndexCreator $decorated,
        Client $client,
        array $hosts,
        array $analysisConfig,
        SynonymService $synonymService
    ) {
        // Pass parent arguments down
        parent::__construct($client, $hosts, $analysisConfig);
        $this->decorated = $decorated;
        $this->synonymService = $synonymService;
    }

    public function createIndex(AbstractElasticsearchDefinition $definition, string $index, string $alias, array $config): void
    {
        $synonymRules = $this->synonymService->exportToArray();

        if (!empty($synonymRules)) {
            // Register a custom dynamic synonym filter
            $config['settings']['analysis']['filter']['topdata_synonym_filter'] = [
                'type' => 'synonym',
                'synonyms' => $synonymRules,
            ];

            // Append the synonym filter to our custom delimiter analyzer
            if (isset($config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer'])) {
                $filters = $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] ?? [];
                // Synonym filter is applied prior to casing/stemming checks
                array_unshift($filters, 'topdata_synonym_filter');
                $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] = $filters;
            }
        }

        $this->decorated->createIndex($definition, $index, $alias, $config);
    }
}
```

#### Register Decorator in Dependency Injection:
```xml
<!-- [MODIFY] src/Resources/config/services.xml -->
```
```xml
        <!-- Elasticsearch IndexCreator Decorator for dynamic synonym injection -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Elasticsearch\IndexCreatorDecorator"
                 decorates="Shopware\Elasticsearch\Framework\Indexing\IndexCreator"
                 public="true">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Elasticsearch\IndexCreatorDecorator.inner"/>
            <argument type="service" id="OpenSearch\Client"/>
            <argument>%elasticsearch.hosts%</argument>
            <argument>%elasticsearch.analysis%</argument>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
        </service>
```

---

### Phase 4: Create Synonym Admin Panel Module
We will implement an administration module allowing merchants to add, edit, and delete synonym mappings directly in Shopware Admin.

#### 1. Define Translation Snippets (German / English):
```json
// [NEW FILE] src/Resources/app/administration/src/snippet/de-DE.json
```
```json
{
    "topdata-es-synonym": {
        "title": "Synonyme",
        "description": "Verwaltung von Suchsynonymen für Elasticsearch",
        "listTitle": "Suchsynonyme",
        "columnTerm": "Suchbegriff (Term)",
        "columnSynonyms": "Zugeordnete Synonyme (Komma-getrennt)",
        "columnCreatedAt": "Erstellt am",
        "buttonAdd": "Synonym hinzufügen",
        "modalTitleAdd": "Neues Synonym erstellen",
        "modalTitleEdit": "Synonym bearbeiten",
        "labelTerm": "Suchbegriff (z.B. klopapier)",
        "labelSynonyms": "Synonymgruppe (z.B. toilettenpapier, wc-papier)",
        "placeholderSynonyms": "synonym1, synonym2, synonym3",
        "saveSuccess": "Synonym erfolgreich gespeichert."
    }
}
```

```json
// [NEW FILE] src/Resources/app/administration/src/snippet/en-GB.json
```
```json
{
    "topdata-es-synonym": {
        "title": "Synonyms",
        "description": "Manage search synonyms for Elasticsearch",
        "listTitle": "Search Synonyms",
        "columnTerm": "Search Term",
        "columnSynonyms": "Mapped Synonyms (Comma-separated)",
        "columnCreatedAt": "Created At",
        "buttonAdd": "Add Synonym",
        "modalTitleAdd": "Create New Synonym",
        "modalTitleEdit": "Edit Synonym",
        "labelTerm": "Search Term (e.g. klopapier)",
        "labelSynonyms": "Synonym Group (e.g. toilettenpapier, wc-papier)",
        "placeholderSynonyms": "synonym1, synonym2, synonym3",
        "saveSuccess": "Synonym saved successfully."
    }
}
```

#### 2. Create Admin Page template and script:
```typescript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts
```
```typescript
const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-synonym-list', {
    template: `
<div class="topdata-es-synonym-list">
    <sw-page class="topdata-es-synonym-list-page">
        <template #smart-bar-header>
            <h2>{{ $tc('topdata-es-synonym.title') }}</h2>
        </template>

        <template #smart-bar-actions>
            <sw-button variant="primary" @click="onAddSynonym">
                {{ $tc('topdata-es-synonym.buttonAdd') }}
            </sw-button>
        </template>

        <template #content>
            <sw-entity-listing
                v-if="items"
                :dataSource="items"
                :columns="columns"
                :repository="repository"
                :criteria-limit="limit"
                :show-settings="true"
                :show-selection="false"
                :allow-view="false"
                :allow-edit="true"
                :allow-delete="true"
                :allow-inline-edit="false"
                :full-page="true"
                :sort-by="sortBy"
                :sort-direction="sortDirection"
                :is-loading="isLoading"
                @page-change="onPageChange"
                @column-sort="onSortColumn"
                @edit="onEditSynonym"
            >
                <template #column-createdAt="{ item }">
                    {{ item.createdAt | date(true) }}
                </template>
            </sw-entity-listing>

            <sw-modal
                v-if="activeModal"
                :title="activeModalTitle"
                @modal-close="onCloseModal"
            >
                <sw-text-field
                    v-model="currentEntity.term"
                    required
                    :label="$tc('topdata-es-synonym.labelTerm')"
                ></sw-text-field>

                <sw-textarea-field
                    v-model="currentEntity.synonyms"
                    required
                    :label="$tc('topdata-es-synonym.labelSynonyms')"
                    :placeholder="$tc('topdata-es-synonym.placeholderSynonyms')"
                ></sw-textarea-field>

                <template #modal-footer>
                    <sw-button size="small" @click="onCloseModal">
                        {{ $tc('global.default.cancel') }}
                    </sw-button>
                    <sw-button variant="primary" size="small" @click="onSaveSynonym">
                        {{ $tc('global.default.save') }}
                    </sw-button>
                </template>
            </sw-modal>
        </template>
    </sw-page>
</div>
    `,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('listing'),
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            items: null,
            isLoading: true,
            sortBy: 'term',
            sortDirection: 'ASC',
            limit: 25,
            activeModal: false,
            currentEntity: null,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('topdata_es_synonym');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('topdata-es-synonym.columnTerm'),
                allowResize: true,
                primary: true,
                sortable: true,
            }, {
                property: 'synonyms',
                label: this.$tc('topdata-es-synonym.columnSynonyms'),
                allowResize: true,
            }, {
                property: 'createdAt',
                label: this.$tc('topdata-es-synonym.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },

        activeModalTitle() {
            if (!this.currentEntity) return '';
            return this.currentEntity.isNew()
                ? this.$tc('topdata-es-synonym.modalTitleAdd')
                : this.$tc('topdata-es-synonym.modalTitleEdit');
        },
    },

    methods: {
        getList() {
            this.isLoading = true;

            const criteria = new Criteria(this.page, this.limit);
            criteria.addSorting(Criteria.sort(this.sortBy, this.sortDirection));

            this.repository.search(criteria).then((result) => {
                this.total = result.total;
                this.items = result;
                this.isLoading = false;
            }).catch(() => {
                this.isLoading = false;
            });
        },

        onAddSynonym() {
            this.currentEntity = this.repository.create();
            this.currentEntity.term = '';
            this.currentEntity.synonyms = '';
            this.activeModal = true;
        },

        onEditSynonym(item) {
            this.currentEntity = item;
            this.activeModal = true;
        },

        onCloseModal() {
            this.activeModal = false;
            this.currentEntity = null;
            this.getList();
        },

        onSaveSynonym() {
            if (!this.currentEntity.term.trim() || !this.currentEntity.synonyms.trim()) {
                return;
            }

            this.isLoading = true;
            this.repository.save(this.currentEntity).then(() => {
                this.createNotificationSuccess({
                    message: this.$tc('topdata-es-synonym.saveSuccess'),
                });
                this.onCloseModal();
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
    },
});
```

#### 3. Register the Synonym Admin Module:
```typescript
// [NEW FILE] src/Resources/app/administration/src/module/topdata-es-synonym/index.ts
```
```typescript
import './page/synonym-list';

Shopware.Module.register('topdata-es-synonym', {
    type: 'plugin',
    name: 'Synonyms',
    title: 'topdata-es-synonym.title',
    description: 'topdata-es-synonym.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-synonym-list',
            path: 'list',
            meta: {
                privilege: 'system.zero_search.viewer',
            },
        },
    },

    navigation: [{
        id: 'topdata-es-synonym-list',
        label: 'topdata-es-synonym.listTitle',
        color: '#189eff',
        path: 'topdata.es.synonym.list',
        parent: 'topdata-es-zero-search', // Nest under Zero Search parent menu item
    }],
});
```

#### 4. Import Module in Administration entry point:
```typescript
// [MODIFY] src/Resources/app/administration/src/main.ts
```
```typescript
import './module/topdata-es-zero-search';
import './module/topdata-es-synonym';
```

---

### Phase 5: Re-indexing & Verification testing
Re-index to rebuild the index mapping with the new `topdata_synonym_filter` integration:

```bash
# Re-index all database records
php bin/console es:reset
php bin/console es:index --no-queue
php bin/console es:create:alias
```

#### Verification Testing:
1. Access the Shopware Admin Area under **Content > Zero Search Results > Search Synonyms**.
2. Add a new synonym rule: 
   * **Term:** `wc-papier`
   * **Synonym group:** `klopapier, toilettenpapier`
3. Execute a reindex from terminal.
4. Run `php bin/console topdata:debug:search "klopapier"`. Verify that products containing `"WC-Papier"` are found and match via the synonym-expanded mapping!

---

### Phase 6: Write Implementation Report
Write the final implementation report detailing the changes to `_ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md`.

```markdown
// [NEW FILE] _ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md
```
```markdown
---
filename: "_ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md"
title: "Report: Synonym Administration Module and Dynamic Elasticsearch Index Integration"
createdAt: 2026-07-15 15:21
updatedAt: 2026-07-15 15:21
planFile: "_ai/backlog/active/260715_1520__IMPLEMENTATION_PLAN__synonym_admin_module_and_index_integration.md"
project: "SW6.7 Plugin"
status: completed
completedAt: 2026-07-15 15:28
filesCreated: 8
filesModified: 4
filesDeleted: 0
tags: [elasticsearch, admin-module, synonyms, dal]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Synonym Administration Module and Dynamic Elasticsearch Index Integration

## 1. Summary
Implemented a full synonym management interface in the Shopware 6.7 Admin area using the standard DAL pattern. Intercepted index generation by decorating Shopware's `IndexCreator` to dynamically inject the synonyms from the database into the Elasticsearch settings.

## 2. Files Changed
### New Files Created
* `src/Entity/Synonym/SynonymEntity.php`
* `src/Entity/Synonym/SynonymCollection.php`
* `src/Entity/Synonym/SynonymEntityDefinition.php`
* `src/Elasticsearch/IndexCreatorDecorator.php`
* `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts`
* `src/Resources/app/administration/src/module/topdata-es-synonym/index.ts`
* `src/Resources/app/administration/src/snippet/de-DE.json`
* `src/Resources/app/administration/src/snippet/en-GB.json`
* `_ai/backlog/reports/260715_1520__IMPLEMENTATION_REPORT__synonym_admin_module_and_index_integration.md`

### Modified Files
* `src/Resources/config/services.xml`
* `src/Service/SynonymService.php`
* `src/Resources/app/administration/src/main.ts`

## 3. Key Changes
* Registered `topdata_es_synonym` as a first-class DAL entity.
* Decorated the `IndexCreator` to pull dynamically defined synonym lines and append them to Elasticsearch analyzer properties safely.
* Installed a dedicated administration dashboard panel for listing, creating, editing, and deleting synonym rules inline.
```

