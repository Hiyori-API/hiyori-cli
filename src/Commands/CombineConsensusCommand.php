<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\ExportConfig;
use Hiyori\Service\Ml\LogisticRegressionTrainer;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rank every combiner strategy by the merge decisions it actually made.
 *
 * Each strategy is run offline into its own `bench_<strategy>` scratch
 * collection (see `scripts/benchmark-strategies-all.sh`). This command replays a
 * single common pair sample (`ml:export-pairs`) against all of those
 * collections, so every strategy is judged on identical candidate pairs, and
 * scores each one three ways:
 *
 *   * vs consensus  — majority vote of the strategies themselves. Independent of
 *     the shipped `organized` collection, which makes it the only accuracy
 *     signal available for the non-scorer strategies.
 *   * vs organized  — the label the current shipping pipeline would produce.
 *   * vs human      — reviewer labels from the review UI, when present.
 *
 * Read-only: it never writes to MongoDB.
 */
#[AsCommand(
    name: 'combine:consensus',
    description: 'Rank combiner strategies by merge decisions vs consensus and human labels',
    aliases: ['consensus'],
)]
final class CombineConsensusCommand extends Command
{
    /** @var list<string> */
    private const DEFAULT_STRATEGIES = [
        'relational_mapping',
        'relational_fuzzy',
        'metadata_fuzzy',
        'graph_merge',
        'fellegi_sunter',
        'semantic_rerank',
        'metadata_ml',
    ];

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pairs', 'p', InputOption::VALUE_OPTIONAL, 'Pairs JSONL (default: newest storage/ml/pairs-*.jsonl)', '')
            ->addOption('prefix', null, InputOption::VALUE_OPTIONAL, 'Scratch collection prefix written by the sweep', 'bench_')
            ->addOption('strategies', null, InputOption::VALUE_OPTIONAL, 'Comma-separated strategies to evaluate', implode(',', self::DEFAULT_STRATEGIES))
            ->addOption('min-votes', null, InputOption::VALUE_OPTIONAL, 'Minimum strategy votes for a consensus positive', '3')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'JSON report path', ExportConfig::rootDir() . '/storage/state/combine-consensus.json')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Print the JSON report instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $path = (string) $input->getOption('pairs');
        if ($path === '') {
            $files = glob(ExportConfig::rootDir() . '/storage/ml/pairs-*.jsonl') ?: [];
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $path = $files[0] ?? '';
        }
        if ($path === '' || !is_file($path)) {
            $io->error('No pair sample found. Run `php hiyori ml:export-pairs --base anilist` first.');

            return Command::FAILURE;
        }

        $rows = $this->readPairs($path);
        if ($rows === []) {
            $io->error('No usable rows in ' . $path);

            return Command::FAILURE;
        }

        $prefix = (string) $input->getOption('prefix');
        $strategies = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('strategies')))));
        $db = $this->hiyori->getDatabase();

        /** @var array<string, list<bool>> $predictions */
        $predictions = [];
        $available = [];
        foreach ($strategies as $strategy) {
            $map = $this->loadRefMap($db->getCollection($prefix . $strategy));
            if ($map === null) {
                $io->warning('No data in ' . $prefix . $strategy . '; skipping.');
                continue;
            }
            $pred = [];
            foreach ($rows as $row) {
                $pred[] = $this->isMerged($map, $row);
            }
            $predictions[$strategy] = $pred;
            $available[] = $strategy;
        }

        if ($available === []) {
            $io->error('No ' . $prefix . '* collections found. Run `scripts/benchmark-strategies-all.sh` first.');

            return Command::FAILURE;
        }

        $voters = \count($available);
        $threshold = max(1, (int) $input->getOption('min-votes'), intdiv($voters, 2) + 1);

        $withConsensus = [];
        $consensusPositives = 0;
        foreach ($rows as $i => $row) {
            $votes = 0;
            foreach ($available as $strategy) {
                if ($predictions[$strategy][$i]) {
                    $votes++;
                }
            }
            $label = $votes >= $threshold ? 1 : 0;
            $consensusPositives += $label;
            $row['consensus'] = $label;
            $row['votes'] = $votes;
            $withConsensus[] = $row;
        }

        $report = [
            'created_at' => gmdate('c'),
            'pairs' => $path,
            'rows' => \count($rows),
            'strategies_available' => $available,
            'voters' => $voters,
            'consensus_threshold' => $threshold,
            'consensus_positives' => $consensusPositives,
            'organized_positives' => \count(array_filter($withConsensus, static fn (array $r): bool => $r['organized'] === 1)),
            'human_labelled' => \count(array_filter($withConsensus, static fn (array $r): bool => $r['human'] !== null)),
            'strategies' => [],
        ];

        $ranking = [];
        foreach ($available as $strategy) {
            $preds = $predictions[$strategy];
            $entry = [
                'strategy' => $strategy,
                'predicted_merges' => \count(array_filter($preds)),
                'agreement' => $this->agreement($preds, $withConsensus),
            ];
            $entry['vs_consensus'] = $this->scoreField($preds, $withConsensus, 'consensus');
            $entry['vs_organized'] = $this->scoreField($preds, $withConsensus, 'organized');
            $entry['vs_human'] = $this->scoreField($preds, $withConsensus, 'human');
            $report['strategies'][$strategy] = $entry;
            $ranking[] = [
                'strategy' => $strategy,
                'consensus_f1' => $entry['vs_consensus']['f1'] ?? null,
                'organized_f1' => $entry['vs_organized']['f1'] ?? null,
                'human_f1' => $entry['vs_human']['f1'] ?? null,
                'predicted_merges' => $entry['predicted_merges'],
            ];
        }

        usort($ranking, static function (array $a, array $b): int {
            return ($b['consensus_f1'] ?? -1.0) <=> ($a['consensus_f1'] ?? -1.0)
                ?: $a['strategy'] <=> $b['strategy'];
        });
        $report['ranking'] = $ranking;

        $out = (string) $input->getOption('out');
        $dir = \dirname($out);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents($out, (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->section(\sprintf(
            'Consensus benchmark: %d pairs, %d voters, threshold %d (%d consensus positives)',
            $report['rows'],
            $voters,
            $threshold,
            $consensusPositives,
        ));
        $table = [];
        foreach ($ranking as $i => $rank) {
            $s = $report['strategies'][$rank['strategy']];
            $table[] = [
                $i + 1,
                $rank['strategy'],
                (string) $s['predicted_merges'],
                number_format((float) $s['agreement'], 3),
                number_format((float) $s['vs_consensus']['precision'], 3),
                number_format((float) $s['vs_consensus']['recall'], 3),
                number_format((float) $s['vs_consensus']['f1'], 3),
                $s['vs_organized'] !== null ? number_format((float) $s['vs_organized']['f1'], 3) : 'n/a',
                $s['vs_human'] !== null ? number_format((float) $s['vs_human']['f1'], 3) : 'n/a',
            ];
        }
        $io->table(
            ['#', 'strategy', 'pred', 'agree', 'P', 'R', 'F1', 'org F1', 'human F1'],
            $table,
        );
        $io->text('vs_consensus = majority vote of the strategies; org F1 = against the shipped organized labels; human F1 = against review-UI labels.');
        $io->text('report: ' . $out);

        return Command::SUCCESS;
    }

    /**
     * Load `reference_ids` -> owner hiyori_id for a scratch collection.
     *
     * @return array<string, string>|null null when the collection is missing/empty
     */
    private function loadRefMap(Collection $collection): ?array
    {
        if ($collection->countDocuments() === 0) {
            return null;
        }
        $map = [];
        $cursor = $collection->find([], [
            'projection' => ['reference_ids' => 1, 'hiyori_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        foreach ($cursor as $doc) {
            /** @var array<string, mixed> $doc */
            $owner = (string) ($doc['hiyori_id'] ?? ($doc['_id'] ?? ''));
            $refs = $doc['reference_ids'] ?? null;
            if (!is_array($refs) || $owner === '') {
                continue;
            }
            foreach ($refs as $key => $value) {
                foreach (is_array($value) ? $value : [$value] as $one) {
                    if ($one === null || $one === '') {
                        continue;
                    }
                    $map[(string) $key . '|' . (string) $one] = $owner;
                }
            }
        }

        return $map;
    }

    /**
     * @param array<string, string>                     $map
     * @param array{base_source: string, base_id: string, candidate_source: string, candidate_id: string} $row
     */
    private function isMerged(array $map, array $row): bool
    {
        $base = $row['base_source'] . '|' . $row['base_id'];
        $candidate = $row['candidate_source'] . '|' . $row['candidate_id'];

        return isset($map[$base], $map[$candidate]) && $map[$base] === $map[$candidate];
    }

    /**
     * @param list<bool>              $preds
     * @param list<array<string, mixed>> $rows
     */
    private function agreement(array $preds, array $rows): float
    {
        $agree = 0;
        foreach ($preds as $i => $pred) {
            $isConsensus = ($rows[$i]['consensus'] ?? 0) === 1;
            if ($pred === $isConsensus) {
                $agree++;
            }
        }

        return $preds === [] ? 0.0 : round($agree / \count($preds), 4);
    }

    /**
     * @param list<bool>                 $preds
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, float|int>|null
     */
    private function scoreField(array $preds, array $rows, string $field): ?array
    {
        $scores = [];
        $labels = [];
        foreach ($rows as $i => $row) {
            $label = $row[$field] ?? null;
            if ($label === null) {
                continue;
            }
            $scores[] = ($preds[$i] ?? false) ? 100.0 : 0.0;
            $labels[] = (float) $label;
        }
        if ($scores === []) {
            return null;
        }
        $m = LogisticRegressionTrainer::metrics($scores, $labels, 50.0);

        return [
            'tp' => $m['tp'],
            'fp' => $m['fp'],
            'tn' => $m['tn'],
            'fn' => $m['fn'],
            'precision' => round($m['precision'], 4),
            'recall' => round($m['recall'], 4),
            'f1' => round($m['f1'], 4),
            'accuracy' => round($m['accuracy'], 4),
        ];
    }

    /**
     * @return list<array{base_source: string, base_id: string, candidate_source: string, candidate_id: string, organized: int|null, human: int|null}>
     */
    private function readPairs(string $path): array
    {
        $rows = [];
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $data = json_decode($line, true);
            if (!is_array($data)) {
                continue;
            }
            $base = $data['base'] ?? null;
            $candidate = $data['candidate'] ?? null;
            if (!is_array($base) || !is_array($candidate)) {
                continue;
            }
            $baseSource = (string) ($base['source'] ?? '');
            $baseId = (string) ($base['id'] ?? '');
            $candidateSource = (string) ($candidate['source'] ?? '');
            $candidateId = (string) ($candidate['id'] ?? '');
            if ($baseSource === '' || $baseId === '' || $candidateSource === '' || $candidateId === '') {
                continue;
            }
            $isHuman = ($data['label_source'] ?? 'organized') === 'human';
            $label = (int) ($data['label'] ?? 0);
            $rows[] = [
                'base_source' => $baseSource,
                'base_id' => $baseId,
                'candidate_source' => $candidateSource,
                'candidate_id' => $candidateId,
                'organized' => $isHuman ? null : $label,
                'human' => $isHuman ? $label : null,
            ];
        }
        fclose($handle);

        return $rows;
    }
}
