<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ConsolidateSearchLogsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'topdata_es_hacks.consolidate_search_logs';
    }

    public static function getDefaultInterval(): int
    {
        return 3600;
    }
}
