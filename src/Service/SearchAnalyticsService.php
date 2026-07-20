<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

class SearchAnalyticsService
{
    private const TIME_THRESHOLD = 15;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function consolidate(int $batchSize = 100): int
    {
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
                foreach ($finalIntents as $intent) {
                    $this->upsertStat(
                        $intent['term'], 
                        $intent['result_count'], 
                        $intent['created_at']
                    );
                }

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
            return true;
        }

        if (str_starts_with($termA, $termB)) {
            return true;
        }

        $levenshtein = levenshtein($termA, $termB);
        $maxLength = max(strlen($termA), strlen($termB));
        $maxAllowedEdits = $maxLength > 6 ? 3 : 2;

        return $levenshtein <= $maxAllowedEdits;
    }

    private function resolveStreamToIntent(array $stream): array
    {
        $last = end($stream);

        if ($last['result_count'] > 0) {
            return $last;
        }

        foreach (array_reverse($stream) as $entry) {
            if ($entry['result_count'] > 0) {
                return $entry;
            }
        }

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
