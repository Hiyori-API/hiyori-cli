<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\CombineCheckpoint;
use Hiyori\Service\Combiner\CombineStats;
use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
use Hiyori\Service\Ml\FeatureExtractor;
use Hiyori\Service\Ml\LogisticModel;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use Hiyori\Service\Telemetry;
use MongoDB\Collection;
use RuntimeException;

/**
 * ML second-stage combiner (Level-0.5).
 *
 * Reuses the metadata_fuzzy candidate chain and anchor rejection, then scores
 * each surviving candidate with a logistic-regression model trained by
 * `ml:train` over `ml:export-pairs` features. No LLM or network call is made:
 * failing closed is impossible, but a missing model is a hard error so results
 * are never silently produced by an untrained scorer.
 *
 * The model file is produced by `php hiyori ml:train` (see
 * `docs/ml-augmentation-design.md`).
 */
final class MetadataMlStrategy extends CombinerStrategy
{
    use MergesEntries;

    public const NAME = 'metadata_ml';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): CombineStats {
        $weights = MetadataFuzzyStrategy::normalizeWeights($strategy->getConfig('weights') ?? MetadataFuzzyStrategy::weightsForKind($source->get()->getTable()));
        // Only match within the same kind (manga with manga, anime with anime).
        // Cross-kind candidates are vetoed by the type anchor anyway, so fetching
        // them is pure cost. Set `same_kind_candidates` to false to disable.
        $sameKindOnly = (bool) ($strategy->getConfig('same_kind_candidates') ?? true);
        $metadataFloor = (float) ($strategy->getConfig('metadata_floor') ?? 50.0);
        $mlThreshold = (float) ($strategy->getConfig('ml_threshold') ?? 0.5);
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $anchorRejection = (bool) ($strategy->getConfig('anchor_rejection') ?? true);
        $anchorYearTolerance = (int) ($strategy->getConfig('anchor_year_tolerance') ?? 1);
        $anchorEpisodes = (bool) ($strategy->getConfig('anchor_episodes') ?? false);
        $titleFloor = (float) ($strategy->getConfig('title_floor') ?? MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR);
        $passthrough = (bool) ($strategy->getConfig('passthrough') ?? false);
        $resume = (bool) ($strategy->getConfig('resume') ?? false);

        $modelPath = (string) ($strategy->getConfig('ml_model') ?? ExportConfig::rootDir() . '/storage/state/ml-model.json');
        $model = LogisticModel::load($modelPath);
        if ($model === null) {
            throw new RuntimeException(\sprintf(
                'metadata_ml: no usable model at %s. Run `php hiyori ml:export-pairs` then `php hiyori ml:train` first.',
                $modelPath,
            ));
        }

        $extractor = new FeatureExtractor();
        $tableName = $source->get()->getTable();

        // Keyset pagination + a checkpoint per batch replaces the single
        // long-lived cursor that a server can kill mid-run.
        $checkpoint = CombineCheckpoint::begin(self::NAME, $tableName, $collection, $resume);
        $counters = $checkpoint->counters([
            'skipped_cross_kind_sources' => 0,
            'examined' => 0,
            // `merged` counts distinct documents written; `total_merged` counts
            // matched sources (an entry matching both sources upserts the same
            // document twice), so the two are not interchangeable.
            'merged' => 0,
            'passthrough' => 0,
            'unresolved_base_id' => 0,
            'total_merged' => 0,
            'candidates_examined' => 0,
            'rejected_anchor' => 0,
            'rejected_title' => 0,
            'merged_sources' => [],
        ]);

        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat((string) ($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%'));
        $progressBar->start();
        if ((int) $counters['examined'] > 0) {
            $progressBar->setProgress(min((int) $counters['examined'], $total));
        }

        $scores = [];
        $mlScores = [];
        $telemetry = new Telemetry();

        foreach (self::baseEntryBatches($db, $tableName, $checkpoint, $counters) as $entryArray) {
            $counters['examined']++;
            $baseShorthand = $source->get()->getShorthand();
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
            if ($baseId === '') {
                $counters['unresolved_base_id']++;
            }
            $merged = false;

            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }
                if ($sameKindOnly && !MetadataFuzzyStrategy::sameKind($tableName, $currentSource->getTable())) {
                    $counters['skipped_cross_kind_sources'] = ($counters['skipped_cross_kind_sources'] ?? 0) + 1;
                    continue;
                }

                $candidates = MetadataFuzzyStrategy::candidates($db, $currentSource->getTable(), $entryArray);
                $counters['candidates_examined'] += count($candidates);

                $bestMatch = null;
                $bestProbability = 0.0;
                $bestMetadata = 0.0;

                foreach ($candidates as $candidate) {
                    if ($anchorRejection && MetadataFuzzyStrategy::isAnchorRejected($entryArray, $candidate, $anchorYearTolerance, $anchorEpisodes)) {
                        $counters['rejected_anchor']++;

                        continue;
                    }

                    if (MetadataFuzzyStrategy::isTitleRejected($entryArray, $candidate, $titleFloor)) {
                        $counters['rejected_title']++;

                        continue;
                    }

                    $metadataScore = MetadataFuzzyStrategy::calculateScore($entryArray, $candidate, $weights);
                    if ($metadataScore < $metadataFloor) {
                        continue;
                    }

                    $probability = $model->predict($extractor->vector($entryArray, $candidate));
                    if ($probability < $mlThreshold || $probability <= $bestProbability) {
                        continue;
                    }

                    $bestProbability = $probability;
                    $bestMetadata = $metadataScore;
                    $bestMatch = $candidate;
                }

                if ($bestMatch === null) {
                    continue;
                }

                $scores[] = $bestMetadata;
                $mlScores[] = $bestProbability;
                $counters['merged_sources'][$currentSource->getTable()] = ((int) ($counters['merged_sources'][$currentSource->getTable()] ?? 0)) + 1;

                if ($baseId === '') {
                    continue;
                }

                $entryArray['reference_ids'][$baseShorthand] = $baseId;
                $mergedEntry = Helper::mergeEntries($tableName, $entryArray, $bestMatch);
                $kind = self::targetKind($collection);
                $slug = Exporter::slugify((string) $mergedEntry->getTitle());
                $refs = $mergedEntry->getReferenceIds();

                $extra = [
                    'confidence' => $bestMetadata,
                    'ml_score' => round($bestProbability * 100, 2),
                    'ml_model' => 'logreg',
                    'ml_model_version' => $model->version(),
                    'ml_decision' => 'merge',
                    'ml_decided_at' => gmdate('c'),
                ];
                try {
                    $extra['hiyori_id'] = $db->allocateHiyoriId($kind, $refs, $slug);
                } catch (\Throwable) {
                    // Pre-v3 databases have no id registry; keep the merge without it.
                }

                $db->mergeIntoOrganized($mergedEntry, $baseShorthand, $baseId, $collection, $extra);
                $counters['total_merged']++;
                $merged = true;
            }

            if ($merged) {
                $counters['merged']++;
            }

            // Nothing matched: keep the entry itself (see persistPassthrough)
            // so the run cannot drop a base entry and orphan its registry id.
            if ($passthrough && !$merged && $baseId !== '') {
                if (self::persistPassthrough($db, $source, $entryArray, $collection)) {
                    $counters['passthrough']++;
                }
            }

            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Metadata ML merge complete. Total Merged: ' . $counters['total_merged']);
        if ($passthrough) {
            $console->io()->text(sprintf(
                'Documents: %d (%d merged, %d single-source kept)',
                $counters['merged'] + $counters['passthrough'],
                $counters['merged'],
                $counters['passthrough'],
            ));
        }
        $console->io()->text(\sprintf('Model: %s (threshold %.2f, metadata floor %.1f)', $model->version(), $mlThreshold, $metadataFloor));

        $telemetry->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $counters['total_merged'],
            'passthrough' => $counters['passthrough'],
            'documents' => $counters['merged'] + $counters['passthrough'],
            'ml_threshold' => $mlThreshold,
            'metadata_floor' => $metadataFloor,
            'ml_model_version' => $model->version(),
            'candidates_examined' => $counters['candidates_examined'],
            'rejected_anchor' => $counters['rejected_anchor'],
            'rejected_title' => $counters['rejected_title'],
            'title_floor' => $titleFloor,
            'merged_sources' => $counters['merged_sources'],
            'scores' => $scores !== [] ? [
                'avg' => round(array_sum($scores) / count($scores), 1),
                'min' => round(min($scores), 1),
                'max' => round(max($scores), 1),
            ] : null,
            'ml_scores' => $mlScores !== [] ? [
                'avg' => round(array_sum($mlScores) / count($mlScores), 4),
                'min' => round(min($mlScores), 4),
                'max' => round(max($mlScores), 4),
            ] : null,
            'completed_at' => gmdate('c'),
        ]);
        $checkpoint->complete();
        $telemetry->append('combine-events', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $counters['total_merged'],
            'ml_threshold' => $mlThreshold,
            'candidates_examined' => $counters['candidates_examined'],
            'rejected_anchor' => $counters['rejected_anchor'],
            'rejected_title' => $counters['rejected_title'],
        ]);

        return CombineStats::forRun(
            self::NAME,
            $tableName,
            (int) $counters['examined'],
            (int) $counters['merged'],
            (int) $counters['passthrough'],
            (int) $counters['unresolved_base_id'],
        );
    }
}
