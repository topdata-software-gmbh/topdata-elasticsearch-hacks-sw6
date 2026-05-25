---
filename: "_ai/backlog/active/260525_1730__IMPLEMENTATION_PLAN__synonym_management_suite.md"
title: "Custom Elasticsearch Synonym Management & Zero-Result Analytics Suite"
createdAt: 2026-05-25 17:30
updatedAt: 2026-05-25 17:30
status: draft
priority: medium
tags: [elasticsearch, synonyms, analytics, command-line, api, shopware-6.7]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Custom Elasticsearch Synonym Management & Zero-Result Analytics Suite


## 1. Problem Statement
In standard Shopware 6 installations, administrators lack deep visibility into search execution performance. There are no built-in diagnostics to track failed search queries, nor is there a dedicated administrative interface to audit, modify, validate, or purge synonym mappings. This "blind spot" prevents merchant teams from performing proactive keyword optimization. Adding these tools to the console helps teams maintain their search catalog directly without requiring premium third-party analytics add-ons.

## 2. Executive Summary
This updated implementation plan expands the `TopdataElasticsearchHacksSW6` plugin into a full command-line control suite for search optimization. 

The suite is structured into two main logical parts:
1. **Search Failure Tracker**: Logs occurrences of failed terms via lightweight event subscribers and formats them for external consumption or AI prompts [1].
2. **Synonym Manager**: Interacts with the `topdata_es_synonym` database table [1] to manage explicit mapping rules (`term => synonym1, synonym2`).

Six modular CLI commands are added to allow seamless synonym editing, auditing, and maintenance:
- `Command_ExportZeroResults`: Extracts terms with zero search hits [1].
- `Command_ImportSynonyms`: Bulk-imports synonyms from formatted text files with dry-run support [1].
- `Command_ListSynonyms`: Interactive pagination and text filtering of loaded rules.
- `Command_DeleteSynonym`: Targeted removal of unique rules.
- `Command_ClearSynonyms`: Automated bulk purge of the database table.
- `Command_ExportSynonyms`: Compiles active database mappings into a flat-file backup.
- `Command_ValidateSynonyms`: Tests local text files for syntax errors prior to import.

---

## 3. Project Environment Details

| Requirement / Parameter | Value |
| --- | --- |
| Shopware Platform Compatibility | 6.7.* |
| PHP Target Version | >= 8.2 |
| Database Engine | MySQL >= 8.0 / MariaDB >= 10.11 |
| Output Encoding | UTF-8 |
| Coding Standards | PSR-12, SOLID Principles |

---

## 4. Implementation Phases

```
┌─────────────────────────────────────────────────────────┐
│ Phase 1: Database Migration & Search Aggregator         │
│ - Deploy topdata_es_zero_search & topdata_es_synonym    │
│ - Hook into ProductSearchResultEvent to record misses   │
└────────────────────────────┬────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────┐
│ Phase 2: Core Services Architecture                     │
│ - SearchExportFormatter (formats JSON, CSV, Prompt)     │
│ - ZeroSearchService (manages zero-result extractions)   │
│ - SynonymService (handles DB CRUD, file validations)    │
└────────────────────────────┬────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────┐
│ Phase 3: CLI Commands Integration (Slim Wrappers)       │
│ - Implement ExportZeroResults & ImportSynonyms          │
│ - Implement ListSynonyms, DeleteSynonym, ClearSynonyms  │
│ - Implement ExportSynonyms & ValidateSynonyms           │
└────────────────────────────┬────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────┐
│ Phase 4: Controller & Service Registrations             │
│ - Register all services inside config/services.xml      │
│ - Bind REST controller endpoints for integration        │
└────────────────────────────┬────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────┐
│ Phase 5: Testing, Validation & Manuals                  │
│ - Verify validation algorithms against invalid structures│
│ - Supplement README.md with practical CLI scripts        │
└─────────────────────────────────────────────────────────┘
```

---

### Phase 1: Database Schema & Subscriber Logging

