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
    name: 'topdata:es-hacks:validate-synonyms',
    description: 'Validates formatting syntax of a local synonym text file without importing'
)]
class Command_ValidateSynonyms extends AbstractTopdataCommand
{
    private SynonymService $synonymService;

    public function __construct(SynonymService $synonymService)
    {
        parent::__construct();
        $this->synonymService = $synonymService;
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Path of the local synonyms txt file to validate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getArgument('file');

        try {
            $errors = $this->synonymService->validateFile($filePath);
        } catch (\Throwable $e) {
            $output->writeln('<error>Validation check failed to complete: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        if (empty($errors)) {
            $output->writeln('<info>Format verification successful. File contains valid explicit synonyms syntax.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<error>Syntax issues found! Found %d parsing error(s):</error>', count($errors)));
        foreach ($errors as $error) {
            $output->writeln(sprintf(
                ' - <comment>Line %d</comment>: "%s" -> <error>%s</error>',
                $error['line'],
                trim($error['content']),
                $error['error']
            ));
        }

        return Command::FAILURE;
    }
}
