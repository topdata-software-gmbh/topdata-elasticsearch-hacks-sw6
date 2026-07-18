---
filename: "_ai/backlog/active/260718_1700__implement_scoped_synonyms.md"
title: "Implement Scoped-Single-Table Synonyms for Category and Product Search"
createdAt: 2026-07-18 17:00
updatedAt: 2026-07-18 17:00
status: draft
priority: high
tags: [shopware, elasticsearch, synonyms, category-search, migrations]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Scoped-Single-Table Synonyms

## 1. Problem Statement
Currently, search synonym mapping is strictly used for Elasticsearch product indexing. However, category search (`CategorySearchService`) uses direct database-driven query-time search via Shopware's Data Abstraction Layer (DAL) `ContainsFilter` on the category name. Customers searching for category terms using synonyms (e.g., searching "Klopapier" when the category name is "Toilettenpapier") will find no matching categories. 

Reusing synonym mappings for category search is desirable, but some rules might be specific to products (Elasticsearch indexing logic) or categories (MySQL database queries), while most are globally relevant. There is currently no way to categorize or scope these synonym rules, and Category Search lacks any synonym lookup logic.

## 2. Executive Summary
This plan implements the **Scoped-Single-Table Approach** to reuse the database-stored synonyms without schema bloat:
1. **Schema Extension**: Add a `scope` column (`global`, `product`, `category`) to the existing `topdata_es_synonym` database table.
2. **Entity & Service Expansion**: Update the DAL `SynonymEntityDefinition` and expand `SynonymService` to support scoping, parsing bracketed scope prefixes in imported text files (e.g., `[product] term => synonyms`), and query-time synonym expansion.
3. **Product Search Integration**: Filter Elasticsearch index-time synonyms to only register rules with `global` or `product` scope.
4. **Category Search Integration**: Query the database at run-time for `global` or `category` synonyms matching the user's search term, and dynamically build an `OrFilter` across all expanded terms.
5. **Administration Interface**: Expose the `scope` selection in the synonym administration listing and modals.
6. **Project Command Alignment**: Align CLI commands with Topdata Foundation standards (using `CliLogger`).

## 3. Project Environment Details
- Project Name: SW6.7 Plugin
- Backend root: src
- PHP Version: 8.2 / 8.3 / 8.4

---

## 4. Implementation Phases

### Phase 1: Database Schema & Entity Definition
Add the `scope` column to the database and map it inside the Shopware Data Abstraction Layer (DAL).

#### [NEW FILE] `src/Migration/Migration1752840000AddScopeToSynonymTable.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752840000AddScopeToSynonymTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752840000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `topdata_es_synonym`
            ADD COLUMN `scope` VARCHAR(50) NOT NULL DEFAULT "global" AFTER `synonyms`
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

#### [MODIFY] `src/Entity/Synonym/SynonymEntity.php`
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
    protected string $scope;

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

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setScope(string $scope): void
    {
        $this->scope = $scope;
    }
}
```

#### [MODIFY] `src/Entity/Synonym/SynonymEntityDefinition.php`
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
            (new StringField('scope', 'scope'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
        ]);
    }
}
```

---

### Phase 2: Core Synonym Service Business Logic
Modify `SynonymService` to:
1. Export only specific scope rules for Elasticsearch.
2. Parse bracketed scope definitions in text import files (e.g., `[product] term => synonyms`).
3. Support query-time term expansion for Category search.

