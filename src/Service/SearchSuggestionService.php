<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;

class SearchSuggestionService
{
    public function __construct(private Connection $connection) {}

    public function search(string $query, int $limit = 5): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('COUNT(*)')
            ->from('tdeh_search_suggestion')
            ->where('active = 1')
            ->andWhere('term LIKE :query')
            ->setParameter('query', '%' . $query . '%');
        $total = (int) $qb->executeQuery()->fetchOne();

        $qb = $this->connection->createQueryBuilder();
        $qb->select(
            'id',
            'term',
            'target_type',
            'target_url',
            'target_params',
        )
            ->from('tdeh_search_suggestion')
            ->where('active = 1')
            ->andWhere('term LIKE :query')
            ->orderBy('priority', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('query', '%' . $query . '%');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return [
            'items' => array_map(fn(array $row) => [
                'id' => bin2hex($row['id']),
                'term' => $row['term'],
                'targetType' => $row['target_type'],
                'targetUrl' => $row['target_url'],
                'targetParams' => $row['target_params'] ? json_decode($row['target_params'], true) : null,
            ], $rows),
            'total' => $total,
        ];
    }
}
