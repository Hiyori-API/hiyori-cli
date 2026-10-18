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
    name: 'hiyori-id',
    description: 'Allocate, report and verify the stable global hiyori_id values',
    aliases: ['ids'],
)]
final class HiyoriIdCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backfill', null, InputOption::VALUE_NONE, 'Allocate ids for docs missing hiyori_id (does not record the migration)')
            ->addOption('resolve-duplicate-refs', null, InputOption::VALUE_NONE, 'Opt-in: drop duplicate identity refs from losing registry rows (never changes a hiyori_id)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'With --resolve-duplicate-refs: report what would change without writing')
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Verify id/slug/registry integrity (exit 1 on failure)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $migrator = $this->hiyori->getMigrator();

        $didWork = false;
        $indexesBlocked = false;

        if ($input->getOption('backfill')) {
            $summary = $migrator->allocateHiyoriIds();
            $didWork = true;

            // The unique identity indexes cannot be built while duplicate
            // references exist; never fail the backfill for that, but make the
            // operator's next step explicit.
            $conflicts = $migrator->duplicateRegistryIdentityKeys();
            $indexesBlocked = $conflicts['total'] > 0;

            if ($asJson) {
                $output->writeln(json_encode([
                    'backfill' => $summary,
                    'registry_identity_conflicts' => $conflicts,
                ], JSON_PRETTY_PRINT));
            } else {
                $io->section('Hiyori ID backfill');
                $rows = [];
                foreach ($summary as $kind => $info) {
                    $rows[] = [$kind, $info['collection'], $info['allocated'], $info['reused'], $info['missing'], $info['orphans'], $info['next_id']];
                }
                $table = $io->createTable();
                $table->setHeaders(['Kind', 'Collection', 'Allocated', 'Reused', 'Missing', 'Registry orphans', 'Next id']);
                $table->setRows($rows);
                $table->render();

                if ($indexesBlocked) {
                    $io->warning(sprintf(
                        '%d duplicate identity reference(s) in the registry block the unique indexes. Run `hiyori-id --resolve-duplicate-refs --dry-run` to review, then without --dry-run to resolve.',
                        $conflicts['total'],
                    ));
                }
            }
        }

        if ($input->getOption('resolve-duplicate-refs')) {
            $didWork = true;

            $resolution = $migrator->stripDuplicateRegistryIdentityKeys(dryRun: $dryRun);
            // A dry run must not write anything, so the index step is only
            // attempted for real. The resolution report above already shows
            // exactly which refs would be dropped.
            $indexes = null;
            if (!$dryRun) {
                $indexes = $migrator->ensureRegistryIndexes();
                $indexesBlocked = $indexes['blocked'] !== [];
            }

            if ($asJson) {
                $payload = ['resolve' => $resolution];
                if ($indexes !== null) {
                    $payload['indexes'] = $indexes;
                }
                $output->writeln(json_encode($payload, JSON_PRETTY_PRINT));
            } else {
                $io->section($dryRun ? 'Duplicate registry identity refs (dry run)' : 'Duplicate registry identity refs resolved');
                $io->text(sprintf('%d duplicate reference(s) %s (hiyori_ids never change).', $resolution['resolved'], $dryRun ? 'would be resolved' : 'resolved'));
                if ($resolution['keys'] !== []) {
                    $rows = [];
                    foreach ($resolution['keys'] as $key) {
                        $rows[] = [
                            $key['kind'],
                            $key['source'],
                            $key['key'],
                            '#' . $key['keep'],
                            implode('/', array_map(static fn (int $id): string => '#' . $id, $key['dropped'])),
                        ];
                    }
                    $table = $io->createTable();
                    $table->setHeaders(['Kind', 'Source', 'Value', 'Kept on', 'Removed from']);
                    $table->setRows($rows);
                    $table->render();
                }

                if ($indexes !== null) {
                    $io->text(sprintf('Identity indexes created: %d | present: %d', count($indexes['created']), count($indexes['present'])));
                    foreach ($indexes['blocked'] as $key => $info) {
                        $io->warning(sprintf('refs.%s still has %d duplicate value(s) (%s); unique index not created.', $key, $info['duplicates'], $info['reason']));
                    }
                }
            }

            if (!$dryRun && $indexesBlocked) {
                $io->error('Some identity indexes could not be created because duplicate references remain.');
            }
        }

        if ($input->getOption('verify')) {
            $didWork = true;
            $result = $migrator->verifyHiyoriIds();

            if ($asJson) {
                $output->writeln(json_encode(['verify' => $result], JSON_PRETTY_PRINT));
            } else {
                $io->section('Hiyori ID verification');
                $io->text(sprintf(
                    'Registry rows: %d | anime docs: %d (missing %d) | manga docs: %d (missing %d) | duplicate registry refs: %d | duplicate document refs: %d',
                    $result['checked']['registry'] ?? 0,
                    $result['checked']['anime'] ?? 0,
                    $result['checked']['anime_missing'] ?? 0,
                    $result['checked']['manga'] ?? 0,
                    $result['checked']['manga_missing'] ?? 0,
                    $result['checked']['registry_duplicate_refs'] ?? 0,
                    $result['checked']['document_duplicate_refs'] ?? 0,
                ));
                if ($result['ok']) {
                    $io->success('All hiyori_id checks passed.');
                } else {
                    foreach ($result['problems'] as $problem) {
                        $io->writeln('  - ' . $problem);
                    }
                    $io->error('Found hiyori_id problems (showing up to 20).');
                }
            }

            if (!$result['ok']) {
                return Command::FAILURE;
            }
        }

        if ($input->getOption('resolve-duplicate-refs') && !$dryRun && $indexesBlocked) {
            return Command::FAILURE;
        }

        if (!$didWork) {
            $report = $migrator->hiyoriIdReport();

            if ($asJson) {
                $output->writeln(json_encode($report, JSON_PRETTY_PRINT));

                return Command::SUCCESS;
            }

            $io->section('Hiyori ID report');
            $rows = [];
            foreach ($report['collections'] as $kind => $info) {
                $rows[] = [$kind, $info['collection'], $info['total'], $info['allocated'], $info['missing'], $info['duplicates']];
            }
            $table = $io->createTable();
            $table->setHeaders(['Kind', 'Collection', 'Total', 'Allocated', 'Missing', 'Duplicates']);
            $table->setRows($rows);
            $table->render();

            $io->text(sprintf(
                'Next id: %d | Registry rows: %d | Duplicate ids: %d',
                $report['next_id'],
                $report['registry_size'],
                $report['duplicate_count'],
            ));
        }

        return Command::SUCCESS;
    }
}