We introduce database tables and the storefront event listener to log and count failed customer inquiries.

#### [NEW FILE] `src/Migration/Migration1716652800CreateZeroSearchTable.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1716652800CreateZeroSearchTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1716652800;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_es_zero_search` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `count` INT NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `last_searched_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.topdata_es_zero_search.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `topdata_es_synonym` (
                `id` BINARY(16) NOT NULL,
                `term` VARCHAR(255) NOT NULL,
                `synonyms` TEXT NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.topdata_es_synonym.term` (`term`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // Destructive updates omitted to prevent inadvertent data loss
    }
}
```

#### [NEW FILE] `src/Subscriber/ProductSearchSubscriber.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Product\Events\ProductSearchResultEvent;
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
            ProductEvents::PRODUCT_SEARCH_RESULT => 'onSearchResult'
        ];
    }

    public function onSearchResult(ProductSearchResultEvent $event): void
    {
        $result = $event->getResult();
        
        if ($result->getTotal() !== 0) {
            return;
        }

        $term = $result->getCriteria()->getTerm();
        if ($term === null || trim($term) === '') {
            return;
        }

        $term = mb_strtolower(trim($term));
        if (mb_strlen($term) > 255) {
            $term = mb_substr($term, 0, 255);
        }

        try {
            $this->connection->executeStatement(
                'INSERT INTO `topdata_es_zero_search` (`id`, `term`, `count`, `created_at`, `last_searched_at`)
                 VALUES (:id, :term, 1, :now, :now)
                 ON DUPLICATE KEY UPDATE `count` = `count` + 1, `last_searched_at` = :now',
                [
                    'id' => Uuid::randomBytes(),
                    'term' => $term,
                    'now' => (new \DateTime())->format('Y-m-d H:i:s.v')
                ]
            );
        } catch (\Throwable $e) {
            // Prevent failure logging from degrading active storefront performance
        }
    }
}
```

---

### Phase 2: Core Business Logic Services

To enforce the **Single Responsibility Principle (SRP)**, we isolate query builders, parsing rules, syntax evaluations, and file formatting from commands and controllers.

#### [NEW FILE] `src/Service/SearchExportFormatter.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

class SearchExportFormatter
{
    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatCsv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ['Term', 'Search Count', 'Last Searched At']);

        foreach ($data as $row) {
            fputcsv($handle, [$row['term'], $row['count'], $row['last_searched_at'] ?? '']);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv ?: '';
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatMarkdown(array $data): string
    {
        $output = "| Term | Search Count | Last Searched At |\n";
        $output .= "| --- | --- | --- |\n";

        foreach ($data as $row) {
            $output .= sprintf(
                "| %s | %d | %s |\n",
                $row['term'],
                $row['count'],
                $row['last_searched_at'] ?? '-'
            );
        }

        return $output;
    }

    /**
     * @param array<array{term: string, count: int, last_searched_at: ?string}> $data
     */
    public function formatLlmPrompt(array $data): string
    {
        $markdownTable = $this->formatMarkdown($data);

        return <<<PROMPT
# Instructions for LLM Optimization:
You are an e-commerce search and SEO specialist. Below is a structured markdown list containing search terms entered by customers that returned ZERO results (no matches) on our Shopware 6 online storefront.

Please analyze these search terms and suggest highly accurate synonyms to improve search results.
Focus on:
1. Translating customer colloquial terms to technical/brand names (e.g., "WC-Papier" -> "Toilettenpapier").
2. Identifying common typos/alternative spellings (e.g., "Akkuborher" -> "Akkubohrer").
3. Suggesting equivalent search words or broader parent terms where suitable.

## Expected Output Format:
Provide synonym mappings in Elasticsearch explicit mapping format. One mapping per line, where the search term points to its target synonyms, separated by `=>`. Use a single markdown code block:
```text
term1 => synonym1, synonym2
term2 => synonym1
```
Do not include any preambles, introductory text, or explanations. Only provide the requested Elasticsearch explicit mapping code block.

## Zero-Result Search Terms Dataset:
{$markdownTable}
PROMPT;
    }
}
```

#### [NEW FILE] `src/Service/ZeroSearchService.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;

