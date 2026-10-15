<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Ml\LabelStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manage human-in-the-loop pair labels (`_labels`) used by `ml:export-pairs`.
 *
 *   php hiyori labels add --base-source myanimelist --base-id 1 \
 *       --candidate-source anilist --candidate-id 2 --label 1
 *   php hiyori labels stats
 *   php hiyori labels list --base-source myanimelist --base-id 1
 */
#[AsCommand(
    name: 'labels',
    description: 'Add / inspect human pair labels for ML training (agree/disagree)',
)]
final class LabelsCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'add | stats | list', 'stats')
            ->addOption('base-source', null, InputOption::VALUE_OPTIONAL, 'Base source shorthand (e.g. mal)', '')
            ->addOption('base-id', null, InputOption::VALUE_OPTIONAL, 'Base source id', '')
            ->addOption('candidate-source', null, InputOption::VALUE_OPTIONAL, 'Candidate source shorthand (e.g. al)', '')
            ->addOption('candidate-id', null, InputOption::VALUE_OPTIONAL, 'Candidate source id', '')
            ->addOption('label', null, InputOption::VALUE_OPTIONAL, '1 = agree (same entry), 0 = disagree', '1')
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Who labelled it', 'human')
            ->addOption('notes', null, InputOption::VALUE_OPTIONAL, 'Free-text note', '')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $store = LabelStore::create($this->hiyori->getDatabase());
        $action = (string) $input->getArgument('action');

        switch ($action) {
            case 'add':
                $payload = [
                    'base_source' => (string) $input->getOption('base-source'),
                    'base_id' => (string) $input->getOption('base-id'),
                    'candidate_source' => (string) $input->getOption('candidate-source'),
                    'candidate_id' => (string) $input->getOption('candidate-id'),
                    'label' => (int) $input->getOption('label'),
                    'source' => (string) $input->getOption('source'),
                    'notes' => (string) $input->getOption('notes'),
                ];
                if (!$store->upsert($payload)) {
                    $io->error('base-source/base-id/candidate-source/candidate-id are required.');

                    return Command::FAILURE;
                }
                $io->success(\sprintf(
                    'Label %d stored for %s:%s ↔ %s:%s',
                    (int) $payload['label'] >= 1 ? 1 : 0,
                    $payload['base_source'],
                    $payload['base_id'],
                    $payload['candidate_source'],
                    $payload['candidate_id'],
                ));

                return Command::SUCCESS;

            case 'list':
                $baseSource = (string) $input->getOption('base-source');
                $baseId = (string) $input->getOption('base-id');
                if ($baseSource !== '' && $baseId !== '') {
                    $labels = $store->forBase($baseSource, $baseId);
                    if ($input->getOption('json')) {
                        $output->writeln((string) json_encode($labels, JSON_PRETTY_PRINT));

                        return Command::SUCCESS;
                    }
                    if ($labels === []) {
                        $io->text('No labels for that pair base.');

                        return Command::SUCCESS;
                    }
                    foreach ($labels as $key => $label) {
                        $io->text(\sprintf('%s → %s', $key, $label === 1 ? 'agree' : 'disagree'));
                    }

                    return Command::SUCCESS;
                }
                $io->error('list needs --base-source and --base-id.');

                return Command::FAILURE;

            case 'stats':
            default:
                $stats = $store->stats();
                if ($input->getOption('json')) {
                    $output->writeln((string) json_encode($stats, JSON_PRETTY_PRINT));

                    return Command::SUCCESS;
                }
                $io->section('Label stats');
                $io->text(\sprintf('total: %d (%d agree / %d disagree)', $stats['total'], $stats['positives'], $stats['negatives']));
                if ($stats['by_source'] !== []) {
                    foreach ($stats['by_source'] as $source => $count) {
                        $io->text(\sprintf('  %s: %d', $source, $count));
                    }
                }

                return Command::SUCCESS;
        }
    }
}
