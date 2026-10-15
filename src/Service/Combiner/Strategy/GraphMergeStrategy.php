<?php

declare(strict_types=1);

namespace Hiyori\Service\Combiner\Strategy;

use Hiyori\Helper;
use Hiyori\Service\Combiner\Strategy\Concerns\MergesEntries;
use Hiyori\Service\ConsoleFactory;
use Hiyori\Service\Database;
use Hiyori\Service\SourceConfigurationFactory;
use Hiyori\Service\StrategyConfigurationFactory;
use Hiyori\Service\Telemetry;
use MongoDB\Collection;

/**
 * Graph-based connected-components combiner.
 *
 * Candidate edges are the same low-confidence matches `metadata_fuzzy` would
 * consider, but instead of merging each pair immediately we expand a cluster
 * transitively (base entry -> source A -> source B) and merge the whole
 * connected component once. This catches the case where two sources never
 * match the base entry directly but do match each other.
 */
final class GraphMergeStrategy extends CombinerStrategy
{
    use MergesEntries;

    public const NAME = 'graph_merge';

    public static function process(
        Database $db,
        ConsoleFactory $console,
        StrategyConfigurationFactory $strategy,
        SourceConfigurationFactory $source,
    ): void {
        $weights = MetadataFuzzyStrategy::normalizeWeights($strategy->getConfig('weights') ?? MetadataFuzzyStrategy::DEFAULT_WEIGHTS);
        $linkThreshold = (float) ($strategy->getConfig('link_threshold') ?? 60.0);
        $mergeThreshold = (float) ($strategy->getConfig('threshold') ?? 70.0);
        $maxDepth = max(1, (int) ($strategy->getConfig('max_depth') ?? 4));
        $collection = (string) ($strategy->getConfig('collection') ?? 'organized');

        $tableName = $source->get()->getTable();
        /** @var Collection $baseCollection */
        $baseCollection = $db->getCollection($tableName);
        $total = $baseCollection->countDocuments();
        $cursor = $baseCollection->find();

        $progressBar = $console->io()->createProgressBar($total);
        $progressBar->setRedrawFrequency((int) ($_ENV['PROGRESSBAR_REDRAW_FREQ'] ?? 1));
        $progressBar->setFormat((string) ($_ENV['PROGRESSBAR_FORMAT'] ?? '%current%/%max% [%bar%] %percent:3s%% | ETA:%estimated:-6s% MEM:%memory:6s%'));
        $progressBar->start();

        $baseShorthand = $source->get()->getShorthand();
        $totalMerged = 0;
        $clusterSizes = [];

        foreach ($cursor as $entry) {
            $entryArray = Helper::convertMongoResultToArray($entry);
            $baseId = Helper::resolveReferenceId($entryArray, $baseShorthand);
            if ($baseId === '') {
                $progressBar->advance();

                continue;
            }

            $members = self::expandCluster($db, $source, $tableName, $entryArray, $weights, $linkThreshold, $maxDepth);
            if ($members === [] || $members[0]['score'] < $mergeThreshold) {
                $progressBar->advance();

                continue;
            }

            $entryArray['reference_ids'][$baseShorthand] = $baseId;
            $model = null;
            foreach ($members as $member) {
                if ($model === null) {
                    $model = Helper::mergeEntries($tableName, $entryArray, $member['entry']);
                } else {
                    $model = Helper::mergeEntries($tableName, $model->toArray(), $member['entry']);
                }
            }

            if ($model !== null && self::persistAccumulated($db, $source, $model, $baseId, $collection, $members[0]['score'])) {
                $totalMerged++;
                $clusterSize = count($members) + 1;
                $clusterSizes[$clusterSize] = ($clusterSizes[$clusterSize] ?? 0) + 1;
            }
            $progressBar->advance();
        }
        $progressBar->finish();

        ksort($clusterSizes);
        $console->io()->info('Graph merge complete. Merged: ' . $totalMerged);
        (new Telemetry())->update('combine-latest', [
            'strategy' => self::NAME,
            'base' => $tableName,
            'merges' => $totalMerged,
            'link_threshold' => $linkThreshold,
            'threshold' => $mergeThreshold,
            'cluster_sizes' => $clusterSizes,
            'completed_at' => date('c'),
        ]);
    }

    /**
     * Transitively expand from the base entry into every source that is not
     * yet part of the cluster. Returns matched entries sorted by score desc.
     *
     * @param array<string, mixed> $weights
     *
     * @return list<array{entry: array<string, mixed>, score: float}>
     */
    private static function expandCluster(
        Database $db,
        SourceConfigurationFactory $source,
        string $baseTable,
        array $baseEntry,
        array $weights,
        float $linkThreshold,
        int $maxDepth,
    ): array {
        $visited = [$baseTable => true];
        $frontier = [['entry' => $baseEntry, 'depth' => 0]];
        $members = [];

        while ($frontier !== []) {
            $node = array_shift($frontier);
            if ($node['depth'] >= $maxDepth) {
                continue;
            }

            foreach ($source->getSources() as $candidateSource) {
                $table = $candidateSource->getTable();
                if (isset($visited[$table])) {
                    continue;
                }

                $best = MetadataFuzzyStrategy::findBestMatch($db, $table, $node['entry'], $weights, $linkThreshold);
                if ($best === null) {
                    continue;
                }

                $score = (float) $best['_score'];
                unset($best['_score']);
                $visited[$table] = true;
                $members[] = ['entry' => $best, 'score' => $score];
                $frontier[] = ['entry' => $best, 'depth' => $node['depth'] + 1];
            }
        }

        usort($members, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $members;
    }
}
