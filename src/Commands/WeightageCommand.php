<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use MongoDB\Client;
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
    hidden: false
)]
final class WeightageCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    )
    {
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

        $coll1 = $client->hiyori->{$source1};
        $coll2 = $client->hiyori->{$source2};

        $total1 = $coll1->countDocuments();
        $total2 = $coll2->countDocuments();

        if ($total1 === 0 || $total2 === 0) {
            $io->error("One or both sources have no data. Run ingestion first.");

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

        // Calculate match rates and suggested weights
        $results = [];
        foreach ($fields as $field) {
            if (!isset($stats[$field]) || $stats[$field]['total'] === 0) {
                $results[$field] = [
                    'match_rate' => 0.0,
                    'weight' => 0.0,
                    'note' => 'No ground truth matches found',
                ];
                continue;
            }

            $rate = $stats[$field]['matches'] / $stats[$field]['total'];
            $weight = $rate > 0 ? round($rate * 100, 2) : 0.0;

            $results[$field] = [
                'match_rate' => round($rate * 100, 2),
                'weight' => $weight,
                'sample_size' => $stats[$field]['total'],
                'matches' => $stats[$field]['matches'],
            ];
        }

        // Normalize weights to sum to 100
        $totalWeight = array_sum(array_column($results, 'weight'));
        if ($totalWeight > 0) {
            foreach ($results as $field => $data) {
                $results[$field]['weight'] = round(($data['weight'] / $totalWeight) * 100, 2);
            }
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'sources' => [$source1, $source2],
                'ground_truth_matches' => $matchCount,
                'sample_sizes' => [$total1, $total2],
                'field_weights' => $results,
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
                $data['sample_size'] ?? 0,
                $data['matches'] ?? 0,
            ]);
        }
        $table->render();

        $io->note('Weights normalized to sum to 100%. Higher weight = more reliable for matching.');

        return Command::SUCCESS;
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
            return $union > 0 && ($intersection / $union) > 0.5;
        }

        return strtolower(trim((string)$v1)) === strtolower(trim((string)$v2));
    }
}
