<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use MongoDB\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'export',
    description: 'Export data from sources or combined DB',
    aliases: ['exp'],
    hidden: false,
)]
final class ExportCommand extends Command
{
    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Source to export (myanimelist, anilist, kitsu, organized). Default: organized')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Export format: json, csv, jsonl, relational (default: json)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path (default: stdout)')
            ->addOption('fields', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of fields to export')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of entries')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Pretty-print JSON output');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source') ?? 'organized';
        $format = $input->getOption('format') ?? 'json';
        $outputFile = $input->getOption('output');
        $fields = $input->getOption('fields') ? explode(',', $input->getOption('fields')) : null;
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : 0;
        $pretty = $input->getOption('pretty');

        $db = $this->hiyori->getDatabase();

        if ($format === 'relational') {
            $outDir = $outputFile ?: null;
            $publisher = new \Hiyori\Service\Publisher(new \MongoDB\Client($_ENV['MONGODB_URL']), 'hiyori', $outDir);
            $result = $publisher->dump(false, true);
            $io->success('Relational dump written to ' . $result['dir'] . ' (' . $result['rows'] . ' rows)');

            return Command::SUCCESS;
        }

        $validSources = ['myanimelist', 'anilist', 'kitsu', 'organized'];
        if (!in_array($source, $validSources)) {
            $io->error('Invalid source. Choose from: ' . implode(', ', $validSources));

            return Command::FAILURE;
        }

        /** @var Collection $collection */
        $collection = $db->getCollection($source);

        // Build projection
        $projection = ['_id' => 0];
        if ($fields) {
            foreach ($fields as $f) {
                $projection[trim($f)] = 1;
            }
        }

        $options = ['projection' => $projection];
        if ($limit > 0) {
            $options['limit'] = $limit;
        }

        $cursor = $collection->find([], $options);
        $count = 0;

        // Use OutputInterface directly for stdout, file handle for files
        $isFile = (bool) $outputFile;
        $handle = $isFile ? fopen($outputFile, 'w') : null;

        if ($format === 'json' || $format === 'jsonl') {
            if ($format === 'json' && !$isFile) {
                $output->write('[');
            } elseif ($format === 'json' && $isFile) {
                fwrite($handle, '[');
            }
            $first = true;
            foreach ($cursor as $doc) {
                $doc = Helper::convertMongoResultToArray($doc);
                if (!$first) {
                    $sep = $format === 'json' ? ',' : "\n";
                    if ($isFile) {
                        fwrite($handle, $sep);
                    } else {
                        $output->write($sep);
                    }
                }
                $json = $pretty ? json_encode($doc, JSON_PRETTY_PRINT) : json_encode($doc);
                if ($isFile) {
                    fwrite($handle, $json);
                } else {
                    $output->write($json);
                }
                $first = false;
                $count++;
            }
            if ($format === 'json') {
                if ($isFile) {
                    fwrite($handle, ']');
                } else {
                    $output->write(']');
                }
            }
        } elseif ($format === 'csv') {
            $first = true;
            foreach ($cursor as $doc) {
                $doc = Helper::convertMongoResultToArray($doc);
                if ($first) {
                    $headers = array_keys($doc);
                    if ($isFile) {
                        fputcsv($handle, $headers);
                    } else {
                        $output->write(implode(',', $headers));
                    }
                    $first = false;
                }
                $row = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $doc);
                if ($isFile) {
                    fputcsv($handle, $row);
                } else {
                    $output->write(implode(',', $row));
                }
                $count++;
            }
        }

        if ($isFile) {
            fclose($handle);
        }

        $io->success("Exported $count entries from $source to " . ($outputFile ? $outputFile : 'stdout') . " ($format)");

        return Command::SUCCESS;
    }
}
