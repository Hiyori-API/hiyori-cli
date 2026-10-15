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
    name: 'combine:dupes',
    description: 'Detect duplicate reference keys in combined collections (release gate)',
    aliases: ['dupes'],
)]
final class CombineDupesCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('collection', 'c', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Collection(s) to scan (default: every combined collection)')
            ->addOption('primary', null, InputOption::VALUE_OPTIONAL, 'Comma-separated identity keys that must stay unique', 'mal,al')
            ->addOption('ignore-legacy', null, InputOption::VALUE_NONE, 'Only fail on collisions between current-schema docs (ignore stale legacy dumps)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

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
        foreach ($collections as $collection) {
            $reports[$collection] = $migrator->duplicateReferenceKeys($collection, $primary);
        }

        $ignoreLegacy = (bool) $input->getOption('ignore-legacy');
        $failed = false;
        foreach ($reports as $report) {
            $total = (int) $report['primary_duplicates'];
            $current = (int) $report['primary_duplicates_current'];
            if (($ignoreLegacy ? $current : $total) > 0) {
                $failed = true;
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'ok' => !$failed,
                'primary_keys' => $primary,
                'ignore_legacy' => $ignoreLegacy,
                'collections' => $reports,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $io->section('Reference key duplicates');
        foreach ($reports as $report) {
            $io->text(sprintf(
                '<info>%s</info>: %d docs (%d legacy array-formatted)',
                $report['collection'],
                $report['docs'],
                $report['legacy_docs'],
            ));

            $rows = [];
            foreach ($report['sources'] as $source => $info) {
                if ($info['duplicates'] === 0) {
                    continue;
                }
                $examples = [];
                foreach ($info['examples'] as $example) {
                    $examples[] = sprintf('%s -> #%s', $example['key'], implode('/#', $example['hiyori_ids']));
                }
                $rows[] = [
                    $source . ($info['primary'] ? ' *' : ''),
                    $info['values'],
                    $info['duplicates'],
                    $info['duplicates_current'],
                    $info['max'],
                    implode('; ', $examples),
                ];
            }

            if ($rows === []) {
                $io->text('  no duplicated keys');

                continue;
            }

            $table = $io->createTable();
            $table->setHeaders(['Source', 'Values', 'Dupes', 'Current', 'Max', 'Examples']);
            $table->setRows($rows);
            $table->render();
        }

        if ($failed) {
            $io->error(sprintf('Duplicate identity keys (%s) present -- release gate FAILED.', implode(', ', $primary)));
            if (!$ignoreLegacy) {
                $io->text('Tip: `--ignore-legacy` only fails on collisions between current-schema docs (stale dumps are ignored).');
            }

            return Command::FAILURE;
        }

        $io->success(sprintf('No duplicate identity keys (%s) -- release gate PASSED.', implode(', ', $primary)));

        return Command::SUCCESS;
    }
}