#### [MODIFY] `src/Service/SynonymService.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class SynonymService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<array{term: string, synonyms: string, scope: string, created_at: string}>
     */
    public function listSynonyms(?string $filter = null, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms', 'scope', 'created_at')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($filter !== null && $filter !== '') {
            $qb->where('term LIKE :filter OR synonyms LIKE :filter OR scope LIKE :filter')
                ->setParameter('filter', '%' . $filter . '%');
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function deleteSynonym(string $term): bool
    {
        $deleted = $this->connection->executeStatement(
            'DELETE FROM `topdata_es_synonym` WHERE `term` = :term',
            ['term' => mb_strtolower(trim($term))]
        );

        return $deleted > 0;
    }

    public function clearAllSynonyms(): int
    {
        return (int) $this->connection->executeStatement('TRUNCATE TABLE `topdata_es_synonym`');
    }

    public function validateFile(string $filePath): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return [['line' => 0, 'content' => '', 'error' => 'File does not exist or is unreadable.']];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [['line' => 0, 'content' => '', 'error' => 'Could not read file content.']];
        }

        return $this->validateString($content);
    }

    /**
     * @return array<array{line: int, content: string, error: string}>
     */
    public function validateString(string $content): array
    {
        $lines = explode("\n", $content);
        $errors = [];

        foreach ($lines as $index => $line) {
            $lineNumber = $index + 1;
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
                continue;
            }

            $parts = explode('=>', $trimmed, 2);
            if (count($parts) !== 2) {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Missing expected mapping delimiter "=>"'
                ];
                continue;
            }

            $termPart = trim($parts[0]);
            $synonyms = trim($parts[1]);

            // Parse optional scope bracket, e.g. [product] term
            if (preg_match('/^\[(global|product|category)\]\s*(.+)$/i', $termPart, $matches)) {
                $term = trim($matches[2]);
            } else {
                $term = $termPart;
            }

            if ($term === '') {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Left-hand search term cannot be blank'
                ];
            }

            if ($synonyms === '') {
                $errors[] = [
                    'line' => $lineNumber,
                    'content' => $line,
                    'error' => 'Right-hand synonyms mapping block cannot be blank'
                ];
            }
        }

        return $errors;
    }

    public function importFromFile(string $filePath, bool $dryRun = false): int
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException(sprintf('File "%s" does not exist or is not readable.', $filePath));
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Could not read content from file "%s".', $filePath));
        }

        return $this->importFromString($content, $dryRun);
    }

    public function importFromString(string $content, bool $dryRun = false): int
    {
        $errors = $this->validateString($content);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(sprintf('Cannot import. Found %d syntax errors in the content.', count($errors)));
        }

        $lines = explode("\n", $content);
        $importedCount = 0;

        if ($dryRun) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                    continue;
                }
                $importedCount++;
            }
            return $importedCount;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                    continue;
                }

                $parts = explode('=>', $line, 2);
                $termPart = trim($parts[0]);
                $synonyms = mb_strtolower(trim($parts[1]));

                $scope = 'global';
                if (preg_match('/^\[(global|product|category)\]\s*(.+)$/i', $termPart, $matches)) {
                    $scope = strtolower($matches[1]);
                    $term = mb_strtolower(trim($matches[2]));
                } else {
                    $term = mb_strtolower($termPart);
                }

                $this->connection->executeStatement(
                    'INSERT INTO `topdata_es_synonym` (`id`, `term`, `synonyms`, `scope`, `created_at`)
                     VALUES (:id, :term, :synonyms, :scope, :now)
                     ON DUPLICATE KEY UPDATE `synonyms` = :synonyms, `scope` = :scope, `created_at` = :now',
                    [
                        'id' => Uuid::randomBytes(),
                        'term' => $term,
                        'synonyms' => $synonyms,
                        'scope' => $scope,
                        'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                    ]
                );

                $importedCount++;
            }
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $importedCount;
    }

    /**
     * Export synonym lines without prefixes for standard ES config usage.
     * Only exports rules whose scope matches the requested target (e.g., 'product' retrieves both 'global' and 'product').
     *
     * @return string[]
     */
    public function exportToArray(?string $targetScope = null): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms', 'scope')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC');

        if ($targetScope !== null) {
            $qb->where('scope = :scope OR scope = "global"')
                ->setParameter('scope', $targetScope);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $rules = [];

        foreach ($rows as $row) {
            $rules[] = sprintf('%s => %s', trim($row['term']), trim($row['synonyms']));
        }

        return $rules;
    }

    /**
     * Export database content to a round-trippable backup format including [scope] prefixes.
     */
    public function exportToString(?string $targetScope = null): string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms', 'scope')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC');

        if ($targetScope !== null) {
            $qb->where('scope = :scope OR scope = "global"')
                ->setParameter('scope', $targetScope);
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $lines = ["# Elasticsearch Synonyms Mapping File", "# Generated: " . (new \DateTime())->format('Y-m-d H:i:s')];

        foreach ($rows as $row) {
            $lines[] = sprintf('[%s] %s => %s', $row['scope'], $row['term'], $row['synonyms']);
        }

        return implode("\n", $lines);
    }

    /**
     * Resolves matching expanded synonym terms for run-time query expansion.
     *
     * @return string[]
     */
    public function getExpandedTerms(string $term, string $targetScope): array
    {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $qb->select('synonyms')
            ->from('topdata_es_synonym')
            ->where('term = :term')
            ->andWhere('scope = :scope OR scope = "global"')
            ->setParameter('term', $term)
            ->setParameter('scope', $targetScope);

        $synonymsString = $qb->executeQuery()->fetchOne();

        if ($synonymsString === false || $synonymsString === '') {
            return [$term];
        }

        $synonyms = array_map('trim', explode(',', $synonymsString));
        $synonyms = array_filter($synonyms, fn($s) => $s !== '');

        return array_unique(array_merge([$term], $synonyms));
    }
}
```

---

### Phase 3: Integration into Category Search
Update the `CategorySearchService` to utilize runtime query-time expansion.

#### [MODIFY] `src/Resources/config/services.xml`
```xml
        <!-- Category Search Service (shared logic for suggest and full page) -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService">
            <argument type="service" id="sales_channel.category.repository" key="$categoryRepository"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService" key="$systemConfigService"/>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
        </service>
