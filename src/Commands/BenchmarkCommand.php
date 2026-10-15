<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Service\ExportConfig;
use Hiyori\Service\Ml\BenchmarkEvaluator;
use Hiyori\Service\Ml\LogisticModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Offline precision/recall/F1 benchmark for combiner scorers.
 *
 * Reads the labelled JSONL from `ml:export-pairs` (each row has a 0/1 `label`
 * and the metadata-fuzzy `metadata_score`) and sweeps thresholds for both
 * `metadata_fuzzy` and `metadata_ml` (`ml-model.json`). Non-destructive: it
 * never touches MongoDB, so it can gate a default-strategy switch in CI.
 */
#[AsCommand(
    name: 'combine:benchmark',
    description: 'Offline precision/recall/F1 benchmark of combiner scorers from labelled pairs',
    aliases: ['bench'],
)]
final class BenchmarkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('in', 'i', InputOption::VALUE_OPTIONAL, 'Labelled JSONL (default: newest storage/ml/pairs-*.jsonl)', '')
            ->addOption('ml-model', null, InputOption::VALUE_OPTIONAL, 'Logistic model path', ExportConfig::rootDir() . '/storage/state/ml-model.json')
            ->addOption('thresholds', null, InputOption::VALUE_OPTIONAL, 'Comma-separated score thresholds (0-100)', '60,65,70,75,80,85,90')
            ->addOption('margin', null, InputOption::VALUE_OPTIONAL, 'Required F1 advantage (points 0-100) of metadata_ml over metadata_fuzzy for the gate', '0')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'JSON report path', ExportConfig::rootDir() . '/storage/state/combine-benchmark.json')
            ->addOption('log', null, InputOption::VALUE_OPTIONAL, 'Append-only JSONL log path', ExportConfig::rootDir() . '/storage/logs/combine-benchmark.jsonl')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Exit non-zero when the gate is not met (for CI)')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Print the JSON report instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getOption('in');
        if ($path === '') {
            $files = glob(ExportConfig::rootDir() . '/storage/ml/pairs-*.jsonl') ?: [];
            usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
            $path = $files[0] ?? '';
        }
        if ($path === '' || !is_file($path)) {
            $io->error('No labelled pairs found. Run `php hiyori ml:export-pairs` first.');

            return Command::FAILURE;
        }

        $rows = $this->readRows($path);
        if ($rows === []) {
            $io->error('No usable rows in ' . $path);

            return Command::FAILURE;
        }

        $labels = array_map(static fn (array $r): int => $r['label'], $rows);
        $fuzzyScores = array_map(static fn (array $r): float => $r['metadata_score'], $rows);

        $thresholds = array_values(array_filter(array_map('floatval', explode(',', (string) $input->getOption('thresholds')))));

        $report = [
            'created_at' => gmdate('c'),
            'input' => $path,
            'rows' => count($rows),
            'positives' => array_sum($labels),
            'negatives' => count($rows) - array_sum($labels),
            'thresholds' => $thresholds,
            'scorers' => [],
        ];

        $report['scorers']['metadata_fuzzy'] = $this->evaluate('metadata_fuzzy', $fuzzyScores, $labels, $thresholds);

        $model = LogisticModel::load((string) $input->getOption('ml-model'));
        if ($model === null) {
            $io->warning('No ML model at ' . $input->getOption('ml-model') . '; benchmarking metadata_fuzzy only.');
        } else {
            $mlScores = [];
            foreach ($rows as $row) {
                $mlScores[] = $model->predict($row['features']) * 100.0;
            }
            $report['scorers']['metadata_ml'] = $this->evaluate('metadata_ml', $mlScores, $labels, $thresholds);
            $report['ml_model_version'] = $model->version();
        }

        $requiredMargin = max(0.0, (float) $input->getOption('margin')) / 100.0;
        $baselineF1 = isset($report['scorers']['metadata_fuzzy']['best']['f1']) ? (float) $report['scorers']['metadata_fuzzy']['best']['f1'] : null;
        $mlF1 = isset($report['scorers']['metadata_ml']['best']['f1']) ? (float) $report['scorers']['metadata_ml']['best']['f1'] : null;
        $report['gate'] = BenchmarkEvaluator::gate($baselineF1, $mlF1, $requiredMargin);

        $out = (string) $input->getOption('out');
        $dir = \dirname($out);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        @file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @file_put_contents(
            (string) $input->getOption('log'),
            json_encode([
                'created_at' => $report['created_at'],
                'rows' => $report['rows'],
                'positives' => $report['positives'],
                'gate' => $report['gate'],
                'best' => array_map(static fn (array $s): array => $s['best'], $report['scorers']),
            ], JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND,
        );

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io->section(\sprintf('Benchmark: %d rows (%d pos / %d neg)', $report['rows'], $report['positives'], $report['negatives']));
            foreach ($report['scorers'] as $name => $scorer) {
                $io->text($name . ($name === 'metadata_ml' ? ' (model ' . $report['ml_model_version'] . ')' : ''));
                $io->table(
                    ['thr', 'pred', 'TP', 'FP', 'FN', 'precision', 'recall', 'F1'],
                    array_map(static fn (array $r): array => [
                        number_format($r['threshold'], 0),
                        $r['predicted'],
                        $r['tp'],
                        $r['fp'],
                        $r['fn'],
                        number_format($r['precision'], 3),
                        number_format($r['recall'], 3),
                        number_format($r['f1'], 3),
                    ], $scorer['sweep']),
                );
                $best = $scorer['best'];
                $io->text(\sprintf('  best F1 %.4f at threshold %.0f', $best['f1'], $best['threshold']));
            }
            if ($report['gate']['comparable']) {
                $io->text(\sprintf(
                    'gate: margin %.4f (required %.4f)',
                    (float) $report['gate']['margin'],
                    (float) $report['gate']['required_margin'],
                ));
                $io->writeln($report['gate']['passed']
                    ? '<info>GATE PASSED: metadata_ml beats metadata_fuzzy by at least the required margin.</info>'
                    : '<comment>GATE NOT MET: metadata_ml does not beat metadata_fuzzy by the required margin.</comment>');
            }
        }

        if ($input->getOption('strict') && $report['gate']['comparable'] && !$report['gate']['passed']) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<float> $scores
     * @param list<int>   $labels
     * @param list<float> $thresholds
     *
     * @return array{best: array<string, mixed>, sweep: list<array<string, mixed>>}
     */
    private function evaluate(string $name, array $scores, array $labels, array $thresholds): array
    {
        $sweep = BenchmarkEvaluator::sweep($scores, $labels, $thresholds);

        return ['name' => $name, 'best' => BenchmarkEvaluator::best($sweep), 'sweep' => $sweep];
    }

    /**
     * @return list<array{label: int, metadata_score: float, features: array<string, float>}>
     */
    private function readRows(string $path): array
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
            if (!is_array($data) || !isset($data['label'], $data['metadata_score']) || !is_array($data['features'] ?? null)) {
                continue;
            }
            $features = [];
            foreach ($data['features'] as $key => $value) {
                $features[(string) $key] = (float) $value;
            }
            $rows[] = [
                'label' => (int) $data['label'],
                'metadata_score' => (float) $data['metadata_score'],
                'features' => $features,
            ];
        }
        fclose($handle);

        return $rows;
    }
}
