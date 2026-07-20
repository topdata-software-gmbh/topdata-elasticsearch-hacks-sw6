<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SearchAnalyticsService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:es-hacks:consolidate-search-logs',
    description: 'Consolidates raw search logs into aggregated analytics'
)]
class Command_ConsolidateSearchLogs extends AbstractTopdataCommand
{
    public function __construct(private readonly SearchAnalyticsService $analyticsService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('batch-size', 'b', InputOption::VALUE_REQUIRED, 'Max session tokens to process in one run', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');

        CliLogger::info(sprintf('Starting search log consolidation with batch size %d...', $batchSize));

        try {
            $processed = $this->analyticsService->consolidate($batchSize);
            CliLogger::success(sprintf('Consolidation finished. Consolidated %d raw search event(s).', $processed));
        } catch (\Throwable $e) {
            CliLogger::error(sprintf('Consolidation failed: %s', $e->getMessage()));
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
