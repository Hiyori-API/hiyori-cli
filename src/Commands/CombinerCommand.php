<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\ExportConfig;
use Hiyori\Service\Exporter;
use Hiyori\Service\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'combine',
    description: 'Combine metadata from all sources into one',
    aliases: ['c', 'combiner'],
    hidden: false,
)]
final class CombinerCommand extends Command
{
    use LockableTrait;

    public function __construct(
        private Hiyori $hiyori
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('base', InputArgument::REQUIRED, 'Source dataset to use as a base')
            ->addOption('strategy', 's', InputOption::VALUE_OPTIONAL, 'metadata_fuzzy (default), metadata_ml, graph_merge, fellegi_sunter, semantic_rerank, relational_fuzzy, relational_mapping')
            ->addOption('threshold', 't', InputOption::VALUE_OPTIONAL, 'metadata_fuzzy score threshold (0-100, default 70)')
            ->addOption('weights', 'w', InputOption::VALUE_OPTIONAL, 'metadata_fuzzy field weights as JSON, e.g. {"title":25,"tags":20}')
            ->addOption('config', null, InputOption::VALUE_OPTIONAL, 'Extra strategy config as JSON (e.g. {"ml_threshold":0.6,"metadata_floor":55})')
            ->addOption('into', null, InputOption::VALUE_OPTIONAL, 'Collection to merge into (e.g. organized_manga). Defaults to organized.', 'organized')
            ->addOption('passthrough', null, InputOption::VALUE_NEGATABLE, 'Keep base entries that matched nothing as their own single-source records. Required for an id-stable rebuild of `organized`; off by default so benchmark runs measure merging alone')
            ->addOption('export', null, InputOption::VALUE_OPTIONAL, 'After a successful combine, run the exporter with this profile (per-entry-json, bulk-jsonl, tables-csv) or none. Default: config combiner.auto_export')
            ->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Bypass the pending-migration gate (not recommended)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $base = $input->getArgument('base');
        $strategy = $input->getOption('strategy') ?? MetadataFuzzyStrategy::NAME;

        // Pre-flight: migrations must be applied before the combiner touches data.
        $pending = $this->hiyori->getMigrator()->pending();
        if ($pending !== [] && !$input->getOption('skip-migrations')) {
            $output->writeln('<error>The database has pending migrations. Run `php hiyori migrate` first:</error>');
            foreach ($pending as $v => $name) {
                $output->writeln(sprintf('  v%s: %s', $v, $name));
            }
            $output->writeln('<comment>Or force with: combine ... --skip-migrations</comment>');
            $this->release();

            return Command::FAILURE;
        }

        $config = ['collection' => (string) $input->getOption('into')];
        if ($input->getOption('threshold') !== null) {
            $config['threshold'] = (float) $input->getOption('threshold');
        }
        if ($input->getOption('weights') !== null) {
            $decoded = json_decode((string) $input->getOption('weights'), true);
            if (!is_array($decoded)) {
                $output->writeln('<error>--weights must be valid JSON, e.g. {"title":25,"tags":20}</error>');

                return Command::FAILURE;
            }
            $config['weights'] = $decoded;
        }
        if ($input->getOption('config') !== null) {
            $decoded = json_decode((string) $input->getOption('config'), true);
            if (!is_array($decoded)) {
                $output->writeln('<error>--config must be a valid JSON object, e.g. {"ml_threshold":0.6}</error>');

                return Command::FAILURE;
            }
            $config = array_merge($config, $decoded);
        }

        if ($input->getOption('passthrough') !== null) {
            $config['passthrough'] = (bool) $input->getOption('passthrough');
        }

        // Warn before, not after: a run without passthrough drops every base
        // entry that matched nothing. On a merged collection that already holds
        // single-source records that is silent coverage loss, and the registry
        // rows behind those entries are left without a document.
        $target = (string) $input->getOption('into');
        if (!($config['passthrough'] ?? false) && in_array($target, Migrator::KINDS, true)) {
            $existing = $this->hiyori->getDatabase()->getCollection($target)->countDocuments();
            if ($existing > 0) {
                $output->writeln(sprintf(
                    '<comment>Note:</comment> %s holds %d documents and <comment>--passthrough is off</comment> — base entries that match nothing will be dropped (their hiyori_ids left without a document). Add --passthrough to keep one record per base entry.',
                    $target,
                    $existing,
                ));
            }
        }

        $this->hiyori->combine($base, $strategy, $config);

        $this->exportIfRequested($input, $output);

        $this->release();

        return Command::SUCCESS;
    }

    /**
     * Optional post-combine export. No-op unless `--export <profile>` is set,
     * or the effective config sets `combiner.auto_export`. `none` disables it.
     */
    private function exportIfRequested(InputInterface $input, OutputInterface $output): void
    {
        $profile = $input->getOption('export');
        if ($profile === null && $input->hasParameterOption('--export')) {
            // `--export` without a value means "use the default profile".
            $profile = ExportConfig::DEFAULT_PROFILE;
        }
        if ($profile === null) {
            $profile = ExportConfig::load()['combiner']['auto_export'] ?? null;
        }
        if (!is_string($profile) || $profile === '' || strtolower($profile) === 'none' || strtolower($profile) === 'no') {
            return;
        }

        $config = ExportConfig::withProfile(ExportConfig::load(), $profile);
        $exporter = new Exporter($this->hiyori->getDatabase()->getClient());
        $result = $exporter->write($config);

        $output->writeln(sprintf(
            '<info>exported</info> %d files (%d rows) to %s [profile: %s] (+%d ~%d -%d)',
            $result['files'],
            $result['rows'],
            $result['dir'],
            $config['profile'],
            $result['changelog']['added'],
            $result['changelog']['updated'],
            $result['changelog']['removed'],
        ));
    }
}
