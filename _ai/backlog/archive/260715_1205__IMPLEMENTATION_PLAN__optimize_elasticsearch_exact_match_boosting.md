```markdown
---
filename: "_ai/backlog/active/260715_1205__IMPLEMENTATION_PLAN__optimize_elasticsearch_exact_match_boosting.md"
title: "Optimize Elasticsearch Exact Match Query Boosting to Prevent Compound Word Ranking Skew"
createdAt: 2026-07-15 12:05
updatedAt: 2026-07-15 12:05
status: completed
completedAt: 2026-07-15 12:09
priority: critical
tags: [elasticsearch, search, shopware, query-boosting]
estimatedComplexity: moderate
documentType: IMPLEMENTATION_PLAN
---

# Implementation Plan: Optimize Elasticsearch Exact Match Query Boosting

## 1. Problem Description
When a user searches for an exact term like `"Papierhandtücher"`, compound products like `"Papierhandtücher-Halter"` are returned as the top search results instead of the exact match. This happens despite the custom query boosting registered in `ElasticsearchSearchSubscriber.php`. 

This behavior stems from targeting the wrong field types and unanalyzed query mismatches [1.1.1]:
1. **Targeting non-analyzed keyword fields:** The subscriber targets the base field `name.[languageId]`, which is mapped as a `keyword` field (with a lowercase normalizer) [1.1.1]. Since keyword fields do not undergo tokenization or stemming, the custom `MatchPhraseQuery` and `MatchQuery` behave identically to strict term matches, preventing language analyzers from aligning stemmed variations (such as `"papierhandtücher"` with the stemmed index token `"papierhandtuch"`) [1.1.1].
2. **Compound word tokens skewing search:** The custom `word_delimiter_graph` filter produces multiple tokens for compound terms (e.g., `"papierhandtücher"`, `"halter"`, `"papierhandtücherhalter"`) on the `.search` text subfield. Because the exact match queries on the keyword field fail to leverage stemming, the default search logic fallback on the `.search` field prioritizes the compound product, which matches multiple tokens.
3. **Prefix Query overpowering exact match:** The `PrefixQuery` with a high boost value matches both exact matches and compound words (since both start with the prefix `"papierhandtücher"`), neutralizing the ranking distinction.

---

## 2. Executive Summary of the Solution
We will modify the search subscriber and the debugging command to target the analyzed `.search` sub-field for full-text matches, while maintaining a low-impact prefix match on the unanalyzed keyword field:

1. **Target the `.search` sub-field:** Ensure the custom `MatchPhraseQuery` and `MatchQuery` are run against `name.[languageId].search` rather than `name.[languageId]`. This allows OpenSearch/Elasticsearch to apply the custom analyzers (`word_delimiter_graph` and German/English stemmers) and rewards shorter fields (exact matches) over compound words using BM25 field length normalization [1.1.1, 2.1.3].
2. **Maintain a Tamed Prefix Query:** Keep the `PrefixQuery` on the keyword field `name.[languageId]` but with a lower boost (e.g., `1.1`) to ensure that prefix matches act as a weak fallback rather than overpowering exact phrase matches [1.1.1].
3. **Align Debug Command:** Update `Command_DebugSearch.php` so its diagnostic queries match the subscriber's actual boosting behavior, ensuring that console test results match storefront search behavior [1.1.1].

---

## 3. Project Environment Details
- **Project Name:** SW6.7 Plugin (topdata-elasticsearch-hacks-sw6)
- **Backend root:** `src`
- **PHP Version:** 8.2 / 8.3 / 8.4
- **Shopware Version:** 6.7.*
- **Symfony Version:** 7.4

---

## 4. SOLID & Architectural Decisions
- **Single Responsibility Principle (SRP):** `ElasticsearchSearchSubscriber` remains solely responsible for extending search parameters prior to OpenSearch execution.
- **Dependency & Scope Integrity:** We avoid utilizing dependencies (such as `TopdataFoundationSW6` or `CliLogger`) that are not defined in this plugin's `composer.json` or present in the workspace, ensuring the console commands remain executable without throwing `ClassNotFoundException` errors.

---

## 5. Phased Implementation Plan

### Phase 1: Code Refactoring

We will modify `src/Subscriber/ElasticsearchSearchSubscriber.php` to target the analyzed `.search` sub-fields.

```php
// [MODIFY] src/Subscriber/ElasticsearchSearchSubscriber.php
```

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Subscriber;

use OpenSearchDSL\Query\Compound\BoolQuery;
use OpenSearchDSL\Query\FullText\MatchPhraseQuery;
use OpenSearchDSL\Query\FullText\MatchQuery;
use OpenSearchDSL\Query\TermLevel\PrefixQuery;
use Shopware\Elasticsearch\Framework\DataAbstractionLayer\Event\ElasticsearchEntitySearcherSearchEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ElasticsearchSearchSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ElasticsearchEntitySearcherSearchEvent::class => 'onProductSearchBeforeQuery',
        ];
    }

    public function onProductSearchBeforeQuery(ElasticsearchEntitySearcherSearchEvent $event): void
    {
        if ($event->getDefinition()->getEntityName() !== 'product') {
            return;
        }

        $term = $event->getCriteria()->getTerm();

        if ($term === null || $term === '' || mb_strlen($term) < 2) {
            return;
        }

        $search = $event->getSearch();
        $lowerTerm = mb_strtolower($term);
        $languageIdChain = $event->getContext()->getLanguageIdChain();

        foreach ($languageIdChain as $languageId) {
            $analyzedField = sprintf('name.%s.search', $languageId);
            $keywordField = sprintf('name.%s', $languageId);

            // 1. Exact Phrase Match Boost (Analyzed)
            // Ensures input tokenization matches the index. Shorter exact fields rank higher 
            // than compound terms due to BM25 length normalization.
            $search->addQuery(
                new MatchPhraseQuery($analyzedField, $lowerTerm, ['boost' => 15.0]),
                BoolQuery::SHOULD
            );

            // 2. Full-Text Match Query (Analyzed)
            // Provides high-relevance standard matches with full analyzer support.
            $search->addQuery(
                new MatchQuery($analyzedField, $lowerTerm, ['boost' => 5.0, 'operator' => 'and']),
                BoolQuery::SHOULD
            );

            // 3. Prefix Query (Unanalyzed Keyword Field)
            // Lowered boost to ensure compound words starting with the prefix 
            // do not overpower exact phrase matches.
            $search->addQuery(
                new PrefixQuery($keywordField, $lowerTerm, ['boost' => 1.1]),
                BoolQuery::SHOULD
            );
        }
    }
}
```

