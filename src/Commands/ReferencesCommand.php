<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'references',
    description: 'Return all unique references from combined DB',
    aliases: ['refs'],
    hidden: false
)]
final class ReferencesCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Filter by specific source shorthand (e.g., mal, al, k)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $asJson = $input->getOption('json');

        $db = $this->hiyori->getDatabase();
        $refs = $db->getUniqueReferences();

        if ($source) {
            $source = strtolower($source);
            if (!isset($refs[$source])) {
                $io->warning("No references found for source: $source");

                return Command::SUCCESS;
            }
            $refs = [$source => $refs[$source]];
        }

        if ($asJson) {
            $output->writeln(json_encode($refs, JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->section('Unique References from Combined DB');
        foreach ($refs as $shorthand => $ids) {
            $io->text("<info>$shorthand</info>: " . count($ids) . " unique IDs");
            if ($input->getOption('verbose')) {
                foreach ($ids as $id) {
                    $io->text("  - $id");
                }
            } else {
                $io->text("  (use -v to list all " . count($ids) . " IDs)");
            }
        }

        return Command::SUCCESS;
    }
}
