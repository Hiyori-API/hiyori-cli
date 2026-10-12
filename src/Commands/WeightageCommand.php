<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'weightage',
    description: 'Calculate ideal weightage between 2 sources for metadata fuzzy matching',
    aliases: ['weight'],
    hidden: false,
)]
final class WeightageCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source1', InputArgument::REQUIRED, 'First source (e.g., myanimelist)')
            ->addArgument('source2', InputArgument::REQUIRED, 'Second source (e.g., anilist)')
            ->addOption('sample', 's', InputOption::VALUE_OPTIONAL, 'Sample size for analysis (default: 1000)', 1000)
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source1 = $input->getArgument('source1');
        $source2 = $input->getArgument('source2');
        $sampleSize = (int) $input->getOption('sample');
        $asJson = $input->getOption('json');

        $db = $this->hiyori->getDatabase();
        $client = $db->getClient();

        /** @var Collection $coll1 */
        $coll1 = $client->selectDatabase('hiyori')->selectCollection($source1);
        /** @var Collection $coll2 */
        $coll2 = $client->selectDatabase('hiyori')->selectCollection($source2);

        $total1 = $coll1->countDocuments();
        $total2 = $coll2->countDocuments();

        if ($total1 === 0 || $total2 === 0) {
            $io->error('One or both sources have no data. Run ingestion first.');

            return Command::FAILURE;
        }

        $io->section("Weightage Analysis: $source1 vs $source2");
        $io->text("Source 1: $total1 entries | Source 2: $total2 entries | Sample: $sampleSize");

        // Sample entries from both sources - convert to arrays
        $entries1 = [];
        foreach ($coll1->find([], [
            'projection' => ['title' => 1, 'type' => 1, 'episodes' => 1, 'status' => 1, 'season' => 1, 'year' => 1, 'tags' => 1, 'reference_ids' => 1, '_id' => 0],
            'limit' => $sampleSize,
        ]) as $doc) {
            $entries1[] = Helper::convertMongoResultToArray($doc);
        }

        $entries2 = [];
        foreach ($coll2->find([], [
            'projection' => ['title' => 1, 'type' => 1, 'episodes' => 1, 'status' => 1, 'season' => 1, 'year' => 1, 'tags' => 1, 'reference_ids' => 1, '_id' => 0],
            'limit' => $sampleSize,
        ]) as $doc) {
            $entries2[] = Helper::convertMongoResultToArray($doc);
        }

        // Build lookup by reference_ids for ground truth matches
        $refMap2 = [];
        foreach ($entries2 as $e2) {
            if (!empty($e2['reference_ids']) && is_array($e2['reference_ids'])) {
                foreach ($e2['reference_ids'] as $k => $v) {
                    $refMap2[$k][$v] = $e2;
                }
            }
        }

        $fields = ['title', 'type', 'episodes', 'status', 'season', 'year', 'tags'];
        $stats = [];
        $matchCount = 0;
        $this->samplePairs = [];

        foreach ($entries1 as $e1) {
            $matched = null;

            // Try to find match via reference_ids
            if (!empty($e1['reference_ids']) && is_array($e1['reference_ids'])) {
                foreach ($e1['reference_ids'] as $k => $v) {
                    if (isset($refMap2[$k][$v])) {
                        $matched = $refMap2[$k][$v];
                        break;
                    }
                }
            }

            if ($matched) {
                $matchCount++;
                $this->samplePairs[] = [$e1, $matched];
                // Negative samples: pick random source2 entries that are NOT the true
                // match, so weights can be optimized on the signal difference.
                $matchedKey = $e1['reference_ids'];
                ksort($matchedKey);
                $negatives = [];
                $entry2Keys = array_keys($entries2);
                $randomIdx = (array) array_rand($entry2Keys, min(2, count($entry2Keys)));
                foreach ($randomIdx as $idx) {
                    $candidate = $entries2[$idx];
                    $candidateRefs = $candidate['reference_ids'] ?? [];
                    ksort($candidateRefs);
                    if (($candidate['reference_ids'] ?? null) === $matchedKey) {
                        continue;
                    }
                    $negatives[] = $candidate;
                }
                foreach ($negatives as $neg) {
                    $this->negativePairs[] = [$e1, $neg];
                }
                foreach ($fields as $field) {
                    if (!isset($stats[$field])) {
                        $stats[$field] = ['matches' => 0, 'total' => 0, 'match_rate' => 0.0];
                    }
                    $stats[$field]['total']++;
                    if ($this->fieldsMatch($e1, $matched, $field)) {
                        $stats[$field]['matches']++;
                    }
                }
            }
        }

        // Per-field match rates (informational)
        $results = [];
        foreach ($fields as $field) {
            $total = $stats[$field]['total'] ?? 0;
            $results[$field] = [
                'match_rate' => $total > 0 ? round(($stats[$field]['matches'] / $total) * 100, 2) : 0.0,
                'sample_size' => $total,
                'matches' => $total > 0 ? $stats[$field]['matches'] : 0,
            ];
        }

        // dbsentinel-style weight optimization: grid-search weight vectors that
        // maximize the average metadata score over ground-truth pairs, using the
        // SAME scoring functions as MetadataFuzzyStrategy.
        $optimized = $this->optimizeWeights($this->samplePairs, $this->negativePairs);
        $optimizedWeights = $optimized['weights'];
        $avgScore = $optimized['avg_score'];

        foreach ($fields as $field) {
            $results[$field]['weight'] = round($optimizedWeights[$field] ?? 0.0, 2);
            $results[$field]['optimized_weight'] = $results[$field]['weight'];
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'sources' => [$source1, $source2],
                'ground_truth_matches' => $matchCount,
                'sample_sizes' => [$total1, $total2],
                'field_weights' => $results,
                'optimized_avg_score' => round($avgScore, 2),
                'top_field' => $optimized['top_field'],
                'field_gaps' => $optimized['gaps'],
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->text("Ground-truth matches found: $matchCount / $sampleSize");

        $table = $io->createTable();
        $table->setHeaders(['Field', 'Match Rate (%)', 'Suggested Weight (%)', 'Sample', 'Matches']);
        foreach ($results as $field => $data) {
            $table->addRow([
                $field,
                $data['match_rate'],
                $data['weight'],
                $data['sample_size'],
                $data['matches'],
            ]);
        }
        $table->render();

        $io->note('Discrimination-based weights (normalized to 100). Higher = field separates true matches from non-matches; top field: ' . $optimized['top_field']);

        return Command::SUCCESS;
    }

    private array $samplePairs = [];

    private array $negativePairs = [];

    /**
     * Discrimination-based weight derivation (dbsentinel-inspired): for each
     * field, measure how well it separates true pairs from random non-pairs
     * (score on true pairs minus score on negatives), and normalize those gaps
     * into weights. Fields that also match false pairs (e.g. status) get ~0.
     *
     * @param array<int, array{array, array}> $pairs
     * @param array<int, array{array, array}> $negatives
     * @return array{weights: array<string,float>, avg_score: float, top_field: string, gaps: array<string,float>}
     */
    public function optimizeWeights(array $pairs, array $negatives = [], array $fields = ['title', 'type', 'episodes', 'status', 'season', 'year', 'tags']): array
    {
        $pairs = array_slice($pairs, 0, 300);
        $negatives = array_slice($negatives, 0, 600);

        // Per-field discrimination gap: score a true pair minus a false pair with
        // all weight on one field. Fields that match TRUE pairs but also match
        // FALSE pairs (e.g. status) get a low gap and therefore low weight.
        $gaps = [];
        foreach ($fields as $field) {
            $w = array_fill_keys($fields, 0.0);
            $w[$field] = 1.0;

            $trueTotal = 0.0;
            $nTrue = count($pairs);
            foreach ($pairs as [$e1, $e2]) {
                $trueTotal += MetadataFuzzyStrategy::calculateScore($e1, $e2, $w);
            }
            $falseTotal = 0.0;
            $nFalse = count($negatives);
            foreach ($negatives as [$e1, $neg]) {
                $falseTotal += MetadataFuzzyStrategy::calculateScore($e1, $neg, $w);
            }

            $gaps[$field] = max(0.0, ($nTrue > 0 ? $trueTotal / $nTrue : 0.0) - ($nFalse > 0 ? $falseTotal / $nFalse : 0.0));
        }

        $gapSum = array_sum($gaps);
        $weights = [];
        foreach ($fields as $field) {
            $weights[$field] = $gapSum > 0 ? ($gaps[$field] / $gapSum) * 100 : 0.0;
        }

        arsort($gaps);
        $top = array_key_first($gaps);

        return [
            'weights' => $weights,
            'avg_score' => $gapSum > 0 ? $gaps[$top] : 0.0,
            'top_field' => $top,
            'gaps' => $gaps,
        ];
    }

    private function fieldsMatch(array $e1, array $e2, string $field): bool
    {
        $v1 = $e1[$field] ?? null;
        $v2 = $e2[$field] ?? null;

        if ($v1 === null && $v2 === null) {
            return true;
        }
        if ($v1 === null || $v2 === null) {
            return false;
        }

        if ($field === 'tags') {
            $set1 = array_flip((array)$v1);
            $set2 = array_flip((array)$v2);
            $intersection = count(array_intersect_key($set1, $set2));
            $union = count(array_merge($set1, $set2));

            /** @var int $union */
            return $union > 0 && ($intersection / $union) > 0.5;
        }

        return strtolower(trim((string)$v1)) === strtolower(trim((string)$v2));
    }
}
