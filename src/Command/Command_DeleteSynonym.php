<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;

#[AsCommand(
    name: 'topdata:es-hacks:delete-synonym',
    description: 'Deletes a specific synonym configuration rule by key term'
)]
class Command_DeleteSynonym extends AbstractTopdataCommand
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addArgument('term', InputArgument::REQUIRED, 'The key search term mapping to delete');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $term = $input->getArgument('term');

        try {
            $success = $this->synonymService->deleteSynonym($term);
            if ($success) {
                $output->writeln(sprintf('<info>Successfully deleted synonym configuration for term "%s".</info>', $term));
            } else {
                $output->writeln(sprintf('<comment>No active synonym record matches the term "%s".</comment>', $term));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Operation failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
