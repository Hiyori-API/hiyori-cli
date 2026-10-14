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
            ->addOption('verify', null, InputOption::VALUE_NONE, 'Verify id/slug/registry integrity (exit 1 on failure)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $migrator = $this->hiyori->getMigrator();

        $didWork = false;

        if ($input->getOption('backfill')) {
            $summary = $migrator->allocateHiyoriIds();
            $didWork = true;

            if ($asJson) {
                $output->writeln(json_encode(['backfill' => $summary], JSON_PRETTY_PRINT));
            } else {
                $io->section('Hiyori ID backfill');
                $rows = [];
                foreach ($summary as $kind => $info) {
                    $rows[] = [$kind, $info['collection'], $info['allocated'], $info['missing'], $info['next_id']];
                }
                $table = $io->createTable();
                $table->setHeaders(['Kind', 'Collection', 'Allocated', 'Missing', 'Next id']);
                $table->setRows($rows);
                $table->render();
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
                    'Registry rows: %d | anime docs: %d (missing %d) | manga docs: %d (missing %d)',
                    $result['checked']['registry'] ?? 0,
                    $result['checked']['anime'] ?? 0,
                    $result['checked']['anime_missing'] ?? 0,
                    $result['checked']['manga'] ?? 0,
                    $result['checked']['manga_missing'] ?? 0,
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
