<?php declare(strict_types=1);

namespace Topdata\TopdataElasticsearchHacksSW6\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Topdata\TopdataElasticsearchHacksSW6\Service\SynonymService;
use Topdata\TopdataFoundationSW6\Command\AbstractTopdataCommand;

#[AsCommand(
    name: 'topdata:es-hacks:export-synonyms',
    description: 'Exports current synonym records from the database into a backup text file'
)]
class Command_ExportSynonyms extends AbstractTopdataCommand
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path (prints to screen if omitted)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputPath = $input->getOption('output');

        try {
            $content = $this->synonymService->exportToString();

            if ($outputPath !== null) {
                if (\file_put_contents($outputPath, $content) === false) {
                    throw new \RuntimeException(sprintf('Could not write output to path "%s".', $outputPath));
                }
                $output->writeln(sprintf('<info>Export complete. Mappings written to "%s".</info>', $outputPath));
            } else {
                $output->write($content . "\n");
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>Export operation aborted: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