class ZeroSearchService
{
    private Connection $connection;
    private SearchExportFormatter $formatter;

    public function __construct(Connection $connection, SearchExportFormatter $formatter)
    {
        $this->connection = $connection;
        $this->formatter = $formatter;
    }

    /**
     * @return array<array{term: string, count: int, last_searched_at: ?string}>
     */
    public function fetchZeroResults(int $limit, int $minCount): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('LOWER(term) as term', 'count', 'last_searched_at')
            ->from('topdata_es_zero_search')
            ->where('count >= :minCount')
            ->setParameter('minCount', $minCount)
            ->orderBy('count', 'DESC')
            ->addOrderBy('last_searched_at', 'DESC')
            ->setMaxResults($limit);

        return $qb->executeQuery()->fetchAllAssociative();
    }

    public function export(string $format, int $limit, int $minCount, ?string $outputPath = null): string
    {
        $data = $this->fetchZeroResults($limit, $minCount);

        if (empty($data)) {
            return '';
        }

        $formattedContent = match ($format) {
            'json' => $this->formatter->formatJson($data),
            'csv' => $this->formatter->formatCsv($data),
            'markdown' => $this->formatter->formatMarkdown($data),
            'llm-prompt' => $this->formatter->formatLlmPrompt($data),
            default => throw new \InvalidArgumentException(sprintf('Unsupported format "%s"', $format))
        };

        if ($outputPath !== null) {
            if (\file_put_contents($outputPath, $formattedContent) === false) {
                throw new \RuntimeException(sprintf('Could not write to file path "%s"', $outputPath));
            }
        }

        return $formattedContent;
    }
}
```

#### [NEW FILE] `src/Service/SynonymService.php`
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
     * @return array<array{term: string, synonyms: string, created_at: string}>
     */
    public function listSynonyms(?string $filter = null, int $limit = 50, int $offset = 0): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms', 'created_at')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($filter !== null && $filter !== '') {
            $qb->where('term LIKE :filter OR synonyms LIKE :filter')
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

            $term = trim($parts[0]);
            $synonyms = trim($parts[1]);

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
                $term = mb_strtolower(trim($parts[0]));
                $synonyms = mb_strtolower(trim($parts[1]));

                $this->connection->executeStatement(
                    'INSERT INTO `topdata_es_synonym` (`id`, `term`, `synonyms`, `created_at`)
                     VALUES (:id, :term, :synonyms, :now)
                     ON DUPLICATE KEY UPDATE `synonyms` = :synonyms, `created_at` = :now',
                    [
                        'id' => Uuid::randomBytes(),
                        'term' => $term,
                        'synonyms' => $synonyms,
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

    public function exportToString(): string
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('term', 'synonyms')
            ->from('topdata_es_synonym')
            ->orderBy('term', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $lines = ["# Elasticsearch Synonyms Mapping File", "# Generated: " . (new \DateTime())->format('Y-m-d H:i:s')];

        foreach ($rows as $row) {
            $lines[] = sprintf('%s => %s', $row['term'], $row['synonyms']);
        }

        return implode("\n", $lines);
    }
}
```

---

### Phase 3: Console Interface (Command Classes)

CLI operations are represented by single action classes, isolating inputs and delegating output formatting to the output writer.

#### [NEW FILE] `src/Command/Command_ExportZeroResults.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService;

#[AsCommand(
    name: 'elasticsearchhackssw6:export-zero-results',
    description: 'Export search queries that returned zero results for analysis or LLM synonym generation'
)]
class Command_ExportZeroResults extends Command
{
    private ZeroSearchService $zeroSearchService;

