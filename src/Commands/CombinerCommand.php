<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Combiner\CombineStats;
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

    /**
     * Default coverage floor, as a percent of base rows that must end up as a
     * document.
     *
     * Measured coverage of the seven strategies on the 15,015-row `anilist`
     * base ranges from 45.3 % (`relational_mapping`, exact-id only) to 76.6 %
     * (`relational_fuzzy`) — docs/combiner-strategy-benchmark.md §1. A floor
     * below the lowest known-good run cannot fail a healthy one, so 40 is the
     * conservative default: it fires only when a run loses more than three base
     * rows in five, which is the signature of a wrong base, an unresolvable
     * reference-id population or a wrong target rather than of a scoring
     * disagreement. `0` disables the percentage comparison (an empty base still
     * fails).
     */
    public const DEFAULT_MIN_COVERAGE = 40;

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
            ->addOption('min-coverage', null, InputOption::VALUE_REQUIRED, \sprintf('Fail when fewer than this percent of base rows end up as a document (integer 0-100, default %d). 0 disables the percentage comparison; a run that examines no base rows fails either way', self::DEFAULT_MIN_COVERAGE))
            ->addOption('passthrough', null, InputOption::VALUE_NEGATABLE, 'Keep base entries that matched nothing as their own single-source records. Required for an id-stable rebuild of `organized`; off by default so benchmark runs measure merging alone')
            ->addOption('rejection-buffer', null, InputOption::VALUE_NEGATABLE, 'Write borderline title-floor rejections to the rejection buffer (pair_rejections) for later review')
            ->addOption('rejection-buffer-floor', null, InputOption::VALUE_OPTIONAL, 'Metadata score that makes a title-floor rejection borderline (default: the combine threshold)')
            ->addOption('export', null, InputOption::VALUE_OPTIONAL, 'After a successful combine, run the exporter with this profile (per-entry-json, bulk-jsonl, tables-csv) or none. Default: config combiner.auto_export')
            ->addOption('skip-migrations', null, InputOption::VALUE_NONE, 'Bypass the pending-migration gate (not recommended)');
    }

    /**
     * Lock resource name.
     *
     * Overridable so a test can run while a real combine holds the production
     * lock — the suite used to fail with "already running in another process"
     * for the whole duration of a rebuild. The default is unchanged.
     */
    private static function lockName(): string
    {
        return (string) ($_ENV['COMBINE_LOCK_NAME'] ?? 'combine');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock(self::lockName())) {
            $output->writeln('The command is already running in another process.');

            return Command::SUCCESS;
        }

        $base = $input->getArgument('base');
        $strategy = $input->getOption('strategy') ?? MetadataFuzzyStrategy::NAME;

        $minCoverage = self::DEFAULT_MIN_COVERAGE;
        if ($input->getOption('min-coverage') !== null) {
            $requested = filter_var($input->getOption('min-coverage'), FILTER_VALIDATE_INT);
            if ($requested === false || $requested < 0 || $requested > 100) {
                $output->writeln('<error>--min-coverage must be an integer between 0 and 100 (0 disables the percentage check).</error>');
                $this->release();

                return Command::FAILURE;
            }
            $minCoverage = $requested;
        }

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

        if ($input->getOption('rejection-buffer') !== null) {
            $config['rejection_buffer'] = (bool) $input->getOption('rejection-buffer');
        }
        if ($input->getOption('rejection-buffer-floor') !== null) {
            $config['rejection_buffer_floor'] = (float) $input->getOption('rejection-buffer-floor');
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

        // Without this there is no way to tell a 40 %-loss run from a healthy
        // one, which is the bug this closes.
        $reported = $this->hiyori->combine($base, $strategy, $config);

        if (!$reported instanceof CombineStats) {
            $output->writeln('<error>The combiner returned no coverage statistics, so this run cannot be verified as successful.</error>');
            $this->release();

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'Coverage: <info>%s</info> of %s base rows covered (%s%%) — merged rows: %s, single-source rows: %s, unmatched rows: %s, skipped rows (no base reference id): %s',
            number_format($reported->written()),
            number_format($reported->examined),
            $reported->coveragePercent(),
            number_format($reported->merged),
            number_format($reported->passthrough),
            number_format($reported->unmatched),
            number_format($reported->unresolvedBaseId),
        ));

        if ($reported->failsCoverageGate($minCoverage)) {
            if ($reported->examined === 0) {
                $output->writeln(sprintf(
                    '<error>Coverage gate FAILED: %s examined 0 base rows, so this run proves nothing.</error>',
                    $reported->base,
                ));
            } else {
                $output->writeln(sprintf(
                    '<error>Coverage gate FAILED: %s%% is below the %d%% floor (%s of %s base rows produced no document). --passthrough keeps unmatched rows but cannot recover rows with no base reference id (see the skipped count above). Lower the floor with --min-coverage=0 to disable the check.</error>',
                    $reported->coveragePercent(),
                    $minCoverage,
                    number_format($reported->examined - $reported->written()),
                    number_format($reported->examined),
                ));
            }
            $this->release();

            return Command::FAILURE;
        }

        if ($minCoverage === 0) {
            $output->writeln('<comment>Coverage gate disabled (--min-coverage=0).</comment>');
        }

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
