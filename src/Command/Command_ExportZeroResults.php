<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\ZeroSearchService;

#[AsCommand(
    name: 'topdata:es-hacks:export-zero-results',
    description: 'Export search queries that returned zero results for analysis or LLM synonym generation'
)]
class Command_ExportZeroResults extends Command
{
    private ZeroSearchService $zeroSearchService;

    public function __construct(ZeroSearchService $zeroSearchService)
    {
        parent::__construct();
        $this->zeroSearchService = $zeroSearchService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format (table, json, csv, markdown, llm-prompt)', 'table')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Limit the number of export records', '100')
            ->addOption('min-count', 'm', InputOption::VALUE_REQUIRED, 'Filter out searches with occurrences less than this value', '1')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Target file path to save the output instead of printing to CLI');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        $limit = (int) $input->getOption('limit');
        $minCount = (int) $input->getOption('min-count');
        $outputPath = $input->getOption('output');

        if ($format === 'table') {
            if ($outputPath) {
                $output->writeln('<error>File output path is not compatible with console "table" format. Choose json, csv, markdown, or llm-prompt.</error>');
                return Command::INVALID;
            }

            try {
                $data = $this->zeroSearchService->fetchZeroResults($limit, $minCount);
            } catch (\Throwable $e) {
                $output->writeln('<error>Failed to fetch data: ' . $e->getMessage() . '</error>');
                return Command::FAILURE;
            }

            if (empty($data)) {
                $output->writeln('<comment>No zero-result searches found matching the criteria.</comment>');
                return Command::SUCCESS;
            }

            $table = new Table($output);
            $table->setHeaders(['Term', 'Search Count', 'Last Searched At']);
            foreach ($data as $row) {
                $table->addRow([$row['term'], $row['count'], $row['last_searched_at'] ?? '-']);
            }
            $table->render();
            return Command::SUCCESS;
        }

        try {
            $formattedContent = $this->zeroSearchService->export($format, $limit, $minCount, $outputPath);
        } catch (\InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        } catch (\Throwable $e) {
            $output->writeln('<error>Export execution failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if ($formattedContent === '') {
            $output->writeln('<comment>No data exported (dataset was empty).</comment>');
            return Command::SUCCESS;
        }

        if ($outputPath) {
            $output->writeln(sprintf('<info>Successfully wrote export payload to "%s"</info>', $outputPath));
        } else {
            $output->write($formattedContent . "\n");
        }

        return Command::SUCCESS;
    }
}
