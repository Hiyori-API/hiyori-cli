<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Ml\FeatureExtractor;
use Hiyori\Service\SourceConfigurationFactory;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export labelled candidate pairs as JSONL for ML training / benchmarking.
 *
 * Ground truth comes from the `organized` collection: a pair is positive when
 * a single organized document carries both the base entry's and the candidate's
 * reference ids. Negatives are candidate-chain entries that do not share an
 * organized document with the base, which keeps label noise low (a base entry
 * with no organized document at all is skipped unless `--all`).
 */
#[AsCommand(
    name: 'ml:export-pairs',
    description: 'Export labelled candidate pairs (JSONL) for ML training/benchmarking',
)]
final class MlExportPairsCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('base', 'b', InputOption::VALUE_REQUIRED, 'Base source (e.g. myanimelist)', 'myanimelist')
            ->addOption('targets', 't', InputOption::VALUE_OPTIONAL, 'Comma-separated target sources (default: all other sources)', '')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Number of base entries to sample', 500)
            ->addOption('negatives', null, InputOption::VALUE_OPTIONAL, 'Max negatives per base entry', 3)
            ->addOption('collection', null, InputOption::VALUE_OPTIONAL, 'Ground-truth collection', 'organized')
            ->addOption('out', null, InputOption::VALUE_OPTIONAL, 'Output JSONL path (default: storage/ml/pairs-<ts>.jsonl)', '')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Also export negatives for base entries with no organized document')
            ->addOption('sample', null, InputOption::VALUE_OPTIONAL, 'Sampling mode: random|first', 'random');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $baseName = (string) $input->getOption('base');
        $limit = max(1, (int) $input->getOption('limit'));
        $maxNegatives = max(0, (int) $input->getOption('negatives'));
        $collectionName = (string) $input->getOption('collection');
        $includeUnmerged = (bool) $input->getOption('all');

        $sources = SourceConfigurationFactory::create();
        if (!$sources->exists($baseName)) {
            $io->error(\sprintf('Unknown source [%s].', $baseName));

            return Command::FAILURE;
        }
        $sources->setCurrent($baseName);
        $base = $sources->get();
        $baseShorthand = $base->getShorthand();

        $targets = [];
        foreach ($sources->getSources() as $name => $config) {
            if ($name === $baseName) {
                continue;
            }
            $targets[$name] = $config;
        }
        $targetFilter = array_filter(array_map('trim', explode(',', (string) $input->getOption('targets'))));
        if ($targetFilter !== []) {
            $targets = array_intersect_key($targets, array_flip($targetFilter));
        }
        if ($targets === []) {
            $io->error('No target sources selected.');

            return Command::FAILURE;
        }

        $db = $this->hiyori->getDatabase();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($base->getTable());
        /** @var Collection $organized */
        $organized = $db->getCollection($collectionName);
        $extractor = new FeatureExtractor();
        $weights = MetadataFuzzyStrategy::normalizeWeights(MetadataFuzzyStrategy::DEFAULT_WEIGHTS);

        $total = $baseCollection->countDocuments();
        $cursor = (string) $input->getOption('sample') === 'first' || $total <= $limit
            ? $baseCollection->find([], ['limit' => $limit, 'typeMap' => ['root' => 'array', 'document' => 'array']])
            : $baseCollection->aggregate([['$sample' => ['size' => $limit]]], ['typeMap' => ['root' => 'array', 'document' => 'array']]);

        $outPath = (string) $input->getOption('out');
        if ($outPath === '') {
            $outPath = \Hiyori\Service\ExportConfig::rootDir() . '/storage/ml/pairs-' . gmdate('Ymd-His') . '.jsonl';
        }
        $dir = \dirname($outPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $handle = @fopen($outPath, 'wb');
        if ($handle === false) {
            $io->error('Cannot open output file: ' . $outPath);

            return Command::FAILURE;
        }

        $rows = 0;
        $positives = 0;
        $negatives = 0;
        $sampled = 0;
        $skipped = 0;

        $io->section(\sprintf('Exporting pairs: base=%s targets=%s', $baseName, implode(',', array_keys($targets))));
        $io->text(\sprintf('Bases sampled: %d (limit %d of %d)', $limit, $limit, $total));

        foreach ($cursor as $entry) {
            $baseArray = Helper::convertMongoResultToArray($entry);
            $baseId = Helper::resolveReferenceId($baseArray, $baseShorthand);
            if ($baseId === '') {
                continue;
            }
            $sampled++;

            $goldenRefs = $this->goldenReferences($organized, $baseShorthand, $baseArray['reference_ids'][$baseShorthand] ?? $baseId);
            if ($goldenRefs === null && !$includeUnmerged) {
                $skipped++;

                continue;
            }
            $goldenRefs ??= [];

            foreach ($targets as $target) {
                $targetShorthand = $target->getShorthand();
                $positiveId = isset($goldenRefs[$targetShorthand]) ? (string) $goldenRefs[$targetShorthand] : null;

                if ($positiveId !== null) {
                    $positive = $db->get($target->getTable(), $targetShorthand, $positiveId, true);
                    if (is_array($positive)) {
                        $this->writeRow($handle, $extractor, $baseArray, $positive, 1, $baseShorthand, $baseId, $targetShorthand, $weights);
                        $positives++;
                        $rows++;
                    }
                }

                if ($maxNegatives === 0) {
                    continue;
                }

                $written = 0;
                foreach (MetadataFuzzyStrategy::candidates($db, $target->getTable(), $baseArray) as $candidate) {
                    $candidateId = Helper::resolveReferenceId($candidate, $targetShorthand);
                    if ($candidateId === '' || $candidateId === $positiveId) {
                        continue;
                    }
                    $this->writeRow($handle, $extractor, $baseArray, $candidate, 0, $baseShorthand, $baseId, $targetShorthand, $weights);
                    $negatives++;
                    $rows++;
                    if (++$written >= $maxNegatives) {
                        break;
                    }
                }
            }
        }

        fclose($handle);

        $meta = [
            'base' => $baseName,
            'targets' => array_keys($targets),
            'collection' => $collectionName,
            'features' => FeatureExtractor::FEATURES,
            'rows' => $rows,
            'positives' => $positives,
            'negatives' => $negatives,
            'sampled' => $sampled,
            'skipped_unmerged' => $skipped,
            'created_at' => gmdate('c'),
        ];
        @file_put_contents($outPath . '.meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $io->success(\sprintf('Wrote %d rows (%d positives / %d negatives) to %s', $rows, $positives, $negatives, $outPath));

        return Command::SUCCESS;
    }

    /**
     * @param resource $handle
     * @param array<string, float> $weights
     */
    private function writeRow(
        $handle,
        FeatureExtractor $extractor,
        array $base,
        array $candidate,
        int $label,
        string $baseShorthand,
        string $baseId,
        string $candidateShorthand,
        array $weights,
    ): void {
        $features = $extractor->extract($base, $candidate);
        $row = [
            'base' => ['source' => $baseShorthand, 'id' => $baseId],
            'candidate' => ['source' => $candidateShorthand, 'id' => Helper::resolveReferenceId($candidate, $candidateShorthand)],
            'label' => $label,
            'features' => $features,
            'vector' => array_values(array_map(static fn (string $f): float => $features[$f] ?? 0.0, FeatureExtractor::FEATURES)),
            'metadata_score' => round(MetadataFuzzyStrategy::calculateScore($base, $candidate, $weights), 2),
        ];
        fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @return array<string, mixed>|null reference_ids of the organized document, or null
     */
    private function goldenReferences(Collection $organized, string $baseShorthand, mixed $baseId): ?array
    {
        $doc = $organized->findOne(
            ['reference_ids.' . $baseShorthand => $baseId],
            ['typeMap' => ['root' => 'array', 'document' => 'array']],
        );

        if (!is_array($doc) || !is_array($doc['reference_ids'] ?? null)) {
            return null;
        }

        return $doc['reference_ids'];
    }
}
