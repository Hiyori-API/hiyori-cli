<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\ReferenceRepair;
use MongoDB\BSON\UTCDateTime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'repair:references',
    description: 'Recompute manga reference_ids from each entry\'s own source URL',
    aliases: ['repair-refs'],
)]
final class RepairReferencesCommand extends Command
{
    private const COLLECTIONS = ['anilist_manga', 'kitsu_manga', 'myanimelist_manga'];

    private const BACKUP = '_repair_reference_ids_backup';

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('collection', 'c', InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Collection(s) to repair (default: the three manga sources)')
            ->addOption('dry-run', 'd', InputOption::VALUE_NONE, 'Report what would change without writing')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Stop after N documents per collection (0 = all)', '0')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output the summary as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = (int) $input->getOption('limit');
        $asJson = (bool) $input->getOption('json');

        /** @var list<string> $collections */
        $collections = array_values((array) $input->getOption('collection'));
        if ($collections === []) {
            $collections = self::COLLECTIONS;
        }

        $database = $this->hiyori->getDatabase()->getClient()->selectDatabase('hiyori');
        $backup = $database->selectCollection(self::BACKUP);

        $summary = [];
        foreach ($collections as $collection) {
            $coll = $database->selectCollection($collection);
            $counts = [
                'collection' => $collection,
                'scanned' => 0,
                'unchanged' => 0,
                'updated' => 0,
                'unresolved' => 0,
                'had_anime_key' => 0,
            ];
            $examples = [];

            foreach ($coll->find([], ['batchSize' => 500]) as $doc) {
                if ($limit > 0 && $counts['scanned'] >= $limit) {
                    break;
                }
                ++$counts['scanned'];

                // MongoDB returns root documents as MongoDB\Model\BSONDocument,
                // which extends ArrayObject; (array) cast also works but this is explicit.
                $arr = $doc instanceof \ArrayObject ? $doc->getArrayCopy() : (array) $doc;
                $references = $arr['references'] ?? [];
                if (!is_iterable($references)) {
                    ++$counts['unresolved'];

                    continue;
                }

                $new = ReferenceRepair::identityFromReferences($references);
                if ($new === []) {
                    ++$counts['unresolved'];

                    continue;
                }

                $old = $arr['reference_ids'] ?? [];
                if (!is_array($old)) {
                    $decoded = json_decode((string) json_encode($old), true);
                    $old = is_array($decoded) ? $decoded : [];
                }
                if (ReferenceRepair::hasAnimeKey($old)) {
                    ++$counts['had_anime_key'];
                }

                ksort($new);
                ksort($old);
                if ($new === $old) {
                    ++$counts['unchanged'];

                    continue;
                }

                if (!$dryRun) {
                    // The previous ids are kept so a repair can be reversed
                    // without guessing, and so the contamination is auditable.
                    $backup->insertOne([
                        'collection' => $collection,
                        'doc_id' => $arr['_id'] ?? null,
                        'old' => $old,
                        'new' => $new,
                        'at' => new UTCDateTime(),
                    ]);
                    $coll->updateOne(['_id' => $arr['_id']], ['$set' => ['reference_ids' => $new]]);
                }

                ++$counts['updated'];
                if (count($examples) < 3) {
                    $examples[] = [
                        'title' => (string) ($arr['title'] ?? ''),
                        'old' => $old,
                        'new' => $new,
                    ];
                }
            }

            $counts['examples'] = $examples;
            $summary[] = $counts;
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'dry_run' => $dryRun,
                'collections' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note('Dry run: nothing was written. Re-run without --dry-run to apply.');
        }

        foreach ($summary as $row) {
            $io->section($row['collection']);
            $io->text(sprintf(
                'scanned %d · would update %d · unchanged %d · unresolved %d · carried an anime key %d',
                $row['scanned'],
                $row['updated'],
                $row['unchanged'],
                $row['unresolved'],
                $row['had_anime_key'],
            ));
            foreach ($row['examples'] as $example) {
                $io->text(sprintf(
                    '  %s: %s -> %s',
                    $example['title'],
                    json_encode($example['old']),
                    json_encode($example['new']),
                ));
            }
        }

        if (!$dryRun) {
            $io->success(sprintf('Previous ids saved in %s for rollback.', self::BACKUP));
        }

        return Command::SUCCESS;
    }
}
