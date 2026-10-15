<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Service\ExportConfig;
use Hiyori\Service\Ml\FeatureExtractor;
use Hiyori\Service\Ml\LogisticRegressionTrainer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Train the metadata_ml second-stage scorer from `ml:export-pairs` JSONL.
 *
 * Pure PHP logistic regression (no external deps), so a model can be trained
 * on any machine that can run the CLI.
 */
#[AsCommand(
    name: 'ml:train',
    description: 'Train the metadata_ml logistic-regression scorer from exported pairs',
)]
final class MlTrainCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('in', 'i', InputOption::VALUE_OPTIONAL, 'Training JSONL (default: newest storage/ml/pairs-*.jsonl)', '')
            ->addOption('out', 'o', InputOption::VALUE_OPTIONAL, 'Model output path', ExportConfig::rootDir() . '/storage/state/ml-model.json')
            ->addOption('epochs', null, InputOption::VALUE_OPTIONAL, 'Gradient-descent epochs', 400)
            ->addOption('lr', null, InputOption::VALUE_OPTIONAL, 'Learning rate', 0.5)
            ->addOption('l2', null, InputOption::VALUE_OPTIONAL, 'L2 regularisation', 0.001)
            ->addOption('threshold', null, InputOption::VALUE_OPTIONAL, 'Decision threshold for reported metrics', 0.5);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $in = (string) $input->getOption('in');
        if ($in === '') {
            $in = self::newestPairsFile();
        }
        if ($in === '' || !is_file($in)) {
            $io->error('No training file found. Run `php hiyori ml:export-pairs` first.');

            return Command::FAILURE;
        }

        $rows = self::readRows($in);
        if ($rows === []) {
            $io->error('Training file has no usable rows: ' . $in);

            return Command::FAILURE;
        }

        $trainer = new LogisticRegressionTrainer();
        $model = $trainer->train($rows, [
            'features' => FeatureExtractor::FEATURES,
            'epochs' => (int) $input->getOption('epochs'),
            'learning_rate' => (float) $input->getOption('lr'),
            'l2' => (float) $input->getOption('l2'),
            'threshold' => (float) $input->getOption('threshold'),
        ]);

        $out = (string) $input->getOption('out');
        $dir = \dirname($out);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $encoded = json_encode($model, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false || @file_put_contents($out, $encoded) === false) {
            $io->error('Cannot write model to ' . $out);

            return Command::FAILURE;
        }

        $io->section('Training complete');
        $io->text(\sprintf('Rows: %d (%d positives)', count($rows), (int) array_sum(array_column($rows, 'label'))));
        $metrics = $model['metrics'];
        $io->text(\sprintf(
            'Threshold %.2f → precision %.4f, recall %.4f, F1 %.4f, accuracy %.4f, AUC %.4f',
            $metrics['threshold'],
            $metrics['precision'],
            $metrics['recall'],
            $metrics['f1'],
            $metrics['accuracy'],
            $metrics['auc'],
        ));

        $weights = [];
        foreach ($model['features'] as $i => $feature) {
            $weights[$feature] = round((float) $model['weights'][$i], 4);
        }
        arsort($weights);
        $io->text('Feature weights (standardised):');
        foreach ($weights as $feature => $weight) {
            $io->text(\sprintf('  %-16s %+.4f', $feature, $weight));
        }
        $io->success('Model written to ' . $out);

        return Command::SUCCESS;
    }

    private static function newestPairsFile(): string
    {
        $files = glob(ExportConfig::rootDir() . '/storage/ml/pairs-*.jsonl') ?: [];
        if ($files === []) {
            return '';
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * @return list<array{vector: list<float>, label: int}>
     */
    private static function readRows(string $path): array
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
            if (!is_array($data) || !is_array($data['vector'] ?? null) || !isset($data['label'])) {
                continue;
            }
            $rows[] = [
                'vector' => array_values(array_map('floatval', $data['vector'])),
                'label' => (int) $data['label'],
            ];
        }
        fclose($handle);

        return $rows;
    }
}
