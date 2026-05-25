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
    }
}
