---
filename: "_ai/backlog/active/260720_2152__IMPLEMENTATION_PLAN__advanced_search_logging.md"
title: "Advanced Search Logging and Fragment Consolidation"
createdAt: 2026-07-20 21:52
updatedAt: 2026-07-20 21:52
status: draft
priority: high
tags: [shopware, elasticsearch, analytics, sessions, database]
estimatedComplexity: complex
documentType: IMPLEMENTATION_PLAN
---

# Advanced Search Logging and Fragment Consolidation Plan

## 1. Problem Description
Currently, the plugin only logs search queries when they return zero results. Furthermore, it only records them when a user lands on the full search page (`ProductEvents::PRODUCT_SEARCH_RESULT` [lessons-learned.md]), leaving interactive suggestion dropdown requests unmonitored. 

When suggestions are monitored, fast-typing users generate progressive fragments (e.g., `"w"`, `"wc"`, `"wc-"`, `"wc-pa"`, `"wc-papier"`) which flood the database with incomplete words. Logging every raw input degrades performance and yields inaccurate analytical metrics. The system needs to log all searches (successful and failed), track them via a persistent session key, and intelligently clean up typing fragments.

## 2. Executive Summary
This plan updates the search logging architecture to a **two-table transaction/aggregation design**:
1. **Raw Log Table (`tdeh_search_log`)**: Stores every raw query instantly, containing the user session token, the query term, and the result count.
2. **Aggregated Stats Table (`tdeh_search_stats`)**: Replaces the old `tdeh_zero_search` table, tracking clean consolidated search stats (total queries, zero-result counts, average result counts).

An asynchronous **Scheduled Task and CLI command** processes raw logs in the background. It groups logs by session token, analyzes chronological typing progressions using a Levenshtein edit-distance and prefix-matching heuristic, discards partial keystroke fragments, extracts the final intended search queries, aggregates them into the stats table, and purges the processed raw data.

---

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin
- **Backend Root:** `src`
- **PHP Version:** 8.2 / 8.3 / 8.4
- **Conventions:** Shopware 6.7 Plugin Conventions, `TopdataFoundationSW6` base classes, and `CliLogger` for console output.

---

## 4. Implementation Phases

### Phase 1: Database Migration
We will create a new migration to introduce the raw log table (`tdeh_search_log`) and the aggregated stats table (`tdeh_search_stats`), migrate any existing data from the deprecated `tdeh_zero_search` table, and drop the deprecated table.

