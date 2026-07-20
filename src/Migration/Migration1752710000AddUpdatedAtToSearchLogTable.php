<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1752710000AddUpdatedAtToSearchLogTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1752710000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `tdeh_search_log`
            ADD COLUMN `updated_at` DATETIME(3) NULL AFTER `created_at`
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
