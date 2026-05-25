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
