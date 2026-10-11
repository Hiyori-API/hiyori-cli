<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use MongoDB\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'sweep',
    description: 'Clean up orphaned, duplicate, or incomplete entries',
    aliases: ['sweeper'],
    hidden: false
)]
final class SweeperCommand extends Command
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
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Specific source to sweep (default: all)')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Show what would be deleted without deleting')
            ->addOption('orphans', 'o', InputOption::VALUE_NONE, 'Remove entries without any reference_ids')
            ->addOption('duplicates', 'p', InputOption::VALUE_NONE, 'Remove duplicate entries within same source')
            ->addOption('incomplete', 'i', InputOption::VALUE_NONE, 'Remove entries missing required fields (title)')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Run all cleaning operations');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $dryRun = $input->getOption('dry-run');
        $orphans = $input->getOption('orphans');
        $duplicates = $input->getOption('duplicates');
        $incomplete = $input->getOption('incomplete');
        $all = $input->getOption('all');

        if ($all) {
            $orphans = $duplicates = $incomplete = true;
        }

        if (!$orphans && !$duplicates && !$incomplete) {
            $io->error('No operation selected. Use --orphans, --duplicates, --incomplete, or --all');

            return Command::FAILURE;
        }

        $db = $this->hiyori->getDatabase();
        $client = $db->getClient();

        $sources = $source ? [$source] : ['myanimelist', 'anilist', 'kitsu'];
        $totalRemoved = 0;

        foreach ($sources as $src) {
            $collection = $client->hiyori->{$src};

            if ($orphans) {
                $removed = $this->removeOrphans($collection, $dryRun, $io);
                $totalRemoved += $removed;
                $io->text("[$src] Orphans: $removed " . ($dryRun ? '(dry-run)' : 'removed'));
            }

            if ($duplicates) {
                $removed = $this->removeDuplicates($collection, $dryRun, $io);
                $totalRemoved += $removed;
                $io->text("[$src] Duplicates: $removed " . ($dryRun ? '(dry-run)' : 'removed'));
            }

            if ($incomplete) {
                $removed = $this->removeIncomplete($collection, $dryRun, $io);
                $totalRemoved += $removed;
                $io->text("[$src] Incomplete: $removed " . ($dryRun ? '(dry-run)' : 'removed'));
            }
        }

        $io->success("Sweep complete. Total: $totalRemoved " . ($dryRun ? 'would be removed' : 'removed'));

        return Command::SUCCESS;
    }

    private function removeOrphans($collection, bool $dryRun, SymfonyStyle $io): int
    {
        $filter = [
            '$or' => [
                ['reference_ids' => ['$exists' => false]],
                ['reference_ids' => []],
                ['reference_ids' => null],
            ],
        ];

        $count = $collection->countDocuments($filter);

        if ($count === 0) {
            return 0;
        }

        if (!$dryRun) {
            $collection->deleteMany($filter);
        }

        return $count;
    }

    private function removeDuplicates($collection, bool $dryRun, SymfonyStyle $io): int
    {
        $pipeline = [
            [
                '$group' => [
                    '_id' => '$reference_ids',
                    'count' => ['$sum' => 1],
                    'ids' => ['$push' => '$_id'],
                ],
            ],
            ['$match' => ['count' => ['$gt' => 1]]],
        ];

        $duplicates = 0;
        foreach ($collection->aggregate($pipeline) as $group) {
            $ids = Helper::convertMongoResultToArray($group['ids']);
            if (!is_array($ids)) continue;
            
            $toDelete = array_slice($ids, 1);
            if (!empty($toDelete)) {
                $duplicates += count($toDelete);
                if (!$dryRun) {
                    $collection->deleteMany(['_id' => ['$in' => $toDelete]]);
                }
            }
        }

        return $duplicates;
    }

    private function removeIncomplete($collection, bool $dryRun, SymfonyStyle $io): int
    {
        $filter = [
            '$or' => [
                ['title' => ['$exists' => false]],
                ['title' => ''],
                ['title' => null],
            ],
        ];

        $count = $collection->countDocuments($filter);

        if ($count === 0) {
            return 0;
        }

        if (!$dryRun) {
            $collection->deleteMany($filter);
        }

        return $count;
    }
}
