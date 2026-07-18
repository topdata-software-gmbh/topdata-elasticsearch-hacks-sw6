<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;
use Topdata\TopdataFoundationSW6\Util\CliLogger;

#[AsCommand(
    name: 'topdata:es-hacks:import-synonyms',
    description: 'Import synonym mapping rules from a generated text file back into the store database'
)]
class Command_ImportSynonyms extends AbstractTopdataCommand
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
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the text file containing synonym rules (one rule per line: term => synonym1, synonym2)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Validate and preview the import count without updating the database');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $count = $this->synonymService->importFromFile($filePath, $dryRun);
            if ($dryRun) {
                CliLogger::info(sprintf('[Dry-Run] Mappings checked. %d synonym rule(s) are valid and ready to import.', $count));
            } else {
                CliLogger::success(sprintf('Successfully imported %d synonym rule(s).', $count));
            }
        } catch (\Throwable $e) {
            CliLogger::error('Import failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
