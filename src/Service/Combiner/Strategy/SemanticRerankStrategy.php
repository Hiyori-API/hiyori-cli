<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\Ml\EmbeddingService;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use Hiyori\Service\Telemetry;
use MongoDB\Collection;

/**
 * Level-0 local semantic re-rank.
 *
 * Metadata similarity narrows the candidate pool; a local embedding model
 * (sentence-transformers sidecar when configured, deterministic n-gram hasher
 * otherwise) then re-scores the title/synonym similarity and the two signals
 * are blended. No network calls and no API keys are required.
 */
final class SemanticRerankStrategy extends CombinerStrategy
{
    use MergesEntries;

    public const NAME = 'semantic_rerank';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source,
    ): void {
        $weights = MetadataFuzzyStrategy::normalizeWeights($strategy->getConfig('weights') ?? MetadataFuzzyStrategy::DEFAULT_WEIGHTS);
        $threshold = MetadataFuzzyStrategy::normalizeThreshold($strategy->getConfig('threshold') ?? 70.0);
        $prefilter = (float) ($strategy->getConfig('prefilter_threshold') ?? max(0.0, $threshold - 15.0));
        $metadataWeight = max(0.0, (float) ($strategy->getConfig('metadata_weight') ?? 0.7));
        $semanticWeight = max(0.0, (float) ($strategy->getConfig('semantic_weight') ?? 0.3));
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');
        $anchorRejection = (bool) ($strategy->getConfig('anchor_rejection') ?? true);
        $anchorYearTolerance = (int) ($strategy->getConfig('anchor_year_tolerance') ?? 1);
        $anchorEpisodes = (bool) ($strategy->getConfig('anchor_episodes') ?? false);

        $embeddings = new EmbeddingService(
            (int) ($strategy->getConfig('embedding_dimensions') ?? 256),
            is_string($strategy->getConfig('embedding_command')) ? $strategy->getConfig('embedding_command') : null,
        );

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

        $totalMerged = 0;
        $scores = [];
        $candidatesExamined = 0;
        $rejectedAnchor = 0;
        $telemetry = new Telemetry();

        foreach ($cursor as $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseEmbedding = null;

            foreach ($availableSources as $currentSource) {
                if ($currentSource->getTable() === $tableName) {
                    continue;
                }

                foreach (MetadataFuzzyStrategy::candidates($db, $currentSource->getTable(), $entryArray) as $candidate) {
                    $candidatesExamined++;
                    if ($anchorRejection && MetadataFuzzyStrategy::isAnchorRejected($entryArray, $candidate, $anchorYearTolerance, $anchorEpisodes)) {
                        $rejectedAnchor++;

                        continue;
                    }

                    $metadataScore = MetadataFuzzyStrategy::calculateScore($entryArray, $candidate, $weights);
                    if ($metadataScore < $prefilter) {
                        continue;
                    }

                    $baseEmbedding ??= $embeddings->embedEntry($entryArray);
                    $semanticScore = EmbeddingService::cosine($baseEmbedding, $embeddings->embedEntry($candidate)) * 100.0;
                    $blended = self::blend($metadataScore, $semanticScore, $metadataWeight, $semanticWeight);

                    if ($blended < $threshold) {
                        continue;
                    }

                    $scores[] = $blended;
                    if (self::persistMerge($db, $source, $entryArray, $candidate, $collection, $blended)) {
                        $totalMerged++;
                    }
                    break;
                }
            }
            $progressBar->advance();
        }
        $progressBar->finish();
        $embeddings->flush();

        $console->io()->info('Semantic re-rank complete. Merged: ' . $totalMerged . ($embeddings->isExternal() ? ' (sidecar embeddings)' : ' (local n-gram fallback)'));
        $telemetry->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'threshold' => $threshold,
            'prefilter_threshold' => $prefilter,
            'embedding_backend' => $embeddings->isExternal() ? 'sidecar' : 'local-ngram',
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
            'anchor_rejection' => $anchorRejection,
            'scores' => $scores !== [] ? [
                'avg' => round(array_sum($scores) / count($scores), 1),
                'min' => round(min($scores), 1),
                'max' => round(max($scores), 1),
            ] : null,
            'completed_at' => date('c'),
        ]);
        $telemetry->append('combine-events', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'threshold' => $threshold,
            'candidates_examined' => $candidatesExamined,
            'rejected_anchor' => $rejectedAnchor,
        ]);
    }

    public static function blend(float $metadataScore, float $semanticScore, float $metadataWeight, float $semanticWeight): float
    {
        $total = $metadataWeight + $semanticWeight;
        if ($total <= 0.0) {
            return $metadataScore;
        }

        return ($metadataScore * $metadataWeight + $semanticScore * $semanticWeight) / $total;
    }
}