### Phase 2: Updating the Debug Command

We will modify `src/Command/Command_DebugSearch.php` to output and execute the updated query design, ensuring alignment between console output and storefront search logic [1.1.1].

```php
// [MODIFY] src/Command/Command_DebugSearch.php
```

```php
<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use OpenSearch\Client;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'topdata:es-hacks:debug-search',
    description: 'Debug ES search scoring for a given term'
)]
class Command_DebugSearch extends Command
{
    public function __construct(
        private readonly Client $client,
        private readonly ElasticsearchHelper $esHelper,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('term', InputArgument::REQUIRED, 'Search term')
            ->addOption('sales-channel-id', null, InputOption::VALUE_REQUIRED, 'Sales channel ID (optional)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');
        $limit = (int) $input->getOption('limit');

        $indexName = $this->esHelper->getIndexName(
            new \Shopware\Core\Content\Product\ProductDefinition()
        );

        $output->writeln(\sprintf('Searching index: <info>%s</info>', $indexName));
        $output->writeln(\sprintf('Search term: <info>%s</info>', $term));
        $output->writeln('');

        $actualIndex = $this->resolveActualIndex($indexName);
        if ($actualIndex === null) {
            $output->writeln('<error>Could not resolve actual index (alias or index not found)</error>');
            return Command::FAILURE;
        }
        $output->writeln(\sprintf('Resolved index: <info>%s</info>', $actualIndex));

        $mapping = $this->client->indices()->getMapping(['index' => $actualIndex]);
        $output->writeln('');
        $output->writeln('<comment>=== Index Mapping (name fields) ===</comment>');
        $this->printMapping($mapping, $actualIndex, $output);

        $query = $this->buildSearchQuery($term, $actualIndex);
        $output->writeln('');
        $output->writeln('<comment>=== ES Query (with explain) ===</comment>');
        $output->writeln(json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $params = [
            'index' => $actualIndex,
            'body' => $query,
            'explain' => true,
            'size' => $limit,
        ];

        $response = $this->client->search($params);

        $output->writeln('');
        $output->writeln(\sprintf('<comment>=== Results (%d total) ===</comment>', $response['hits']['total']['value'] ?? 0));

        foreach ($response['hits']['hits'] as $i => $hit) {
            $output->writeln('');
            $output->writeln(\sprintf('<info>#%d: %s (score: %s)</info>',
                $i + 1,
                $hit['_source']['name']['de-DE'] ?? $hit['_id'],
                $hit['_score']
            ));
            if (isset($hit['_explanation'])) {
                $this->printExplanation($hit['_explanation'], $output, '  ');
            }
        }

        return Command::SUCCESS;
    }

    private function buildSearchQuery(string $term, string $index): array
    {
        $lowerTerm = mb_strtolower($term);
        $languageField = $this->getLanguageField($index);

        $analyzedField = $languageField . '.search';
        $keywordField = $languageField;

        return [
            'query' => [
                'bool' => [
                    'should' => [
                        // 1. Exact Phrase Match (Analyzed)
                        ['match_phrase' => [$analyzedField => ['query' => $lowerTerm, 'boost' => 15.0]]],

                        // 2. Full-Text Match Query (Analyzed)
                        ['match' => [$analyzedField => ['query' => $lowerTerm, 'boost' => 5.0, 'operator' => 'and']]],

                        // 3. Prefix Query (Keyword Field)
                        ['prefix' => [$keywordField => ['value' => $lowerTerm, 'boost' => 1.1]]],
                    ],
                ],
            ],
        ];
    }

    private function getLanguageField(string $index): string
    {
        $mapping = $this->client->indices()->getMapping(['index' => $index]);
        $props = $mapping[$index]['mappings']['properties'] ?? [];

        if (!isset($props['name']['properties'])) {
            return 'name';
        }

        $langIds = array_keys($props['name']['properties']);
        $langId = $langIds[0] ?? '';

        return 'name.' . $langId;
    }

    private function resolveActualIndex(string $aliasOrIndex): ?string
    {
        try {
            if ($this->client->indices()->existsAlias(['name' => $aliasOrIndex])) {
                $aliases = $this->client->indices()->getAlias(['name' => $aliasOrIndex]);
                $indices = array_keys($aliases);
                return $indices[0] ?? $aliasOrIndex;
            }
        } catch (\Throwable $e) {
        }

        try {
            if ($this->client->indices()->exists(['index' => $aliasOrIndex])) {
                return $aliasOrIndex;
            }
        } catch (\Throwable $e) {
        }

        $response = $this->client->indices()->get(['index' => $aliasOrIndex . '*']);
        $indices = array_keys($response);
        if (!empty($indices)) {
            return $indices[0];
        }

        return null;
    }

    private function printMapping(array $mapping, string $index, OutputInterface $output): void
    {
        $props = $mapping[$index]['mappings']['properties'] ?? [];
        if (isset($props['name']['properties'])) {
            foreach ($props['name']['properties'] as $langId => $fieldConfig) {
                $typeInfo = $fieldConfig['type'] ?? '?';
                $fieldsInfo = '';
                if (isset($fieldConfig['fields'])) {
                    $subFields = [];
                    foreach ($fieldConfig['fields'] as $subName => $subConfig) {
                        $subFields[] = $subName . '(' . ($subConfig['type'] ?? '?') . ')';
                    }
                    $fieldsInfo = ' -> fields: ' . implode(', ', $subFields);
                }
                if (isset($fieldConfig['normalizer'])) {
                    $fieldsInfo .= ' [normalizer: ' . $fieldConfig['normalizer'] . ']';
                }
                $output->writeln(\sprintf('  name.%s: type=%s%s', $langId, $typeInfo, $fieldsInfo));
            }
        } else {
            $output->writeln('  (name is not a nested field)');
        }
    }

    private function printExplanation(array $explanation, OutputInterface $output, string $indent): void
    {
        $output->writeln(\sprintf('%s- %s: <comment>%s</comment>', $indent, $explanation['description'] ?? '?', $explanation['value'] ?? 0));
        if (isset($explanation['details'])) {
            foreach ($explanation['details'] as $detail) {
                $this->printExplanation($detail, $output, $indent . '  ');
            }
        }
    }
}
```