```

#### [MODIFY] `src/Service/CategorySearchService.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Shopware\Core\Content\Category\CategoryCollection;
use Shopware\Core\Content\Category\CategoryDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class CategorySearchService
{
    private const DB_FETCH_LIMIT = 50;

    public function __construct(
        private readonly SalesChannelRepository $categoryRepository,
        private readonly SystemConfigService $systemConfigService,
        private readonly SynonymService $synonymService,
    ) {
    }

    public function search(
        string $term,
        SalesChannelContext $salesChannelContext,
        ?int $displayLimit = null,
    ): array {
        $salesChannelId = $salesChannelContext->getSalesChannel()->getId();

        if ($displayLimit === null) {
            $displayLimit = (int) $this->systemConfigService->get(
                'TopdataElasticsearchHacksSW6.config.categorySuggestLimit',
                $salesChannelId
            ) ?: 8;
        }

        $criteria = new Criteria();
        $criteria->setLimit(self::DB_FETCH_LIMIT);

        // Run synonym expansion
        $expandedTerms = $this->synonymService->getExpandedTerms($term, 'category');
        if (count($expandedTerms) > 1) {
            $orFilter = new OrFilter();
            foreach ($expandedTerms as $expandedTerm) {
                $orFilter->addQuery(new ContainsFilter('name', $expandedTerm));
            }
            $criteria->addFilter($orFilter);
        } else {
            $criteria->addFilter(new ContainsFilter('name', $term));
        }

        $criteria->addFilter(new EqualsFilter('active', true));
        $criteria->addFilter(new EqualsFilter('visible', true));
        $criteria->addFilter(new EqualsFilter('type', CategoryDefinition::TYPE_PAGE));
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

        $result = $this->categoryRepository->search($criteria, $salesChannelContext);
        $total = $result->getTotal();

        if ($result->count() === 0) {
            return ['categories' => new CategoryCollection(), 'total' => 0];
        }

        $entities = $result->getEntities();
        $entities->sort(fn ($a, $b) => $this->sortByRelevance($a, $b, $term));

        $trimmed = new CategoryCollection();
        $i = 0;
        foreach ($entities as $entity) {
            if ($i >= $displayLimit) {
                break;
            }
            $trimmed->add($entity);
            $i++;
        }

        return ['categories' => $trimmed, 'total' => $total];
    }

    private function sortByRelevance($a, $b, string $term): int
    {
        $aName = mb_strtolower($a->getTranslation('name') ?? $a->getName() ?? '');
        $bName = mb_strtolower($b->getTranslation('name') ?? $b->getName() ?? '');
        $termLower = mb_strtolower($term);

        $aExact = $aName === $termLower;
        $bExact = $bName === $termLower;

        if ($aExact !== $bExact) {
            return $aExact ? -1 : 1;
        }

        $aStartsWith = str_starts_with($aName, $termLower);
        $bStartsWith = str_starts_with($bName, $termLower);

        if ($aStartsWith !== $bStartsWith) {
            return $aStartsWith ? -1 : 1;
        }

        return ($a->getLevel() ?? 0) <=> ($b->getLevel() ?? 0);
    }
}
```

---

### Phase 4: Product Search Filtering (Index Config Integration)
Update the Elasticsearch subscriber to only extract `product` and `global` synonyms during the re-indexing event.

#### [MODIFY] `src/Subscriber/ElasticsearchIndexConfigSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Shopware\Elasticsearch\Framework\Indexing\Event\ElasticsearchIndexConfigEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

