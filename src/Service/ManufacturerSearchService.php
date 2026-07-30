<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Service;

use Doctrine\DBAL\Connection;

class ManufacturerSearchService
{
    public function __construct(private Connection $connection) {}

    public function search(string $query, int $limit = 5): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select('COUNT(*)')
            ->from('product_manufacturer', 'm')
            ->innerJoin('m', 'product_manufacturer_translation', 'mt', 'mt.product_manufacturer_id = m.id')
            ->where('mt.name LIKE :query')
            ->setParameter('query', '%' . $query . '%');
        $total = (int) $qb->executeQuery()->fetchOne();

        $qb = $this->connection->createQueryBuilder();
        $qb->select('m.id', 'mt.name', 'LOWER(mt.name) as name_lower')
            ->from('product_manufacturer', 'm')
            ->innerJoin('m', 'product_manufacturer_translation', 'mt', 'mt.product_manufacturer_id = m.id')
            ->where('mt.name LIKE :query')
            ->orderBy('name_lower', 'ASC')
            ->setMaxResults($limit)
            ->setParameter('query', '%' . $query . '%');

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return [
            'items' => array_map(fn(array $row) => [
                'id' => bin2hex($row['id']),
                'name' => $row['name'],
            ], $rows),
            'total' => $total,
        ];
    }
}