    public function __construct(ZeroSearchService $zeroSearchService)
    {
        parent::__construct();
        $this->zeroSearchService = $zeroSearchService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table, json, csv, markdown, llm-prompt)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit the number of export records', '100')
            ->addOption('min-count', 'm', InputOption::VALUE_REQUIRED, 'Filter out searches with occurrences less than this value', '1')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Target file path to save the output instead of printing to CLI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');
        $minCount = (int) $input->getOption('min-count');
        $outputPath = $input->getOption('output');

        if ($format === 'table') {
            if ($outputPath) {
                $output->writeln('<error>File output path is not compatible with console "table" format. Choose json, csv, markdown, or llm-prompt.</error>');
                return Command::INVALID;
            }

            try {
                $data = $this->zeroSearchService->fetchZeroResults($limit, $minCount);
            } catch (\Throwable $e) {
                $output->writeln('<error>Failed to fetch data: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            if (empty($data)) {
                $output->writeln('<comment>No zero-result searches found matching the criteria.</comment>');
                return Command::SUCCESS;
            }

            $table = new Table($output);
            $table->setHeaders(['Term', 'Search Count', 'Last Searched At']);
            foreach ($data as $row) {
                $table->addRow([$row['term'], $row['count'], $row['last_searched_at'] ?? '-']);
            }
            $table->render();
            return Command::SUCCESS;
        }

        try {
            $formattedContent = $this->zeroSearchService->export($format, $limit, $minCount, $outputPath);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $e) {
            $output->writeln('<error>Export execution failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($formattedContent === '') {
            $output->writeln('<comment>No data exported (dataset was empty).</comment>');
            return Command::SUCCESS;
        }

        if ($outputPath) {
            $output->writeln(sprintf('<info>Successfully wrote export payload to "%s"</info>', $outputPath));
        } else {
            $output->write($formattedContent . "\n");
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_ImportSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:import-synonyms',
    description: 'Import synonym mapping rules from a generated text file back into the store database'
)]
class Command_ImportSynonyms extends Command
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
                $output->writeln(sprintf('<info>[Dry-Run] Mappings checked. %d synonym rule(s) are valid and ready to import.</info>', $count));
            } else {
                $output->writeln(sprintf('<info>Successfully imported %d synonym rule(s).</info>', $count));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_ListSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:list-synonyms',
    description: 'View and filter synonym records currently active in the database store'
)]
class Command_ListSynonyms extends Command
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
            $output->writeln('<error>Failed to fetch synonyms: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($list)) {
            $output->writeln('<comment>No active synonym definitions found in database.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Target Search Term', 'Mapped Synonym Group', 'Created/Modified At']);
        foreach ($list as $row) {
            $table->addRow([$row['term'], $row['synonyms'], $row['created_at']]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_DeleteSynonym.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:delete-synonym',
    description: 'Deletes a specific synonym configuration rule by key term'
)]
class Command_DeleteSynonym extends Command
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The key search term mapping to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');

        try {
            $success = $this->synonymService->deleteSynonym($term);
            if ($success) {
                $output->writeln(sprintf('<info>Successfully deleted synonym configuration for term "%s".</info>', $term));
            } else {
                $output->writeln(sprintf('<comment>No active synonym record matches the term "%s".</comment>', $term));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Operation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_ClearSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:clear-synonyms',
    description: 'Bulk purges all active synonym mappings from the database'
)]
class Command_ClearSynonyms extends Command
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation safety check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'This will delete ALL database-stored synonyms. Are you sure you want to proceed? [y/N]: ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Operation aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        try {
            $this->synonymService->clearAllSynonyms();
            $output->writeln('<info>Successfully cleared all synonym mapping definitions from the database.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>Truncate process failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_ExportSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:export-synonyms',
    description: 'Exports current synonym records from the database into a backup text file'
)]
class Command_ExportSynonyms extends Command
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (prints to screen if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = $input->getOption('output');

        try {
            $content = $this->synonymService->exportToString();

            if ($outputPath !== null) {
                if (\file_put_contents($outputPath, $content) === false) {
                    throw new \RuntimeException(sprintf('Could not write output to path "%s".', $outputPath));
                }
                $output->writeln(sprintf('<info>Export complete. Mappings written to "%s".</info>', $outputPath));
            } else {
                $output->write($content . "\n");
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Export operation aborted: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
```

#### [NEW FILE] `src/Command/Command_ValidateSynonyms.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'elasticsearchhackssw6:validate-synonyms',
    description: 'Validates formatting syntax of a local synonym text file without importing'
)]
class Command_ValidateSynonyms extends Command
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path of the local synonyms txt file to validate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');

        try {
            $errors = $this->synonymService->validateFile($filePath);
        } catch (\Throwable $e) {
            $output->writeln('<error>Validation check failed to complete: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($errors)) {
            $output->writeln('<info>Format verification successful. File contains valid explicit synonyms syntax.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>Syntax issues found! Found %d parsing error(s):</error>', count($errors)));
        foreach ($errors as $error) {
            $output->writeln(sprintf(
                ' - <comment>Line %d</comment>: "%s" -> <error>%s</error>',
                $error['line'],
                trim($error['content']),
                $error['error']
            ));
        }

        return Command::FAILURE;
    }
}
```

---

### Phase 4: Admin API Endpoint Integration

We integrate service routes and wire console commands in the DIC container.

#### [NEW FILE] `src/Controller/ZeroResultsExportController.php`
```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService;

