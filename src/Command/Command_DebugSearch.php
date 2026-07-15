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
    name: 'topdata:debug:search',
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

        return [
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'dis_max' => [
                                'queries' => [
                                    [
                                        'bool' => [
                                            'should' => [
                                                $this->buildFieldQuery($languageField, $lowerTerm, 5.0),
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'should' => [
                        ['term' => [$languageField => ['value' => $lowerTerm, 'boost' => 10]]],
                        ['prefix' => [$languageField => ['value' => $lowerTerm, 'boost' => 5]]],
                    ],
                ],
            ],
        ];
    }

    private function buildFieldQuery(string $field, string $term, float $ranking): array
    {
        $tokens = preg_split('/\s+/u', $term, -1, \PREG_SPLIT_NO_EMPTY) ?: [$term];
        $tokenCount = count($tokens);

        $queries = [];

        $queries[] = ['dis_max' => ['queries' => [
            ['term' => [$field => ['value' => $term, 'boost' => 1]]],
            ['match' => [$field . '.search' => ['query' => $term, 'boost' => 0.8, 'fuzziness' => 'AUTO:3,8', 'prefix_length' => 1]]],
            ['prefix' => [$field . '.search' => ['value' => $term, 'boost' => 0.4]]],
        ], 'boost' => $ranking]];

        return ['dis_max' => ['queries' => $queries]];
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
