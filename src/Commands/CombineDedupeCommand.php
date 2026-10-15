<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'combine:dedupe',
    description: 'Resolve duplicate identity keys by removing the key from the losing document (release gate)',
    aliases: ['dedupe'],
)]
final class CombineDedupeCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('collection', 'c', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Collection(s) to fix (default: every combined collection)')
            ->addOption('primary', null, InputOption::VALUE_OPTIONAL, 'Comma-separated identity keys that must stay unique', 'mal,al')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var list<string> $collections */
        $collections = array_values((array) $input->getOption('collection'));
        if ($collections === []) {
            $collections = array_values(Migrator::KINDS);
        }

        /** @var list<string> $primary */
        $primary = array_values(array_filter(array_map(
            static fn (string $key): string => strtolower(trim($key)),
            explode(',', (string) $input->getOption('primary')),
        )));

        $migrator = $this->hiyori->getMigrator();

        $reports = [];
        $total = 0;
        foreach ($collections as $collection) {
            $report = $migrator->stripDuplicateIdentityKeys($collection, $primary, $dryRun);
            $reports[$collection] = $report;
            $total += (int) $report['resolved'];
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'ok' => true,
                'primary_keys' => $primary,
                'dry_run' => $dryRun,
                'resolved' => $total,
                'collections' => $reports,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->section($dryRun ? 'Duplicate identity keys (dry run)' : 'Duplicate identity keys resolved');
        foreach ($reports as $name => $report) {
            $io->text(sprintf('<info>%s</info>: %d duplicate key(s) resolved', $name, $report['resolved']));
            if ($report['keys'] === []) {
                continue;
            }
            $rows = [];
            foreach ($report['keys'] as $key) {
                $rows[] = [
                    $key['source'],
                    $key['key'],
                    '#' . $key['keep'],
                    implode('/', array_map(static fn (int $id): string => '#' . $id, $key['dropped'])),
                ];
            }
            $table = $io->createTable();
            $table->setHeaders(['Source', 'Value', 'Kept on', 'Removed from']);
            $table->setRows($rows);
            $table->render();
        }

        if ($total === 0) {
            $io->success('No duplicate identity keys to resolve.');

            return Command::SUCCESS;
        }

        $io->success(sprintf('%d duplicate identity key(s) %s.', $total, $dryRun ? 'would be resolved' : 'resolved'));

        return Command::SUCCESS;
    }
}
