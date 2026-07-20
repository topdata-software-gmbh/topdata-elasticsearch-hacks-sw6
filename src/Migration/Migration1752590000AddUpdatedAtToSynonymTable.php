<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752590000AddUpdatedAtToSynonymTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752590000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'topdata_es_synonym', 'updated_at')) {
            $connection->executeStatement('
                ALTER TABLE `topdata_es_synonym`
                ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
            ');
        }
    }

    private function columnExists(Connection $connection, string $table, string $column): bool
    {
        $result = $connection->fetchOne(
            'SHOW COLUMNS FROM `' . $table . '` LIKE :column',
            ['column' => $column]
        );

        return $result !== false;
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
