<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Service\Ml\FellegiSunterCalibration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fit the Fellegi–Sunter m/u probabilities from labelled pairs.
 *
 *   php hiyori combine:fs-calibrate                       # newest *-train.jsonl
 *   php hiyori combine:fs-calibrate --input storage/ml/pairs-...-train.jsonl
 *
 * Writes `storage/state/fellegi-sunter-calibration.json` and prints the
 * `--config` JSON to feed back into a combine run. The strategy also reads
 * that file directly when configured with `mu_file`.
 */
#[AsCommand(
    name: 'combine:fs-calibrate',
    description: 'Fit Fellegi-Sunter m/u probabilities from labelled pairs',
)]
final class CombineFsCalibrateCommand extends Command
{
    private const DEFAULT_OUT = 'storage/state/fellegi-sunter-calibration.json';

    protected function configure(): void
    {
        $this
            ->addOption('input', null, InputOption::VALUE_OPTIONAL, 'Labelled pairs JSONL (default: newest storage/ml/pairs-*-train.jsonl)')
            ->addOption('cutoff', null, InputOption::VALUE_OPTIONAL, 'Agreement cutoff the strategy uses (default 0.85)', '0.85')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'Where to save the calibration', self::DEFAULT_OUT)
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = \dirname(__DIR__, 2);
        $cutoff = (float) $input->getOption('cutoff');
        $inputPath = (string) $input->getOption('input');

        if ($inputPath === '') {
            $inputPath = $this->newestTrainFile($root);
            if ($inputPath === null) {
                $io->error('No pairs file found. Run `php hiyori ml:export-pairs --holdout 30` first, or pass --input.');

                return Command::FAILURE;
            }
        }
        $inputPath = str_starts_with($inputPath, '/') ? $inputPath : $root . '/' . ltrim($inputPath, '/');

        try {
            $fitted = FellegiSunterCalibration::fit($inputPath, $cutoff);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $outPath = (string) $input->getOption('out');
        if (!str_starts_with($outPath, '/')) {
            $outPath = $root . '/' . ltrim($outPath, '/');
        }
        $dir = \dirname($outPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $payload = array_merge($fitted, [
            'agreement_cutoff' => $cutoff,
            'source' => $inputPath,
            'generated_at' => date('c'),
        ]);
        file_put_contents($outPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $config = FellegiSunterCalibration::toStrategyConfig($fitted, $cutoff);

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'input' => $inputPath,
                'out' => $outPath,
                'calibration' => $payload,
                'strategy_config' => $config,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $io->section('Fellegi-Sunter m/u (calibrated)');
        $io->text(\sprintf('From %d labelled pairs, agreement cutoff %.2f', array_sum(array_map(static fn (array $c): int => $c['n_pos'], $fitted['counts'])), $cutoff));
        $rows = [];
        foreach ($fitted['m'] as $field => $m) {
            $rows[] = [$field, number_format($m, 4), number_format($fitted['u'][$field], 4)];
        }
        $io->table(['field', 'm (P agree | match)', 'u (P agree | no match)'], $rows);
        $io->text(\sprintf('Saved to %s', $outPath));
        $io->text('Feed back with:');
        $io->block((string) json_encode(['mu_file' => (string) $input->getOption('out')], JSON_PRETTY_PRINT));

        return Command::SUCCESS;
    }

    private function newestTrainFile(string $root): ?string
    {
        $files = glob($root . '/storage/ml/pairs-*-train.jsonl') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