#[Route(defaults: ['_routeScope' => ['api']])]
class ZeroResultsExportController extends AbstractController
{
    private ZeroSearchService $zeroSearchService;

    public function __construct(ZeroSearchService $zeroSearchService)
    {
        $this->zeroSearchService = $zeroSearchService;
    }

    #[Route(
        path: '/api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export',
        name: 'api.action.elasticsearchhackssw6.zero_results.export',
        methods: ['GET']
    )]
    public function export(Request $request): Response
    {
        $limit = $request->query->getInt('limit', 100);
        $minCount = $request->query->getInt('minCount', 1);
        $format = $request->query->get('format', 'json');

        try {
            if ($format === 'json') {
                $data = $this->zeroSearchService->fetchZeroResults($limit, $minCount);
                return new JsonResponse($data);
            }

            $content = $this->zeroSearchService->export($format, $limit, $minCount);
            $response = new Response($content);

            if ($format === 'csv') {
                $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
                $response->headers->set('Content-Disposition', 'attachment; filename="zero_results_export.csv"');
            } elseif ($format === 'markdown') {
                $response->headers->set('Content-Type', 'text/markdown; charset=UTF-8');
            }

            return $response;
        } catch (\Throwable $e) {
            return new JsonResponse([
                'success' => false,
                'error' => 'An error occurred during export: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
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
        <!-- Formatting Utility Service -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\SearchExportFormatter" public="false"/>

        <!-- Core Business Logic Services -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SearchExportFormatter"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService" public="true">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
        </service>

        <!-- Storefront Search Fail Aggregation Subscriber -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Subscriber\ProductSearchSubscriber">
            <argument type="service" id="Doctrine\DBAL\Connection"/>
            <tag name="kernel.event_subscriber"/>
        </service>

        <!-- Command Line Control Suite Services -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ExportZeroResults">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ImportSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ListSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_DeleteSynonym">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ClearSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ExportSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <service id="Topdata\TopdataElasticsearchHacksSW6\Command\Command_ValidateSynonyms">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService"/>
            <tag name="console.command"/>
        </service>

        <!-- Action API Route Controllers -->
        <service id="Topdata\TopdataElasticsearchHacksSW6\Controller\ZeroResultsExportController" public="true">
            <argument type="service" id="Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService"/>
            <call method="setContainer">
                <argument type="service" id="service_container"/>
            </call>
        </service>

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
    </services>
</container>
```

---

### Phase 5: Documentation Update

Update instructions inside the `README.md` file to detail the newly introduced administrative synonym CLI capabilities.

#### [MODIFY] `README.md`
```markdown
# Topdata Elasticsearch Hacks SW6

![Plugin Icon](src/Resources/config/plugin.png)

## Overview
This plugin optimizes Elasticsearch tokenization on Shopware 6.7 to allow better matching on hyphenated or concatenated terms (such as `WC-Papier` matching `WC Papier`).

## Features
* Globally registers a `word_delimiter_graph` token filter in Elasticsearch settings.
* Overrides default language analyzers (`sw_german_analyzer`, `sw_english_analyzer`, `sw_default_analyzer`) to split terms dynamically without breaking default stemmers.
* **Synonym Suite**: Dynamically tracks failed storefront searches and offers a full suite of administrative CLI utilities to manage search synonym mappings.

## Installation
1. Install and activate the plugin.
2. Run database migrations to construct tables:
   ```bash
   php bin/console database:migrate TopdataElasticsearchHacksSW6 --all
   ```
3. Clear the Symfony cache:
   ```bash
   php bin/console cache:clear
   ```
4. Reset and rebuild the Elasticsearch search indices to apply the updated mappings:
   ```bash
   php bin/console es:reset
   php bin/console es:index --no-queue
   php bin/console es:create:alias
   ```

---

## Command Reference Guide: Synonym & Zero-Result Analytics

This plugin contains a comprehensive suite of console commands to help merchants audit, optimize, and organize search synonyms.

### 1. Identify Failed Searches (Zero-Result Terms)
Extract terms entered by customers that returned no matches, formatted directly for an LLM prompt:
```bash
# Print standard console table view of failures
php bin/console elasticsearchhackssw6:export-zero-results --limit=50 --min-count=2

# Export directly into a pre-formatted LLM copy-paste prompt file
php bin/console elasticsearchhackssw6:export-zero-results --format=llm-prompt --output=var/log/prompt.txt
```

### 2. Validate Synonym Mapping Files
Test a local synonyms text file for syntax, missing elements, or structural errors before committing changes to the database:
```bash
php bin/console elasticsearchhackssw6:validate-synonyms var/log/synonyms.txt
```

### 3. Dry-Run and Import Mappings
Import generated synonyms text files using the explicit mapping format (`term => synonym1, synonym2`):
```bash
# Perform validation checks without writing to the database
php bin/console elasticsearchhackssw6:import-synonyms var/log/synonyms.txt --dry-run

# Execute database import
php bin/console elasticsearchhackssw6:import-synonyms var/log/synonyms.txt
```

### 4. Search and List Registered Mappings
Inspect synonym entries currently configured in the database using filters and pagination:
```bash
# List all active mappings in a structured table
php bin/console elasticsearchhackssw6:list-synonyms --limit=50

# Filter active mappings by search criteria
php bin/console elasticsearchhackssw6:list-synonyms --filter="papier"
```

### 5. Export Mappings (Backups/Manual Audits)
Dump currently stored synonym mappings to a file for backup or local editing:
```bash
php bin/console elasticsearchhackssw6:export-synonyms --output=var/log/synonym_backup.txt
```

### 6. Delete a Specific Mapping
Remove a unique synonym configuration using its left-hand key search term:
```bash
php bin/console elasticsearchhackssw6:delete-synonym "wc-papier"
```

### 7. Clear All Synonym Definitions
Completely wipe all stored synonym records. Requires interactive confirmation unless forced:
```bash
php bin/console elasticsearchhackssw6:clear-synonyms
# Or bypass the confirmation prompt:
php bin/console elasticsearchhackssw6:clear-synonyms --force
```

---

## API Integration

Automated management scripts can retrieve zero-result queries via Shopware Admin API authentication:
* **Route:** `GET /api/_action/topdata-elasticsearch-hacks-sw6/zero-results/export`
* **Query Parameters:**
  * `limit` (default: 100)
  * `minCount` (default: 1)
  * `format` (`json`, `csv`, `markdown`)

## Requirements

- Shopware 6.7.*

## License

MIT

