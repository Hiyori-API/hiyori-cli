<?php

namespace Hiyori\Commands;

use Hiyori\Helper;
use Hiyori\Hiyori;
use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
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
    /** Raw source collections keep the legacy streaming export. */
    private const RAW_SOURCES = ['myanimelist', 'anilist', 'kitsu'];

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('source', InputArgument::OPTIONAL, 'Source to export (myanimelist, anilist, kitsu, organized). Default: organized')
            ->addOption('profile', null, InputOption::VALUE_OPTIONAL, 'Export profile: per-entry-json, bulk-jsonl, tables-csv')
            ->addOption('format', 'f', InputOption::VALUE_OPTIONAL, 'Export format: json, jsonl, csv, relational (back-compat -> tables-csv)')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'Config override k=v (dot notation), repeatable (e.g. --config include.tags=true)')
            ->addOption('output', 'o', InputOption::VALUE_OPTIONAL, 'Output file path (legacy) or dump base directory (profiles)')
            ->addOption('fields', null, InputOption::VALUE_OPTIONAL, 'Comma-separated list of fields to export')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of entries')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Pretty-print JSON output')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Estimate the dump without writing any file')
            ->addOption('preview', null, InputOption::VALUE_OPTIONAL, 'Print one rendered entry for the given kind (default: anime)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $source = $input->getArgument('source') ?? 'organized';
        $profile = $input->getOption('profile');
        $format = $input->getOption('format');

        if (in_array($source, self::RAW_SOURCES, true) && $profile === null && $format !== 'relational') {
            return $this->streamSource($input, $output, $source);
        }

        return $this->runProfiles($input, $output);
    }

    /**
     * New configurable core: profiles, dry-run and preview.
     */
    private function runProfiles(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $config = ExportConfig::load();

        $profile = $input->getOption('profile');
        if (is_string($profile) && $profile !== '') {
            $config = ExportConfig::withProfile($config, $profile);
        }
        $format = $input->getOption('format');
        if (is_string($format) && $format !== '') {
            $config = ExportConfig::withProfile($config, ExportConfig::profileForFormat($format));
        }

        $config = ExportConfig::overrides($config, $input->getOption('config') ?? []);

        if ($input->getOption('fields')) {
            $config['fields'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('fields')))));
        }
        if ($input->getOption('pretty')) {
            $config['pretty'] = true;
        }

        $outputDir = $input->getOption('output');
        $exporter = new Exporter(
            $this->hiyori->getDatabase()->getClient(),
            'hiyori',
            is_string($outputDir) && $outputDir !== '' ? $outputDir : null,
        );

        $previewRequested = $input->hasParameterOption('--preview');
        if ($previewRequested) {
            $kind = $input->getOption('preview');
            $preview = $exporter->preview($config, is_string($kind) && $kind !== '' ? $kind : 'anime');
            if ($preview['entry'] === null) {
                $io->error('No document found to preview.');

                return Command::FAILURE;
            }
            $io->writeln((string) $preview['json']);

            return Command::SUCCESS;
        }

        if ($input->getOption('dry-run')) {
            $estimate = $exporter->dryRun($config);
            $table = new \Symfony\Component\Console\Helper\Table($output);
            $table->setHeaders(['metric', 'value'])->setRows([
                ['profile', $config['profile']],
                ['files', (string) $estimate['files']],
                ['anime', (string) $estimate['byKind']['anime']],
                ['manga', (string) $estimate['byKind']['manga']],
                ['bytes (est.)', (string) $estimate['bytesEstimate']],
            ]);
            $table->render();
            foreach ($estimate['sampleFilenames'] as $sample) {
                $io->writeln('  ' . $sample);
            }

            return Command::SUCCESS;
        }

        $result = $exporter->write($config);

        $io->success(sprintf(
            'Wrote %d files (%d rows) to %s [profile: %s]',
            $result['files'],
            $result['rows'],
            $result['dir'],
            $config['profile'],
        ));
        $io->writeln(sprintf(
            'changelog: +%d ~%d -%d',
            $result['changelog']['added'],
            $result['changelog']['updated'],
            $result['changelog']['removed'],
        ));

        return Command::SUCCESS;
    }

    /**
     * Legacy streaming export for the raw source collections.
     */
    private function streamSource(InputInterface $input, OutputInterface $output, string $source): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = $input->getOption('format') ?? 'json';
        $outputFile = $input->getOption('output');
        $fields = $input->getOption('fields') ? explode(',', (string) $input->getOption('fields')) : null;
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : 0;
        $pretty = $input->getOption('pretty');

        $db = $this->hiyori->getDatabase();

        $validSources = ['myanimelist', 'anilist', 'kitsu', 'organized'];
        if (!in_array($source, $validSources, true)) {
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
