<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Framework\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService;

#[AsMessageHandler(handles: ConsolidateSearchLogsTask::class)]
class ConsolidateSearchLogsTaskHandler extends ScheduledTaskHandler
{
    public function __construct(
        $scheduledTaskRepository,
        private readonly SearchAnalyticsService $analyticsService
    ) {
        parent::__construct($scheduledTaskRepository);
    }

    public function run(): void
    {
        try {
            $this->analyticsService->consolidate(250);
        } catch (\Throwable $e) {
        }
    }
}