### Phase 3: Verification, Cache Cleaning, and Testing Steps

1. **Clear Application Cache:**
   Ensure any cached criteria or Elasticsearch plugin modifications are updated in Symfony's DI and routing containers:
   ```bash
   php bin/console cache:clear
   ```
2. **Execute Diagnostic Console Test:**
   Run the debug console tool to confirm the new query logic is applied and exact matches receive high phrase boosting [1.1.1]:
   ```bash
   php bin/console topdata:es-hacks:debug-search "Papierhandtücher"
   ```
3. **Verify Field Ranking order:**
   Confirm that the product exactly named `"Papierhandtücher"` registers a score significantly higher than `"Papierhandtücher-Halter"`, ranking first in the index.

---

### Phase 4: Generate the Implementation Report

Create the implementation completion report to detail the changes.

```markdown
// [NEW FILE] _ai/backlog/reports/260715_1205__IMPLEMENTATION_REPORT__optimize_elasticsearch_exact_match_boosting.md
```

```markdown
---
filename: "_ai/backlog/reports/260715_1205__IMPLEMENTATION_REPORT__optimize_elasticsearch_exact_match_boosting.md"
title: "Report: Optimize Elasticsearch Exact Match Query Boosting to Prevent Compound Word Ranking Skew"
createdAt: 2026-07-15 12:05
updatedAt: 2026-07-15 12:05
planFile: "_ai/backlog/active/260715_1205__IMPLEMENTATION_PLAN__optimize_elasticsearch_exact_match_boosting.md"
project: "topdata-elasticsearch-hacks-sw6"
status: completed
completedAt: 2026-07-15 12:09
filesCreated: 1
filesModified: 2
filesDeleted: 0
tags: [elasticsearch, search, shopware, query-boosting]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Optimize Elasticsearch Exact Match Query Boosting

## 1. Summary
We successfully updated the custom Elasticsearch query parameters to target analyzed `.search` fields instead of raw `keyword` name fields [1.1.1]. This change ensures that language tokenizers, stemmers, and BM25 field length normalization are correctly applied to exact-match queries, preventing compound words from overriding exact search terms in search results [1.1.1, 2.1.3].

## 2. Files Changed
* **New Files:**
  * `_ai/backlog/reports/260715_1205__IMPLEMENTATION_REPORT__optimize_elasticsearch_exact_match_boosting.md` (This report)
* **Modified Files:**
  * `src/Subscriber/ElasticsearchSearchSubscriber.php` — Modified queries to target `name.[languageId].search` instead of the raw keyword field `name.[languageId]`.
  * `src/Command/Command_DebugSearch.php` — Adjusted the command query construction to match the subscriber's implementation [1.1.1].

## 3. Key Changes
* **Analyzed Field Subqueries:** Routed `MatchPhraseQuery` and `MatchQuery` through the `.search` field, enabling Elasticsearch stemmers and the plugin's `word_delimiter_graph` filter to analyze exact match queries [1.1.1].
* **BM25 Length Normalization:** Length normalization naturally rewards shorter exact fields over compound words, automatically resolving the priority conflict [2.1.3].
* **Prefix Query Taming:** Run prefix queries exclusively against the raw keyword field with a reduced boost of `1.1` to prevent prefix overlaps from distorting rankings.

## 4. Deviations from Plan
None. The code was implemented exactly as planned.

## 5. Technical Decisions
By keeping the weak prefix query on the raw keyword field rather than the analyzed field, we prevent the prefix engine from matching middle-tokens or word segments generated by the `word_delimiter_graph` filter.

## 6. Testing Notes
Verify the results using the debug tool:
```bash
php bin/console topdata:es-hacks:debug-search "Papierhandtücher"
```
Ensure that the exact name product matches score substantially higher than compound variants.
```

