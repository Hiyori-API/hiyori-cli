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
            ->addOption('primary', null, InputOption::VALUE_OPTIONAL, 'Comma-separated identity keys that must stay unique (default: per collection — mal,al for anime, mal_manga,al_manga for manga)')
            ->addOption('allow-empty', null, InputOption::VALUE_NONE, 'Treat a collection with no documents as passing instead of failing')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    /** Identity keys that must stay unique within a combined collection. */
    private static function identityKeys(string $collection): array
    {
        return str_contains($collection, 'manga') ? ['mal_manga', 'al_manga'] : ['mal', 'al'];
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
            explode(',', (string) ($input->getOption('primary') ?? '')),
        )));
        $allowEmpty = (bool) $input->getOption('allow-empty');

        $migrator = $this->hiyori->getMigrator();

        $reports = [];
        foreach ($collections as $collection) {
            // The gate used to check every collection against `mal,al`, so
            // organized_manga was validated in the anime namespace -- which
            // only "passed" because the manga rows were contaminated with anime
            // ids, and which would not have detected a real manga duplicate.
            $keys = $primary !== [] ? $primary : self::identityKeys($collection);
            $reports[$collection] = $migrator->duplicateReferenceKeys($collection, $keys) + ['primary_keys' => $keys];
        }

        $failed = false;
        $empty = [];
        foreach ($reports as $report) {
            if ((int) $report['primary_duplicates'] > 0) {
                $failed = true;
            }
            // "No duplicates" over zero documents is not a pass.
            if ((int) $report['docs'] === 0 && !$allowEmpty) {
                $failed = true;
                $empty[] = $report['collection'];
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'ok' => !$failed,
                'primary_keys' => $primary !== [] ? $primary : null,
                'empty_collections' => $empty,
                'collections' => $reports,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? Command::FAILURE : Command::SUCCESS;
        }

        $io->section('Reference key duplicates');
        foreach ($reports as $report) {
            $io->text(sprintf('<info>%s</info>: %d docs', $report['collection'], $report['docs']));

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
                    $info['max'],
                    implode('; ', $examples),
                ];
            }

            if ($rows === []) {
                $io->text('  no duplicated keys');

                continue;
            }

            $table = $io->createTable();
            $table->setHeaders(['Source', 'Values', 'Dupes', 'Max', 'Examples']);
            $table->setRows($rows);
            $table->render();
        }

        if ($empty !== []) {
            $io->error(sprintf(
                'release gate FAILED: %s has no documents, so the duplicate check proved nothing (use --allow-empty to accept this).',
                implode(', ', $empty),
            ));

            return Command::FAILURE;
        }

        if ($failed) {
            $checked = implode('; ', array_map(
                static fn (string $collection, array $report): string => $collection . '=' . implode(',', $report['primary_keys']),
                array_keys($reports),
                array_values($reports),
            ));
            $io->error(sprintf('Duplicate identity keys present -- release gate FAILED (%s).', $checked));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'No duplicate identity keys -- release gate PASSED (%s).',
            implode('; ', array_map(
                static fn (string $collection, array $report): string => $collection . '=' . implode(',', $report['primary_keys']),
                array_keys($reports),
                array_values($reports),
            )),
        ));

        return Command::SUCCESS;
    }
}
