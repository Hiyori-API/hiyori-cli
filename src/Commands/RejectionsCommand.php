<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Ml\RejectionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Work the rejection buffer (`pair_rejections`) — the review queue of turned-down
 * candidate pairs with multiclass verdicts (approve / reject / unsure).
 *
 *   php hiyori rejections add --base-source myanimelist --base-id 1 \
 *       --candidate-source anilist --candidate-id 2 --decision reject
 *   php hiyori rejections list --limit 20
 *   php hiyori rejections stats
 *   php hiyori rejections promote            # copy decided pairs into `_labels`
 */
#[AsCommand(
    name: 'rejections',
    description: 'Review the rejection buffer and promote decided pairs to training labels',
)]
final class RejectionsCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'add | list | stats | promote', 'stats')
            ->addOption('base-source', null, InputOption::VALUE_OPTIONAL, 'Base source shorthand (e.g. mal)', '')
            ->addOption('base-id', null, InputOption::VALUE_OPTIONAL, 'Base source id', '')
            ->addOption('candidate-source', null, InputOption::VALUE_OPTIONAL, 'Candidate source shorthand (e.g. al)', '')
            ->addOption('candidate-id', null, InputOption::VALUE_OPTIONAL, 'Candidate source id', '')
            ->addOption('decision', null, InputOption::VALUE_OPTIONAL, 'approve | reject | unsure', 'reject')
            ->addOption('source', null, InputOption::VALUE_OPTIONAL, 'Who rejected it', 'human')
            ->addOption('notes', null, InputOption::VALUE_OPTIONAL, 'Free-text note', '')
            ->addOption('score', null, InputOption::VALUE_OPTIONAL, 'metadata/ML score at rejection time', null)
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max rows', '50')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $store = RejectionStore::create($this->hiyori->getDatabase());
        $store->ensureIndexes();
        $action = (string) $input->getArgument('action');

        switch ($action) {
            case 'add':
                $payload = [
                    'base_source' => (string) $input->getOption('base-source'),
                    'base_id' => (string) $input->getOption('base-id'),
                    'candidate_source' => (string) $input->getOption('candidate-source'),
                    'candidate_id' => (string) $input->getOption('candidate-id'),
                    'decision' => (string) $input->getOption('decision'),
                    'source' => (string) $input->getOption('source'),
                    'notes' => (string) $input->getOption('notes'),
                    'score' => $input->getOption('score'),
                ];
                if (!$store->upsert($payload)) {
                    $io->error('base-source/base-id/candidate-source/candidate-id are required.');

                    return Command::FAILURE;
                }
                if ($input->getOption('json')) {
                    $output->writeln((string) json_encode(['ok' => true], JSON_PRETTY_PRINT));

                    return Command::SUCCESS;
                }
                $io->success(\sprintf(
                    'Rejection %s stored for %s:%s ↔ %s:%s',
                    (string) $payload['decision'],
                    $payload['base_source'],
                    $payload['base_id'],
                    $payload['candidate_source'],
                    $payload['candidate_id'],
                ));

                return Command::SUCCESS;

            case 'list':
                $decision = (string) $input->getOption('decision');
                $rows = $store->list($decision === 'reject' ? null : $decision, (int) $input->getOption('limit'));
                if ($input->getOption('json')) {
                    $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT));

                    return Command::SUCCESS;
                }
                if ($rows === []) {
                    $io->text('Rejection buffer is empty.');

                    return Command::SUCCESS;
                }
                $io->table(
                    ['decision', 'base', 'candidate', 'source', 'score', 'promoted', 'created'],
                    array_map(static function (array $r): array {
                        $created = $r['created_at'] ?? null;
                        if ($created instanceof \MongoDB\BSON\UTCDateTime) {
                            $created = $created->toDateTime()->format('Y-m-d H:i');
                        }

                        return [
                            (string) ($r['decision'] ?? ''),
                            \sprintf('%s:%s', (string) ($r['base_source'] ?? ''), (string) ($r['base_id'] ?? '')),
                            \sprintf('%s:%s', (string) ($r['candidate_source'] ?? ''), (string) ($r['candidate_id'] ?? '')),
                            (string) ($r['source'] ?? ''),
                            isset($r['score']) ? (string) $r['score'] : '—',
                            !empty($r['promoted']) ? 'yes' : 'no',
                            $created === null ? '—' : (string) $created,
                        ];
                    }, $rows),
                );

                return Command::SUCCESS;

            case 'promote':
                $result = $store->promote(null, (int) $input->getOption('limit') > 0 ? (int) $input->getOption('limit') : null);
                if ($input->getOption('json')) {
                    $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT));

                    return Command::SUCCESS;
                }
                $io->success(\sprintf(
                    'Promoted %d rejection(s) → %d training label(s) in `_labels`.',
                    $result['promoted'],
                    $result['labels'],
                ));

                return Command::SUCCESS;

            case 'stats':
            default:
                $store->ensureIndexes();
                $stats = $store->stats();
                if ($input->getOption('json')) {
                    $output->writeln((string) json_encode($stats, JSON_PRETTY_PRINT));

                    return Command::SUCCESS;
                }
                $io->section('Rejection buffer stats');
                $io->text(\sprintf('total: %d (%d pending / %d promoted)', $stats['total'], $stats['pending'], $stats['promoted']));
                foreach ($stats['by_decision'] as $decision => $count) {
                    $io->text(\sprintf('  %s: %d', $decision, $count));
                }
                if ($stats['by_source'] !== []) {
                    foreach ($stats['by_source'] as $source => $count) {
                        $io->text(\sprintf('  source %s: %d', $source, $count));
                    }
                }

                return Command::SUCCESS;
        }
    }
}
