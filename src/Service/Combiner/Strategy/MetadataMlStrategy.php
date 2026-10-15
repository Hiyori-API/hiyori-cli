<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
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
    public const NAME = 'metadata_ml';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source
    ): void {
        $weights = MetadataFuzzyStrategy::normalizeWeights($strategy->getConfig('weights') ?? MetadataFuzzyStrategy::DEFAULT_WEIGHTS);
        $metadataFloor = (float) ($strategy->getConfig('metadata_floor') ?? 50.0);
        $mlThreshold = (float) ($strategy->getConfig('ml_threshold') ?? 0.5);
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $anchorRejection = (bool) ($strategy->getConfig('anchor_rejection') ?? true);
        $anchorYearTolerance = (int) ($strategy->getConfig('anchor_year_tolerance') ?? 1);
        $anchorEpisodes = (bool) ($strategy->getConfig('anchor_episodes') ?? false);
        $titleFloor = (float) ($strategy->getConfig('title_floor') ?? MetadataFuzzyStrategy::DEFAULT_TITLE_FLOOR);

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
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();
        $availableSources = $source->getSources();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat((string) ($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%'));
        $progressBar->start();

        $scores = [];
        $mlScores = [];
        $totalMerged = 0;
        $mergedSources = [];
        $candidatesExamined = 0;
        $rejectedAnchor = 0;
        $rejectedTitle = 0;
        $telemetry = new Telemetry();

        foreach ($cursor as $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseShorthand = $source->get()->getShorthand();
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);

            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                $candidates = MetadataFuzzyStrategy::candidates($db, $currentSource->getTable(), $entryArray);
                $candidatesExamined += count($candidates);

                $bestMatch = null;
                $bestProbability = 0.0;
                $bestMetadata = 0.0;

                foreach ($candidates as $candidate) {
                    if ($anchorRejection && MetadataFuzzyStrategy::isAnchorRejected($entryArray, $candidate, $anchorYearTolerance, $anchorEpisodes)) {
                        $rejectedAnchor++;

                        continue;
                    }

                    if (MetadataFuzzyStrategy::isTitleRejected($entryArray, $candidate, $titleFloor)) {
                        $rejectedTitle++;

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
                $mergedSources[$currentSource->getTable()] = ($mergedSources[$currentSource->getTable()] ?? 0) + 1;

                if ($baseId === '') {
                    continue;
                }

                $entryArray['reference_ids'][$baseShorthand] = $baseId;
                $mergedEntry = Helper::mergeEntries($tableName, $entryArray, $bestMatch);
                $kind = $collection === 'organized_manga' ? 'manga' : 'anime';
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
                $totalMerged++;
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        $console->io()->info('Metadata ML merge complete. Total Merged: ' . $totalMerged);
        $console->io()->text(\sprintf('Model: %s (threshold %.2f, metadata floor %.1f)', $model->version(), $mlThreshold, $metadataFloor));

        $telemetry->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'ml_threshold' => $mlThreshold,
            'metadata_floor' => $metadataFloor,
            'ml_model_version' => $model->version(),
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
            'rejected_title' => $rejectedTitle,
            'title_floor' => $titleFloor,
            'merged_sources' => $mergedSources,
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
        $telemetry->append('combine-events', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'ml_threshold' => $mlThreshold,
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
            'rejected_title' => $rejectedTitle,
        ]);
    }
}
