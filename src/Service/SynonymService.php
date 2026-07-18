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
