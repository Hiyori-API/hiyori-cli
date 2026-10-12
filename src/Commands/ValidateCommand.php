<?php

declare(strict_types=1);

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'validate',
    description: 'Validate existing data in the database against the Hiyori schema',
    aliases: ['data:validate'],
    hidden: false,
)]
final class ValidateCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_OPTIONAL, 'Only validate this collection (e.g. anilist, organized)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of documents to validate (default: all)', 0)
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getOption('source');
        $limit = (int) $input->getOption('limit');
        $asJson = $input->getOption('json');

        $db = $this->hiyori->getDatabase();
        $validator = $db->getValidator();

        $collections = $source ? [$source] : ['myanimelist', 'kitsu', 'anilist', 'myanimelist_manga', 'kitsu_manga', 'anilist_manga', 'organized'];

        $summary = [];
        $totalDocs = 0;
        $totalErrors = 0;

        foreach ($collections as $collectionName) {
            /** @var Collection $collection */
            $collection = $db->getCollection($collectionName);
            $count = $collection->countDocuments();

            $cursor = $collection->find([], ['limit' => $limit ?: 0]);
            $docsChecked = 0;
            $errorsByField = [];

            foreach ($cursor as $doc) {
                $docsChecked++;
                $arr = json_decode(json_encode($doc), true) ?: [];
                $errors = $validator->validate($arr);
                if ($errors !== []) {
                    $totalErrors++;
                    foreach ($errors as $field => $messages) {
                        $errorsByField[$field] = ($errorsByField[$field] ?? 0) + count($messages);
                    }
                }
            }

            $totalDocs += $count;
            $summary[$collectionName] = [
                'total_documents' => $count,
                'checked' => $docsChecked,
                'documents_with_errors' => array_sum($errorsByField) > 0 ? $this->countInvalid($db, $collectionName, $validator) : 0,
                'errors_by_field' => $errorsByField,
            ];
        }

        if ($asJson) {
            $output->writeln(json_encode([
                'collections' => $summary,
                'total_documents' => $totalDocs,
                'total_field_errors' => $totalErrors,
            ], JSON_PRETTY_PRINT));

            return Command::SUCCESS;
        }

        $io->section('Data Validation');
        $io->text("Total documents: $totalDocs | Field violations: $totalErrors");

        $table = $io->createTable();
        $table->setHeaders(['Collection', 'Docs', 'Checked', 'Errors by field']);
        foreach ($summary as $name => $info) {
            $fieldSummary = $info['errors_by_field'] === []
                ? 'none'
                : implode(', ', array_map(fn ($f, $c) => "$f=$c", array_keys($info['errors_by_field']), $info['errors_by_field']));
            $table->addRow([$name, $info['total_documents'], $info['checked'], $fieldSummary]);
        }
        $table->render();

        if ($totalErrors > 0) {
            $io->warning("Found $totalErrors field violations across the data.");

            return Command::SUCCESS;
        }

        $io->success('All checked documents passed validation.');

        return Command::SUCCESS;
    }

    private function countInvalid(\Hiyori\Service\Database $db, string $collectionName, \Hiyori\Service\Validator $validator): int
    {
        $collection = $db->getCollection($collectionName);
        $cursor = $collection->find([], ['limit' => 0]);
        $invalid = 0;
        foreach ($cursor as $doc) {
            $arr = json_decode(json_encode($doc), true) ?: [];
            if ($validator->validate($arr) !== []) {
                $invalid++;
            }
        }

        return $invalid;
    }
}