#### [NEW FILE] `src/Migration/Migration1752700000CreateSearchLogAndStatsTable.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752700000CreateSearchLogAndStatsTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752700000;
    }

    public function update(Connection $connection): void
    {
        // 1. Create Raw Log Table (unconstrained index structure for fast writes)
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdeh_search_log` (
                `id` BINARY(16) NOT NULL,
                `session_token` VARCHAR(255) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `result_count` INT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                INDEX `idx.tdeh_search_log.session_token` (`session_token`),
                INDEX `idx.tdeh_search_log.created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // 2. Create Aggregated Stats Table
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `tdeh_search_stats` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `count` INT NOT NULL DEFAULT 1,
                `zero_count` INT NOT NULL DEFAULT 0,
                `avg_result_count` INT NOT NULL DEFAULT 0,
                `created_at` DATETIME(3) NOT NULL,
                `last_searched_at` DATETIME(3) NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.tdeh_search_stats.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        // 3. Migrate old data from tdeh_zero_search if it exists
        try {
            $hasOldTable = $connection->fetchOne("SHOW TABLES LIKE 'tdeh_zero_search'") !== false;
            if ($hasOldTable) {
                $connection->executeStatement('
                    INSERT INTO `tdeh_search_stats` (`id`, `term`, `count`, `zero_count`, `avg_result_count`, `created_at`, `last_searched_at`)
                    SELECT `id`, `term`, `count`, `count` AS `zero_count`, 0 AS `avg_result_count`, `created_at`, `last_searched_at`
                    FROM `tdeh_zero_search`
                    ON DUPLICATE KEY UPDATE `zero_count` = `tdeh_zero_search`.`count`;
                ');
                $connection->executeStatement('DROP TABLE IF EXISTS `tdeh_zero_search`');
            }
        } catch (\Throwable $e) {
            // Suppress if empty or missing
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
```

---

### Phase 2: Live Search Logging (Subscriber Update)
We will modify the subscriber to capture both suggest dropdown events and standard search page results. Instead of performing immediate upserts, we write a raw, lightweight record with the persistent session token.

#### [MODIFY] `src/Subscriber/ProductSearchSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
use Shopware\Core\Content\Product\Events\ProductSuggestResultEvent;
use Shopware\Core\Content\Product\ProductEvents;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ProductSearchSubscriber implements EventSubscriberInterface
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductEvents::PRODUCT_SEARCH_RESULT => 'onSearchResult',
            ProductEvents::PRODUCT_SUGGEST_RESULT => 'onSearchResult',
        ];
    }

    /**
     * Captures search results and suggestion streams, pushing them to raw logs.
     *
     * @param ProductSearchResultEvent|ProductSuggestResultEvent $event
     */
    public function onSearchResult($event): void
    {
        $result = $event->getResult();
        $term = $result->getCriteria()->getTerm();

        if ($term === null || trim($term) === '') {
            return;
        }

        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        // Ignore noisy single characters
        if (mb_strlen($term) < 2) {
            return;
        }

        $resultCount = $result->getTotal();
        $sessionToken = $event->getSalesChannelContext()->getToken();

        try {
            $this->connection->executeStatement(
                'INSERT INTO `tdeh_search_log` (`id`, `session_token`, `term`, `result_count`, `created_at`)
                 VALUES (:id, :session_token, :term, :result_count, :now)',
                [
                    'id' => Uuid::randomBytes(),
                    'session_token' => $sessionToken,
                    'term' => $term,
                    'result_count' => $resultCount,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
            // Prevent storefront degradation during database locking issues
        }
    }
}
```

---

### Phase 3: Background Consolidation Engine
A CLI command and a scheduled task will process unconsolidated logs, execute the similarity heuristic to detect self-corrected typos and typing progressions, update the aggregated statistics table, and delete processed logs.

#### [NEW FILE] `src/Service/SearchAnalyticsService.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class SearchAnalyticsService
{
    private const TIME_THRESHOLD = 15; // Max seconds between keystrokes/actions for a typing stream

    public function __construct(private readonly Connection $connection)
    {
    }

    public function consolidate(int $batchSize = 100): int
    {
        // 1. Fetch distinct session tokens with old logs (exclude last 1 minute to avoid slicing active typing sessions)
        $safetyMargin = (new \DateTime())->modify('-1 minute')->format('Y-m-d H:i:s.v');

        $tokens = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT `session_token` 
             FROM `tdeh_search_log` 
             WHERE `created_at` < :margin 
             LIMIT :limit',
            ['margin' => $safetyMargin, 'limit' => $batchSize],
            ['margin' => \PDO::PARAM_STR, 'limit' => \PDO::PARAM_INT]
        );

        if (empty($tokens)) {
            return 0;
        }

        $processedCount = 0;

        foreach ($tokens as $token) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT `id`, `term`, `result_count`, `created_at`
                 FROM `tdeh_search_log`
                 WHERE `session_token` = :token AND `created_at` < :margin
                 ORDER BY `created_at` ASC',
                ['token' => $token, 'margin' => $safetyMargin]
            );

            if (empty($rows)) {
                continue;
            }

            $streams = $this->groupIntoStreams($rows);
            $finalIntents = [];

            foreach ($streams as $stream) {
                $finalIntents[] = $this->resolveStreamToIntent($stream);
            }

            $this->connection->beginTransaction();
            try {
                // Upsert final intents into aggregated statistics
                foreach ($finalIntents as $intent) {
                    $this->upsertStat(
                        $intent['term'], 
                        $intent['result_count'], 
                        $intent['created_at']
                    );
                }

                // Delete processed raw log rows
                $ids = array_column($rows, 'id');
                $this->connection->executeStatement(
                    'DELETE FROM `tdeh_search_log` WHERE `id` IN (:ids)',
                    ['ids' => $ids],
                    ['ids' => Connection::PARAM_BINARY_ARRAY]
                );

                $this->connection->commit();
                $processedCount += count($rows);
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }

        return $processedCount;
    }

    private function groupIntoStreams(array $rows): array
    {
        $streams = [];
        $currentStream = [];

        foreach ($rows as $row) {
            if (empty($currentStream)) {
                $currentStream[] = $row;
                continue;
            }

            $prev = end($currentStream);
            $timeDiff = (new \DateTime($row['created_at']))->getTimestamp() - (new \DateTime($prev['created_at']))->getTimestamp();

            if ($timeDiff <= self::TIME_THRESHOLD && $this->isRelated($prev['term'], $row['term'])) {
                $currentStream[] = $row;
            } else {
                $streams[] = $currentStream;
                $currentStream = [$row];
            }
        }

        if (!empty($currentStream)) {
            $streams[] = $currentStream;
        }

        return $streams;
    }

    private function isRelated(string $termA, string $termB): bool
    {
        if (str_starts_with($termB, $termA)) {
            return true; // typing forward
        }

        if (str_starts_with($termA, $termB)) {
            return true; // backspacing
        }

        $levenshtein = levenshtein($termA, $termB);
        $maxLength = max(strlen($termA), strlen($termB));
        $maxAllowedEdits = $maxLength > 6 ? 3 : 2;

        return $levenshtein <= $maxAllowedEdits; // typo correction
    }

    private function resolveStreamToIntent(array $stream): array
    {
        $last = end($stream);

        // If terminal query succeeded, return it
        if ($last['result_count'] > 0) {
            return $last;
        }

        // If the final typed term returned 0, look back to find if they bypassed a good result
        foreach (array_reverse($stream) as $entry) {
            if ($entry['result_count'] > 0) {
                return $entry;
            }
        }

        // Settle on the final term as a zero-result log
        return $last;
    }

    private function upsertStat(string $term, int $resultCount, string $createdAt): void
    {
        $isZero = $resultCount === 0 ? 1 : 0;

        $this->connection->executeStatement(
            'INSERT INTO `tdeh_search_stats` (`id`, `term`, `count`, `zero_count`, `avg_result_count`, `created_at`, `last_searched_at`)
             VALUES (:id, :term, 1, :is_zero, :result_count, :now, :now)
             ON DUPLICATE KEY UPDATE 
                `count` = `count` + 1,
                `zero_count` = `zero_count` + :is_zero,
                `avg_result_count` = ROUND((`avg_result_count` * `count` + :result_count) / (`count` + 1)),
                `last_searched_at` = :now',
            [
                'id' => Uuid::randomBytes(),
                'term' => $term,
                'is_zero' => $isZero,
                'result_count' => $resultCount,
                'now' => $createdAt
            ]
        );
    }
}
```

#### [NEW FILE] `src/Command/Command_ConsolidateSearchLogs.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:es-hacks:consolidate-search-logs',
    description: 'Consolidates raw search logs into aggregated analytics'
)]
class Command_ConsolidateSearchLogs extends AbstractTopdataCommand
{
    public function __construct(private readonly SearchAnalyticsService $analyticsService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Max session tokens to process in one run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');

        CliLogger::info(sprintf('Starting search log consolidation with batch size %d...', $batchSize));

        try {
            $processed = $this->analyticsService->consolidate($batchSize);
            CliLogger::success(sprintf('Consolidation finished. Consolidated %d raw search event(s).', $processed));
        } catch (\Throwable $e) {
            CliLogger::error(sprintf('Consolidation failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Framework/ScheduledTask/ConsolidateSearchLogsTask.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ConsolidateSearchLogsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_es_hacks.consolidate_search_logs';
    }

    public static function getDefaultInterval(): int
    {
        return 3600; // Hourly
    }
}
```

#### [NEW FILE] `src/Framework/ScheduledTask/ConsolidateSearchLogsTaskHandler.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService;

#[AsMessageHandler(handles: ConsolidateSearchLogsTask::class)]
class ConsolidateSearchLogsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        $scheduledTaskRepository,
        private readonly SearchAnalyticsService $analyticsService
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
        try {
            $this->analyticsService->consolidate(250);
        } catch (\Throwable $e) {
            // Log core scheduler exceptions silently
        }
    }
}
```

---

### Phase 4: Data Abstraction Layer & Admin API Rebuilding
We will delete the old `ZeroSearch` entity classes and create the new `SearchStats` entity classes so the Shopware Admin API can load the advanced metrics.

#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchCollection.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntity.php`
#### [DELETE] `src/Entity/ZeroSearch/ZeroSearchEntityDefinition.php`

#### [NEW FILE] `src/Entity/SearchStats/SearchStatsEntity.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class SearchStatsEntity extends Entity
{
    use EntityIdTrait;

    protected string $term;
    protected int $count;
    protected int $zeroCount;
    protected int $avgResultCount;
    protected ?\DateTimeInterface $lastSearchedAt = null;

    public function getTerm(): string
    {
        return $this->term;
    }

    public function setTerm(string $term): void
    {
        $this->term = $term;
    }

    public function getCount(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->count = $count;
    }

    public function getZeroCount(): int
    {
        return $this->zeroCount;
    }

    public function setZeroCount(int $zeroCount): void
    {
        $this->zeroCount = $zeroCount;
    }

    public function getAvgResultCount(): int
    {
        return $this->avgResultCount;
    }

    public function setAvgResultCount(int $avgResultCount): void
    {
        $this->avgResultCount = $avgResultCount;
    }

    public function getLastSearchedAt(): ?\DateTimeInterface
    {
        return $this->lastSearchedAt;
    }

    public function setLastSearchedAt(?\DateTimeInterface $lastSearchedAt): void
    {
        $this->lastSearchedAt = $lastSearchedAt;
    }
}
```

#### [NEW FILE] `src/Entity/SearchStats/SearchStatsCollection.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                   add(SearchStatsEntity $entity)
 * @method void                   set(string $key, SearchStatsEntity $entity)
 * @method SearchStatsEntity[]    getIterator()
 * @method SearchStatsEntity[]    getElements()
 * @method SearchStatsEntity|null get(string $key)
 * @method SearchStatsEntity|null first()
 * @method SearchStatsEntity|null last()
 */
class SearchStatsCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return SearchStatsEntity::class;
    }
}
```

#### [NEW FILE] `src/Entity/SearchStats/SearchStatsEntityDefinition.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\DateTimeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class SearchStatsEntityDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'tdeh_search_stats';

    public function getEntityName(): string
    {
        return self::ENTITY_NAME;
    }

    public function getEntityClass(): string
    {
        return SearchStatsEntity::class;
    }

    public function getCollectionClass(): string
    {
        return SearchStatsCollection::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(new PrimaryKey(), new Required()),
            (new StringField('term', 'term'))->addFlags(new Required()),
            (new IntField('count', 'count'))->addFlags(new Required()),
            (new IntField('zero_count', 'zeroCount'))->addFlags(new Required()),
            (new IntField('avg_result_count', 'avgResultCount'))->addFlags(new Required()),
            (new DateTimeField('created_at', 'createdAt'))->addFlags(new Required()),
            (new DateTimeField('last_searched_at', 'lastSearchedAt')),
        ]);
    }
}
```

#### [DELETE] `src/Controller/ZeroSearchController.php`

#### [NEW FILE] `src/Controller/SearchStatsController.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class SearchStatsController extends AbstractController
{
    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/search-stats/export',
        name: 'api.action.elasticsearchhackssw6.search-stats.export',
        methods: ['GET']
    )]
    public function exportAction(): Response
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT term, count, zero_count, avg_result_count, created_at, last_searched_at 
             FROM tdeh_search_stats 
             ORDER BY count DESC'
        );

        $csv = "\xEF\xBB\xBF";
        $csv .= '"term","total_searches","zero_result_searches","avg_result_count","created_at","last_searched_at"' . "\n";

        foreach ($rows as $row) {
            $csv .= sprintf(
                '"%s",%d,%d,%d,"%s","%s"' . "\n",
                str_replace('"', '""', $row['term']),
                (int)$row['count'],
                (int)$row['zero_count'],
                (int)$row['avg_result_count'],
                $row['created_at'] ?? '',
                $row['last_searched_at'] ?? ''
            );
        }

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="search-statistics.csv"',
        ]);
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/search-stats/reset',
        name: 'api.action.elasticsearchhackssw6.search-stats.reset',
        methods: ['POST']
    )]
    public function resetAction(): JsonResponse
    {
        $this->connection->executeStatement('TRUNCATE TABLE `tdeh_search_stats`');
        $this->connection->executeStatement('TRUNCATE TABLE `tdeh_search_log`');

        return new JsonResponse(['success' => true]);
    }
}
```

#### [MODIFY] `src/Resources/config/services.xml`
```xml
<?xml version="1.0" ?>
<container xmlns="http://symfony.com/schema/dic/services"
           xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
           xsi:schemaLocation="http://symfony.com/schema/dic/services http://symfony.com/schema/dic/services/services-1.0.xsd">

    <services>
        <!-- Entity Definition -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Entity\SearchStats\SearchStatsEntityDefinition">
            <tag name="shopware.entity.definition"/>
        </service>

        <!-- Synonym Entity Definition -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Entity\Synonym\SynonymEntityDefinition">
            <tag name="shopware.entity.definition"/>
        </service>

        <!-- Core Business Logic Services -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection" key="$connection"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection" key="$connection"/>
        </service>

        <!-- Storefront Search Fail Aggregation Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ProductSearchSubscriber">
            <argument type="service" id="Doctrine\DBAL\Connection" key="$connection"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Dynamic Storefront Search Exclusion Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\SearchCriteriaSubscriber">
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService" key="$systemConfigService"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Elasticsearch Query Boosting Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ElasticsearchSearchSubscriber">
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Elasticsearch Custom Fields Mapping Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ElasticsearchCustomFieldsMappingSubscriber">
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Elasticsearch Product Definition Decorator -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Elasticsearch\ProductElasticsearchDefinitionDecorator"
                 decorates="Shopware\Elasticsearch\Product\ElasticsearchProductDefinition">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Elasticsearch\ProductElasticsearchDefinitionDecorator.inner" key="$decorated"/>
        </service>

        <!-- Elasticsearch Index Config Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ElasticsearchIndexConfigSubscriber">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Debug & Analytics Commands -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_DebugSearch">
            <argument type="service" id="OpenSearch\Client" key="$client"/>
            <argument type="service" id="Shopware\Elasticsearch\Framework\ElasticsearchHelper" key="$esHelper"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ConsolidateSearchLogs">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService" key="$analyticsService"/>
            <tag name="console.command"/>
        </service>

        <!-- Scheduled Tasks -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask\ConsolidateSearchLogsTask">
            <tag name="shopware.scheduled.task"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask\ConsolidateSearchLogsTaskHandler">
            <argument type="service" id="scheduled_task.repository"/>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService" key="$analyticsService"/>
            <tag name="messenger.message_handler"/>
        </service>

        <!-- Command Line Control Suite Services -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ImportSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ListSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_DeleteSynonym">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ClearSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ExportSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ValidateSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
            <tag name="console.command"/>
        </service>

        <!-- Action API Route Controllers -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\StorefrontExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
            <call method="setTwig">
                <argument type="service" id="twig"/>
            </call>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\AdminApiExampleController" public="true">
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\SearchStatsController" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection" key="$connection"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\SynonymController" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection" key="$connection"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

        <!-- Category Search Service -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService">
            <argument type="service" id="sales_channel.category.repository" key="$categoryRepository"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService" key="$systemConfigService"/>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" key="$synonymService"/>
        </service>

        <!-- Category Suggest Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\CategorySuggestSubscriber">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService" key="$categorySearchService"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Category Search Page Loader -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch\CategorySearchPageLoader">
            <argument type="service" id="Shopware\Storefront\Page\GenericPageLoader" key="$genericLoader"/>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\CategorySearchService" key="$categorySearchService"/>
            <argument type="service" id="Symfony\Component\EventDispatcher\EventDispatcherInterface" key="$eventDispatcher"/>
        </service>

        <!-- Category Search Controller -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\CategorySearchController" public="true">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Page\CategorySearch\CategorySearchPageLoader" key="$categorySearchPageLoader"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>
    </services>
