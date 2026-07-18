<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use OpenSearch\Client;
use Shopware\Core\Defaults;
use Shopware\Elasticsearch\Framework\ElasticsearchHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;

#[AsCommand(
    name: 'topdata:es-hacks:debug-search',
    description: 'Debug ES search scoring for a given term'
)]
class Command_DebugSearch extends AbstractTopdataCommand
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
            ->addOption('language-id', 'l', InputOption::VALUE_REQUIRED, 'Language ID (hex UUID, e.g. 2fbb5fe2e29a4d70aa5854ce7ce3e20b). Restricts query to this language and uses it for display. Defaults to the shop system language.', '')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Number of results', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');
        $limit = (int) $input->getOption('limit');
        $languageId = (string) $input->getOption('language-id');
        if ($languageId !== '') {
            $languageId = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $languageId) ?? '');
        }
        if ($languageId === '') {
            $languageId = Defaults::LANGUAGE_SYSTEM;
            $output->writeln(\sprintf('<comment>Using default language: %s</comment>', $languageId));
        }

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

        $query = $this->buildSearchQuery($term, $actualIndex, $languageId);
        $output->writeln('');
        if ($languageId !== null && $languageId !== '') {
            $output->writeln(\sprintf('<comment>=== Language filter: %s ===</comment>', $languageId));
        }
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
            $name = $this->extractName($hit, $languageId);
            $output->writeln('');
            $output->writeln(\sprintf('<info>#%d: %s (score: %s)</info>',
                $i + 1,
                $name !== '' ? $name : $hit['_id'],
                $hit['_score']
            ));
            $output->writeln(\sprintf('  ID: %s', $hit['_id']));
            if (isset($hit['_explanation'])) {
                $this->printExplanation($hit['_explanation'], $output, '  ');
            }
        }

        return Command::SUCCESS;
    }

    private function buildSearchQuery(string $term, string $index, ?string $languageId): array
    {
        $lowerTerm = mb_strtolower($term);
        $languageFields = $this->getLanguageFields($index, $languageId);

        $should = [];
        foreach ($languageFields as $analyzedField => $keywordField) {
            // Derive the delimiter sub-field name
            $delimiterField = str_replace('.search', '.delimiter', $analyzedField);

            // 1. Clean EXACT Match Phrase (boost 30.0)
            $should[] = ['match_phrase' => [$analyzedField => ['query' => $lowerTerm, 'boost' => 30.0]]];

            // 2. Clean FULL text match (boost 20.0)
            $should[] = ['match' => [$analyzedField => ['query' => $lowerTerm, 'boost' => 20.0, 'operator' => 'and']]];

            // 3. Fallback Delimiter match (boost 15.0)
            $should[] = ['match' => [$delimiterField => ['query' => $lowerTerm, 'boost' => 15.0, 'operator' => 'and']]];

            // 4. Standalone Word Wildcards (boost 15.0)
            $should[] = ['wildcard' => [$keywordField => ['value' => sprintf('* %s *', $lowerTerm), 'boost' => 15.0]]];
            $should[] = ['wildcard' => [$keywordField => ['value' => sprintf('%s *', $lowerTerm), 'boost' => 15.0]]];

            // 5. Prefix fallback
            $should[] = ['prefix' => [$keywordField => ['value' => $lowerTerm, 'boost' => 1.1]]];
        }

        return [
            'query' => [
                'bool' => [
                    'should' => $should,
                ],
            ],
        ];
    }

    /**
     * @return array<string,string> map of analyzed-field => keyword-field
     */
    private function getLanguageFields(string $index, ?string $languageId): array
    {
        $mapping = $this->client->indices()->getMapping(['index' => $index]);
        $props = $mapping[$index]['mappings']['properties'] ?? [];

        if (!isset($props['name']['properties'])) {
            return ['name.search' => 'name'];
        }

        $langIds = array_keys($props['name']['properties']);

        if ($languageId !== null && $languageId !== '') {
            $langIds = array_filter($langIds, static fn (string $id): bool => $id === $languageId);
            if (empty($langIds)) {
                $longForm = '0x' . substr($languageId, 0, 8);
                $langIds = array_filter(array_keys($props['name']['properties']), static fn (string $id): bool => str_starts_with($id, $longForm));
            }
            if (empty($langIds)) {
                return [];
            }
        }

        $fields = [];
        foreach ($langIds as $langId) {
            $fields['name.' . $langId . '.search'] = 'name.' . $langId;
        }

        return $fields;
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

    private function extractName(array $hit, ?string $languageId): string
    {
        $name = $hit['_source']['name'] ?? null;
        if (is_array($name)) {
            if ($languageId !== null && $languageId !== '' && isset($name[$languageId]) && is_string($name[$languageId]) && $name[$languageId] !== '') {
                return $name[$languageId];
            }

            foreach ($name as $value) {
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return '';
        }

        return (string) ($name ?? '');
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
