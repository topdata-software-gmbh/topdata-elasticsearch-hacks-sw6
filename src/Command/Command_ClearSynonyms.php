<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;

#[AsCommand(
    name: 'topdata:es-hacks:clear-synonyms',
    description: 'Bulk purges all active synonym mappings from the database'
)]
class Command_ClearSynonyms extends AbstractTopdataCommand
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip the interactive confirmation safety check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = (bool) $input->getOption('force');

        if (!$force) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                'This will delete ALL database-stored synonyms. Are you sure you want to proceed? [y/N]: ',
                false
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Operation aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        try {
            $this->synonymService->clearAllSynonyms();
            $output->writeln('<info>Successfully cleared all synonym mapping definitions from the database.</info>');
        } catch (\Throwable $e) {
            $output->writeln('<error>Truncate process failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