</container>
```

#### [MODIFY] `src/TopdataElasticsearchHacksSW6.php`
Ensure uninstall cleans up the new transactional and statistical tables:
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Topdata\TopdataElasticsearchHacksSW6\DependencyInjection\ElasticsearchAnalysisCompilerPass;

class TopdataElasticsearchHacksSW6 extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ElasticsearchAnalysisCompilerPass());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $connection = $this->container->get(Connection::class);
        $tables = [
            'tdeh_synonym', 
            'tdeh_zero_search', 
            'topdata_es_synonym', 
            'topdata_es_zero_search',
            'tdeh_search_log',
            'tdeh_search_stats'
        ];
        foreach ($tables as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }
}
```

---

### Phase 5: Administration UI Updates
We will remove the deprecated `topdata-es-zero-search` administration directory structure and introduce a clean, comprehensive `topdata-es-search-stats` dashboard.

#### [DELETE] `src/Resources/app/administration/src/module/topdata-es-zero-search/`

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-stats/page/search-stats-list/index.ts`
```typescript
import template from './search-stats-list.html.twig';

const { Component, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

Component.register('topdata-es-search-stats-list', {
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
            sortBy: 'count',
            sortDirection: 'DESC',
            limit: 25,
            showResetModal: false,
        };
    },

    computed: {
        repository() {
            return this.repositoryFactory.create('tdeh_search_stats');
        },

        columns() {
            return [{
                property: 'term',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnTerm'),
                allowResize: true,
                primary: true,
            }, {
                property: 'count',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'zeroCount',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnZeroCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'avgResultCount',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnAvgResultCount'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'lastSearchedAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnLastSearchedAt'),
                allowResize: true,
                sortable: true,
            }, {
                property: 'createdAt',
                label: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.columnCreatedAt'),
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

        onDownloadCsv() {
            const httpClient = Shopware.Application.getContainer('init').httpClient;
            httpClient.get('_action/topdata-elasticsearch-hacks-sw6/search-stats/export', {
                responseType: 'blob',
            }).then((response) => {
                const url = window.URL.createObjectURL(response.data);
                const link = document.createElement('a');
                link.setAttribute('href', url);
                link.setAttribute('download', 'search-statistics.csv');
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }).catch(() => {
                this.createNotificationError({
                    message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.exportError'),
                });
            });
        },

        onReset() {
            this.showResetModal = true;
        },

        onConfirmReset() {
            this.showResetModal = false;
            this.isLoading = true;

            const httpClient = Shopware.Application.getContainer('init').httpClient;
            httpClient.post('_action/topdata-elasticsearch-hacks-sw6/search-stats/reset', {})
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetSuccess'),
                    });
                    this.getList();
                })
                .catch(() => {
                    this.createNotificationError({
                        message: this.$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetError'),
                    });
                    this.isLoading = false;
                });
        },

        onCancelReset() {
            this.showResetModal = false;
        },
    },
});
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-stats/page/search-stats-list/search-stats-list.html.twig`
```html
<sw-page class="topdata-es-search-stats-list-page">
    <template #smart-bar-header>
        <h2>{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.title') }}</h2>
    </template>

    <template #smart-bar-actions>
        <sw-button variant="primary" @click="onDownloadCsv">
            {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.buttonDownloadCsv') }}
        </sw-button>
        <sw-button variant="danger" @click="onReset">
            {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.buttonReset') }}
        </sw-button>
    </template>

    <template #content>
        <sw-entity-listing
            v-if="items"
            :dataSource="items"
            :columns="columns"
            :repository="repository"
            identifier="topdata-es-search-stats"
            :show-settings="true"
            :show-selection="false"
            :allow-view="false"
            :allow-edit="false"
            :allow-delete="true"
            :allow-inline-edit="false"
            :full-page="true"
            :sort-by="sortBy"
            :sort-direction="sortDirection"
            :is-loading="isLoading"
            @page-change="onPageChange"
            @column-sort="onSortColumn"
        >
            <template #column-lastSearchedAt="{ item }">
                <sw-time-ago v-if="item.lastSearchedAt" :date="item.lastSearchedAt" />
            </template>

            <template #column-createdAt="{ item }">
                <sw-time-ago v-if="item.createdAt" :date="item.createdAt" />
            </template>
        </sw-entity-listing>

        <sw-modal
            v-if="showResetModal"
            :title="$tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetModalTitle')"
            variant="small"
            @modal-close="onCancelReset"
        >
            <p>{{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetModalBody') }}</p>

            <template #modal-footer>
                <sw-button size="small" @click="onCancelReset">
                    {{ $tc('global.default.cancel') }}
                </sw-button>
                <sw-button variant="danger" size="small" @click="onConfirmReset">
                    {{ $tc('TopdataElasticsearchHacksSW6.topdata-es-search-stats.resetModalConfirm') }}
                </sw-button>
            </template>
        </sw-modal>
    </template>
</sw-page>
```

#### [NEW FILE] `src/Resources/app/administration/src/module/topdata-es-search-stats/index.ts`
```typescript
import './page/search-stats-list';

Shopware.Module.register('topdata-es-search-stats', {
    type: 'plugin',
    name: 'SearchStats',
    title: 'TopdataElasticsearchHacksSW6.topdata-es-search-stats.title',
    description: 'TopdataElasticsearchHacksSW6.topdata-es-search-stats.description',
    color: '#189eff',
    icon: 'default-shopping-search',

    routes: {
        list: {
            component: 'topdata-es-search-stats-list',
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
        id: 'topdata-es-search-stats-list',
        label: 'TopdataElasticsearchHacksSW6.nav.searchStats',
        color: '#189eff',
        path: 'topdata.es.search.stats.list',
        parent: 'topdata-elasticsearch-hacks-sw6',
    }],
});
```

#### [MODIFY] `src/Resources/app/administration/src/main.ts`
```typescript
import './module/topdata-es-search-stats';
import './module/topdata-es-synonym';
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/de-DE.json`
```json
{
    "TopdataElasticsearchHacksSW6": {
        "nav":                    {
            "mainTitle":         "Topdata ES",
            "searchStats":       "Suchstatistiken",
            "synonyms":          "Synonyme"
        },
        "topdata-es-search-stats": {
            "title":                "Suchstatistiken",
            "description":          "Statistiken über Suchanfragen von Kunden",
            "listTitle":            "Suchbegriffe",
            "columnTerm":           "Suchbegriff",
            "columnCount":          "Suchanfragen gesamt",
            "columnZeroCount":      "Null-Ergebnisse gesamt",
            "columnAvgResultCount": "ø Trefferanzahl",
            "columnLastSearchedAt": "Zuletzt gesucht",
            "columnCreatedAt":      "Erstmals gesehen",
            "buttonDownloadCsv":    "CSV herunterladen",
            "buttonReset":          "Zurücksetzen",
            "resetModalTitle":      "Statistiken zurücksetzen",
            "resetModalBody":       "Sind Sie sicher, dass alle Suchstatistiken und unvollständigen Suchprotokolle gelöscht werden sollen? Diese Aktion kann nicht rückgängig gemacht werden.",
            "resetModalConfirm":    "Ja, alle zurücksetzen",
            "resetSuccess":         "Suchstatistiken wurden zurückgesetzt.",
            "resetError":           "Fehler beim Zurücksetzen der Suchstatistiken.",
            "exportError":          "Fehler beim Exportieren der Suchstatistiken."
        },
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
            "scopeBoth":           "Beides (Produkte & Kategorien)",
            "scopeProduct":        "Nur Produkte",
            "scopeCategory":       "Nur Kategorien",
            "placeholderSynonyms": "synonym1, synonym2, synonym3",
            "saveSuccess":         "Synonym erfolgreich gespeichert.",
            "deleteSuccess":       "Synonym erfolgreich gelöscht.",
            "deleteConfirmText":   "Sind Sie sicher, dass das Synonym \"{term}\" gelöscht werden soll?",
            "buttonDownloadCsv":   "CSV herunterladen",
            "exportError":         "Fehler beim Exportieren der Synonyme."
        }
    }
}
```

#### [MODIFY] `src/Resources/app/administration/src/snippet/en-GB.json`
```json
{
    "TopdataElasticsearchHacksSW6": {
        "nav":                    {
            "mainTitle":         "Topdata ES",
            "searchStats":       "Search Statistics",
            "synonyms":          "Synonyms"
        },
        "topdata-es-search-stats": {
            "title":                "Search Statistics",
            "description":          "Detailed customer search statistics",
            "listTitle":            "Search Terms",
            "columnTerm":           "Search Term",
            "columnCount":          "Total Searches",
            "columnZeroCount":      "Zero Result Searches",
            "columnAvgResultCount": "ø Hit Count",
            "columnLastSearchedAt": "Last Searched",
            "columnCreatedAt":      "First Seen",
            "buttonDownloadCsv":    "Download CSV",
            "buttonReset":          "Reset",
            "resetModalTitle":      "Reset Search Statistics",
            "resetModalBody":       "Are you sure you want to delete all search statistics and pending search logs? This action cannot be undone.",
            "resetModalConfirm":    "Yes, reset all",
            "resetSuccess":         "Search statistics have been reset.",
            "resetError":           "Failed to reset search statistics.",
            "exportError":          "Failed to export search statistics."
        },
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
            "scopeBoth":           "Both (Products & Categories)",
            "scopeProduct":        "Products Only",
            "scopeCategory":       "Categories Only",
            "placeholderSynonyms": "synonym1, synonym2, synonym3",
            "saveSuccess":         "Synonym saved successfully.",
            "deleteSuccess":       "Synonym deleted successfully.",
            "deleteConfirmText":   "Are you sure you want to delete the synonym \"{term}\"?",
            "buttonDownloadCsv":   "Download CSV",
            "exportError":         "Failed to export synonyms."
        }
    }
}
```

---

## 5. Outlook / Future Scope: Automated Synonym Generation
*This section outlines future features for reference and does not form part of the current implementation phases.*

The consolidated search statistics provide a structured source of user search data. We can introduce an automated background synonym generation feature to assist merchants.

### Concept Overview
1. **Targeting Failed Queries:** The automation focuses only on searches with high `zero_count` but low or zero `avg_result_count` (the highest-friction terms).
2. **AI Synonym Suggestion:** A CLI command queries a structured LLM API (e.g., GPT-4o-mini). It sends the failed term, its category context (derived from top matches or similar query patterns), and requests true synonyms.
3. **Workflow Integration (Human-in-the-Loop):**
   * Automatically generated synonyms should **not** instantly affect Elasticsearch.
   * To prevent this, `tdeh_synonym` is extended with a `source` column (`manual` / `ai_generated`) and a `status` column (`pending_review` / `approved`).
   * The `ElasticsearchIndexConfigSubscriber` is modified to only query synonyms where `status = 'approved'`.
   * The administration panel displays generated suggestions as drafts. The merchant can review, edit, and click "Approve" with one click, which automatically flags the index for rebuild.

---

## 6. Housekeeping & Validation

### [MODIFY] `README.md`
Update command references, table metrics, and task references to keep user documentation accurate:
```markdown
# ... [MODIFY COMMANDS SECTION] ...

### 1. Consolidate Raw Search Logs
Manually run the background log processing engine to group keystrokes and update analytical statistics:
```bash
php bin/console topdata:es-hacks:consolidate-search-logs --batch-size=100
```

*Note: This process runs automatically every hour via Shopware Scheduled Tasks.*
```

### Verification & Testing Tasks
1. Run database migrations:
   ```bash
   php bin/console database:migrate TopdataElasticsearchHacksSW6 --all
   ```
2. Force-clear the cache:
   ```bash
   php bin/console cache:clear
   ```
3. Open the storefront, enter search terms, and test suggest dropdowns to verify inserts into the `tdeh_search_log` table.
4. Run the consolidation engine via command line:
   ```bash
   php bin/console topdata:es-hacks:consolidate-search-logs
   ```
5. Confirm that raw entries are successfully deleted and that consolidated values are correctly added to `tdeh_search_stats`.
6. Compile the administration files to load the new modules:
   ```bash
   VITE_MODE=production npx ts-node -T build/plugins.vite.ts
   ```

---

## 7. Post-Implementation Report Generation
Upon successful completion, compile the final summary report and save it to:
`_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__advanced_search_logging.md`
```

---

## Phase 8: Report Generation Execution
Once the agent finishes implementing the code, it must write a final summary report to `_ai/backlog/reports/{YYMMDD_HHmm}__IMPLEMENTATION_REPORT__advanced_search_logging.md`.

Here is the exact structure to be written at that point:

```markdown
---
filename: "_ai/backlog/reports/260720_2152__IMPLEMENTATION_REPORT__advanced_search_logging.md"
title: "Report: Advanced Search Logging and Fragment Consolidation"
createdAt: 2026-07-20 21:52
updatedAt: 2026-07-20 21:52
planFile: "_ai/backlog/active/260720_2152__IMPLEMENTATION_PLAN__advanced_search_logging.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
filesCreated: 10
filesModified: 6
filesDeleted: 4
tags: [logging, search, processing, analytics]
documentType: IMPLEMENTATION_REPORT
---

## 1. Summary
The search tracking system has been successfully upgraded from basic zero-result logging to an advanced transactional logging and background consolidation engine. The plugin now records every user input securely with a context session token and uses a background heuristic to filter out typing fragments.

## 2. Files Changed
### Created
- `src/Migration/Migration1752700000CreateSearchLogAndStatsTable.php`
- `src/Service/SearchAnalyticsService.php`
- `src/Command/Command_ConsolidateSearchLogs.php`
- `src/Framework/ScheduledTask/ConsolidateSearchLogsTask.php`
- `src/Framework/ScheduledTask/ConsolidateSearchLogsTaskHandler.php`
- `src/Entity/SearchStats/SearchStatsEntity.php`
- `src/Entity/SearchStats/SearchStatsCollection.php`
- `src/Entity/SearchStats/SearchStatsEntityDefinition.php`
- `src/Controller/SearchStatsController.php`
- `src/Resources/app/administration/src/module/topdata-es-search-stats/...` (List views and templates)

### Modified
- `src/Subscriber/ProductSearchSubscriber.php`
- `src/Resources/config/services.xml`
- `src/TopdataElasticsearchHacksSW6.php`
- `src/Resources/app/administration/src/main.ts`
- `src/Resources/app/administration/src/snippet/de-DE.json`
- `src/Resources/app/administration/src/snippet/en-GB.json`
- `README.md`

### Deleted
- `src/Entity/ZeroSearch/ZeroSearchCollection.php`
- `src/Entity/ZeroSearch/ZeroSearchEntity.php`
- `src/Entity/ZeroSearch/ZeroSearchEntityDefinition.php`
- `src/Controller/ZeroSearchController.php`
- `src/Resources/app/administration/src/module/topdata-es-zero-search/`

## 3. Key Changes
- **Typo & Substring Heuristic:** Leverages Levenshtein distance and prefix checks to identify corrections during live search streams.
- **Session Identification:** Tracks sequences across requests using Shopware's native `$context->getToken()`.
- **Admin Dashboard Enhancement:** Replaces old failed-search logs with advanced query metrics (Total Counts, Zero Counts, Average Hit Counts).

## 4. Technical Decisions
- **Two-Table Separation:** Offloading consolidation to an asynchronous scheduled task keeps hot storefront requests fast and reliable.
- **Wiping Log Garbage:** Deletes processed raw logs to prevent database storage issues.
```