class ElasticsearchIndexConfigSubscriber implements EventSubscriberInterface
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        $this->synonymService = $synonymService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchIndexConfigEvent::class => 'onIndexConfig',
        ];
    }

    public function onIndexConfig(ElasticsearchIndexConfigEvent $event): void
    {
        // Only extract global or product-scoped synonym rules for Elasticsearch indexing config
        $synonymRules = $this->synonymService->exportToArray('product');

        $config = $event->getConfig();

        if (empty($synonymRules)) {
            $event->setConfig($config);
            return;
        }

        $config['settings']['analysis']['filter']['topdata_synonym_filter'] = [
            'type' => 'synonym',
            'synonyms' => $synonymRules,
            'ignore_case' => true,
        ];

        if (isset($config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer'])) {
            $config['settings']['analysis']['analyzer']['topdata_delimiter_analyzer']['filter'] = [
                'lowercase',
                'topdata_synonym_filter',
                'topdata_word_delimiter',
            ];
        }

        $swSearchAnalyzers = ['sw_whitespace_analyzer', 'sw_german_analyzer', 'sw_english_analyzer'];
        foreach ($swSearchAnalyzers as $analyzerName) {
            if (!isset($config['settings']['analysis']['analyzer'][$analyzerName])) {
                continue;
            }

            $filters = $config['settings']['analysis']['analyzer'][$analyzerName]['filter'] ?? [];

            if (in_array('topdata_synonym_filter', $filters, true)) {
                continue;
            }

            $insertAt = 0;
            $lowercasePos = array_search('lowercase', $filters, true);
            if ($lowercasePos !== false) {
                $insertAt = $lowercasePos + 1;
            }

            array_splice($filters, $insertAt, 0, ['topdata_synonym_filter']);
            $config['settings']['analysis']['analyzer'][$analyzerName]['filter'] = $filters;
        }

        $event->setConfig($config);
    }
}
```

---

### Phase 5: Administration UI Updates
Expose the `scope` column and selector inside the synonym administration listing and input modals.

#### [MODIFY] `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts`
```typescript
import template from './synonym-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-synonym-list', {
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
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnTerm'),
                allowResize: true,
                primary: true,
                sortable: true,
            }, {
                property: 'synonyms',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnSynonyms'),
                allowResize: true,
            }, {
                property: 'scope',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnScope'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.columnCreatedAt'),
                allowResize: true,
                sortable: true,
            }];
        },

        activeModalTitle() {
            if (!this.currentEntity) return '';
            return this.currentEntity.isNew()
                ? this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.modalTitleAdd')
                : this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.modalTitleEdit');
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

        onAddSynonym() {
            this.currentEntity = this.repository.create();
            this.currentEntity.term = '';
            this.currentEntity.synonyms = '';
            this.currentEntity.scope = 'global';
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
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.saveSuccess'),
                });
                this.onCloseModal();
            }).catch(() => {
                this.isLoading = false;
            });
        },
    },
});
```

#### [MODIFY] `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/synonym-list.html.twig`
```html
<sw-page class="topdata-es-synonym-list-page">
    <template #smart-bar-header>
        <h2>{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button variant="primary" @click="onAddSynonym">
            {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.buttonAdd') }}
        </sw-button>
    </template>

    <template #content>
        <sw-entity-listing
            v-if="items"
            :dataSource="items"
            :columns="columns"
            :repository="repository"
            identifier="topdata-es-synonym"
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
            <template #column-scope="{ item }">
                <sw-label v-if="item.scope === 'global'" variant="neutral" size="medium">
                    {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeGlobal') }}
                </sw-label>
                <sw-label v-else-if="item.scope === 'product'" variant="info" size="medium">
                    {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeProduct') }}
                </sw-label>
                <sw-label v-else-if="item.scope === 'category'" variant="success" size="medium">
                    {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeCategory') }}
                </sw-label>
            </template>

            <template #column-createdAt="{ item }">
                <sw-time-ago :date="item.createdAt" :date-time-format="{ month: '2-digit', day: '2-digit' }" />
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
                :label="$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.labelTerm')"
            ></sw-text-field>

            <sw-textarea-field
                v-model="currentEntity.synonyms"
                required
                :label="$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.labelSynonyms')"
                :placeholder="$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.placeholderSynonyms')"
            ></sw-textarea-field>

            <sw-select-field
                v-model="currentEntity.scope"
                :label="$tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.labelScope')"
                required
            >
                <option value="global">{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeGlobal') }}</option>
                <option value="product">{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeProduct') }}</option>
                <option value="category">{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-synonym.scopeCategory') }}</option>
            </sw-select-field>

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
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/de-DE.json`
```json
        "topdata-es-synonym":     {
            "description":         "Verwaltung von Suchsynonymen für Elasticsearch",
            "listTitle":           "Suchsynonyme",
            "columnTerm":          "Suchbegriff (Term)",
            "columnSynonyms":      "Zugeordnete Synonyme (Komma-getrennt)",
            "columnScope":         "Gültigkeitsbereich",
            "columnCreatedAt":     "Erstellt am",
            "buttonAdd":           "Synonym hinzufügen",
            "modalTitleAdd":       "Neues Synonym erstellen",
            "modalTitleEdit":      "Synonym bearbeiten",
            "labelTerm":           "Suchbegriff (z.B. klopapier)",
            "labelSynonyms":       "Synonymgruppe (z.B. toilettenpapier, wc-papier)",
            "labelScope":          "Gültigkeitsbereich",
            "scopeGlobal":         "Global (Produkte & Kategorien)",
            "scopeProduct":        "Nur Produkte (Elasticsearch)",
            "scopeCategory":       "Nur Kategorien (Datenbank)",
            "placeholderSynonyms": "synonym1, synonym2, synonym3",
            "saveSuccess":         "Synonym erfolgreich gespeichert."
        }
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/en-GB.json`
```json
        "topdata-es-synonym":     {
            "title":               "Synonyms",
            "description":         "Manage search synonyms for Elasticsearch",
            "listTitle":           "Search Synonyms",
            "columnTerm":          "Search Term",
            "columnSynonyms":      "Mapped Synonyms (Comma-separated)",
            "columnScope":         "Scope",
            "columnCreatedAt":     "Created At",
            "buttonAdd":           "Add Synonym",
            "modalTitleAdd":       "Create New Synonym",
            "modalTitleEdit":      "Edit Synonym",
            "labelTerm":           "Search Term (e.g. klopapier)",
            "labelSynonyms":       "Synonym Group (e.g. toilettenpapier, wc-papier)",
            "labelScope":          "Scope",
            "scopeGlobal":         "Global (Products & Categories)",
            "scopeProduct":        "Products Only (Elasticsearch)",
            "scopeCategory":       "Categories Only (Database)",
            "placeholderSynonyms": "synonym1, synonym2, synonym3",
            "saveSuccess":         "Synonym saved successfully."
        }
```

---

### Phase 6: CLI Command Alignment
Align commands with the Topdata foundation codebase guidelines using `CliLogger`.

#### [MODIFY] `src/Command/Command_ImportSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:es-hacks:import-synonyms',
    description: 'Import synonym mapping rules from a generated text file back into the store database'
)]
class Command_ImportSynonyms extends TopdataFoundationSW6
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the text file containing synonym rules (one rule per line: term => synonym1, synonym2)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and preview the import count without updating the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $count = $this->synonymService->importFromFile($filePath, $dryRun);
            if ($dryRun) {
                CliLogger::info(sprintf('[Dry-Run] Mappings checked. %d synonym rule(s) are valid and ready to import.', $count));
            } else {
                CliLogger::success(sprintf('Successfully imported %d synonym rule(s).', $count));
            }
        } catch (\Throwable $e) {
            CliLogger::error('Import failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
```

#### [MODIFY] `src/Command/Command_ListSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\TopdataFoundationSW6;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:es-hacks:list-synonyms',
    description: 'View and filter synonym records currently active in the database store'
)]
class Command_ListSynonyms extends TopdataFoundationSW6
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Search terms or synonyms containing this substring')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of synonym lines to list', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset count for manual pagination', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');

        try {
            $list = $this->synonymService->listSynonyms($filter, $limit, $offset);
        } catch (\Throwable $e) {
            CliLogger::error('Failed to fetch synonyms: ' . $e->getMessage());
            return self::FAILURE;
        }

        if (empty($list)) {
            CliLogger::info('No active synonym definitions found in database.');
            return self::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Target Search Term', 'Scope', 'Mapped Synonym Group', 'Created/Modified At']);
        foreach ($list as $row) {
            $table->addRow([$row['term'], $row['scope'], $row['synonyms'], $row['created_at']]);
        }
        $table->render();

        return self::SUCCESS;
    }
}
```

---

### Phase 7: Validation, Reindexing, & Report Generation
Run checks to verify schema updates, compile the frontend, reindex, and record the implementation details.

1. **Schema Update Execution:**
   Run migrations from inside the container:
   ```bash
   php bin/console database:migrate TopdataElasticsearchHacksSW6 --all
   ```

2. **Frontend Rebuild (Vite):**
   Compile Vite assets to compile JS/CSS changes for administration:
   ```bash
   composer build:js:admin
   ```

3. **Elasticsearch Mappings Refresh:**
   If you have updated existing synonyms and assigned them scopes, refresh indices to flush the `topdata_synonym_filter` configuration mapping:
   ```bash
   php bin/console es:reset
   php bin/console es:index --no-queue
   php bin/console es:create:alias
   ```

4. **Compile Report File:**
   Generate the output report located in `_ai/backlog/reports/` using the template below.

#### [NEW FILE] `_ai/backlog/reports/260718_1700__IMPLEMENTATION_REPORT__implement_scoped_synonyms.md`
```markdown
---
filename: "_ai/backlog/reports/260718_1700__IMPLEMENTATION_REPORT__implement_scoped_synonyms.md"
title: "Report: Implement Scoped-Single-Table Synonyms for Category and Product Search"
createdAt: 2026-07-18 17:00
updatedAt: 2026-07-18 17:00
planFile: "_ai/backlog/active/260718_1700__IMPLEMENTATION_PLAN__implement_scoped_synonyms.md"
project: "SW6.7 Plugin"
status: completed
filesCreated: 2
filesModified: 10
filesDeleted: 0
tags: [shopware, elasticsearch, synonyms, category-search, reports]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The scoped-single-table synonym approach has been successfully implemented. The synonyms table (`topdata_es_synonym`) now has a `scope` column supporting 'global', 'product', and 'category' settings. Elasticsearch index configuration only grabs 'global' and 'product' scoped synonyms, while category searches are expanded at query time via PHP parsing using matching 'global' or 'category' rules. The Administration panel has been updated to support and display the scope field.

## 2. Files Changed
- **New Files:**
  - `src/Migration/Migration1752840000AddScopeToSynonymTable.php` (DB structure migration)
  - `_ai/backlog/reports/260718_1700__IMPLEMENTATION_REPORT__implement_scoped_synonyms.md` (Self)
- **Modified Files:**
  - `src/Entity/Synonym/SynonymEntity.php` (DAL object model mappings)
  - `src/Entity/Synonym/SynonymEntityDefinition.php` (DAL database mappings)
  - `src/Service/SynonymService.php` (Scoping, export logic, query expansion getters, file parser upgrades)
  - `src/Resources/config/services.xml` (Constructor DI matching)
  - `src/Service/CategorySearchService.php` (Query-time OR filtering lookup)
  - `src/Subscriber/ElasticsearchIndexConfigSubscriber.php` (Product config scope selection limit)
  - `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/index.ts` (Scope column configuration & defaults)
  - `src/Resources/app/administration/src/module/topdata-es-synonym/page/synonym-list/synonym-list.html.twig` (Scope modal field)
  - `src/Resources/app/administration/src/snippet/de-DE.json` (German scope UI translations)
  - `src/Resources/app/administration/src/snippet/en-GB.json` (English scope UI translations)
  - `src/Command/Command_ImportSynonyms.php` (Scope output updates using TopdataFoundation CliLogger)
  - `src/Command/Command_ListSynonyms.php` (Scope table rendering using TopdataFoundation CliLogger)

## 3. Key Changes
- Extends synonym configuration mapping with a backward-compatible bracket prefix parser (`[product] term => synonyms`).
- Directs index configuration processes to skip indexing synonyms explicitly scoped to `'category'`.
- Introduces real-time database-driven synonym queries on the Storefront category search page loader and suggestions via automated SQL `OrFilter` expansion.
- Provides unified, localized admin interface labels matching standard Shopware design components.

## 4. Technical Decisions
- **Query-Time Expansion vs Sync-Time Categories:** Because Category Search runs primarily on direct relational databases rather than Elasticsearch indices in Shopware 6.7, query-time expansion was selected to keep the implementation decoupled and highly performant.
- **Explicit Scoping Prefix Format:** Opted for bracketed prefixes (`[scope]`) within text files because it preserves the simple single-line formatting of synonym files while offering a robust way to specify metadata.
```

