<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1754000000RenameTablesToTdehPrefix extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1754000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            RENAME TABLE `topdata_es_synonym` TO `tdeh_synonym`,
                         `topdata_es_zero_search` TO `tdeh_zero_search`
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
