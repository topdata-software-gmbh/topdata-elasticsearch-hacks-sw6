<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;

#[AsCommand(
    name: 'topdata:es-hacks:list-synonyms',
    description: 'View and filter synonym records currently active in the database store'
)]
class Command_ListSynonyms extends Command
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this
            ->addOption('filter', 'f', InputOption::VALUE_REQUIRED, 'Search terms or synonyms containing this substring')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of synonym lines to list', '50')
            ->addOption('offset', 'o', InputOption::VALUE_REQUIRED, 'Offset count for manual pagination', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $input->getOption('filter');
        $limit = (int) $input->getOption('limit');
        $offset = (int) $input->getOption('offset');

        try {
            $list = $this->synonymService->listSynonyms($filter, $limit, $offset);
        } catch (\Throwable $e) {
            $output->writeln('<error>Failed to fetch synonyms: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($list)) {
            $output->writeln('<comment>No active synonym definitions found in database.</comment>');
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['Target Search Term', 'Mapped Synonym Group', 'Created/Modified At']);
        foreach ($list as $row) {
            $table->addRow([$row['term'], $row['synonyms'], $row['created_at']]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
