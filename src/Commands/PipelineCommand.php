<?php

namespace Hiyori\Commands;

use Hiyori\Hiyori;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\ExportConfig;
use Hiyori\Service\Pipeline\DatabaseChecks;
use Hiyori\Service\Pipeline\PipelineChecks;
use Hiyori\Service\Pipeline\PipelineRunner;
use Hiyori\Service\Pipeline\Precondition;
use Hiyori\Service\Pipeline\PreconditionResult;
use Hiyori\Service\Pipeline\RunRecordStore;
use Hiyori\Service\Pipeline\Stage;
use Hiyori\Service\Pipeline\StageOutput;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The release chain, owned in one place.
 *
 * The order used to live only in `.github/workflows/publish.yml` and the shell
 * scripts, each implementing a different subset. This command states the order
 * once, drives the *existing* commands (so behaviour is identical to running
 * them by hand) and refuses to continue when a stage's input is not valid.
 *
 * `release` already builds and verifies its own dump, so there is no separate
 * `export` stage: running `export` first would export twice. Use
 * `--skip=release` to stop after the gates and build a dump by hand.
 */
#[AsCommand(
    name: 'pipeline:release',
    description: 'Run the release chain end to end, refusing to continue on a bad input',
    aliases: ['release:all'],
)]
final class PipelineCommand extends Command
{
    /** Stage ids, in execution order. */
    public const STAGE_IDS = ['migrate', 'combine:anime', 'combine:manga', 'hiyori-id', 'dupes', 'dedupe', 'release'];

    /** Tokens accepted by --from/--skip => canonical stage id. */
    private const STAGE_ALIASES = [
        'migrate' => 'migrate',
        'combine:anime' => 'combine:anime',
        'anime' => 'combine:anime',
        'combine:manga' => 'combine:manga',
        'manga' => 'combine:manga',
        'hiyori-id' => 'hiyori-id',
        'ids' => 'hiyori-id',
        'dupes' => 'dupes',
        'combine:dupes' => 'dupes',
        'dedupe' => 'dedupe',
        'combine:dedupe' => 'dedupe',
        'release' => 'release',
    ];

    /** Combined collections the gates read. */
    private const COMBINED = ['organized', 'organized_manga'];

    public function __construct(
        private Hiyori $hiyori,
        private ?Application $application = null,
        private ?PipelineChecks $checks = null,
        private ?string $stateDir = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the plan and evaluate the read-only preconditions; run no stage and write no state file')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start at this stage, skipping everything before it: ' . implode('|', self::STAGE_IDS))
            ->addOption('skip', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Skip a stage; repeatable')
            ->addOption('json', 'j', InputOption::VALUE_NONE, 'Print only the JSON run record on stdout')
            ->addOption('min-coverage', null, InputOption::VALUE_REQUIRED, 'Coverage floor passed through to both `combine` stages (integer 0-100; 0 disables the percentage check)')
            ->addOption('strategy', 's', InputOption::VALUE_OPTIONAL, 'Combiner strategy for both `combine` stages', MetadataFuzzyStrategy::NAME)
            ->addOption('tag', null, InputOption::VALUE_OPTIONAL, 'Passed through to `release --tag`')
            ->addOption('repo', null, InputOption::VALUE_OPTIONAL, 'Passed through to `release --repo`')
            ->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'Passed through to `release --branch`')
            ->addOption('draft', null, InputOption::VALUE_NONE, 'Passed through to `release --draft`');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $asJson = (bool) $input->getOption('json');
        $dryRun = (bool) $input->getOption('dry-run');
        $human = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $application = $this->application ?? $this->getApplication();
        if (!$application instanceof Application) {
            $human->writeln('<error>pipeline:release must run inside the console application so it can drive the stage commands.</error>');

            return Command::FAILURE;
        }

        $from = $this->resolveStageToken($input->getOption('from'), '--from', $human);
        if ($from === false) {
            return Command::FAILURE;
        }

        $skip = [];
        foreach ((array) $input->getOption('skip') as $token) {
            $id = $this->resolveStageToken($token, '--skip', $human);
            if ($id === false) {
                return Command::FAILURE;
            }
            if ($id !== null) {
                $skip[] = $id;
            }
        }
        $skip = array_values(array_unique($skip));

        if ($from !== null && in_array($from, $skip, true)) {
            $human->writeln(sprintf('<error>--from=%s and --skip=%s contradict each other.</error>', $from, $from));

            return Command::FAILURE;
        }

        $checks = $this->checks ?? $this->defaultChecks();
        $runner = new PipelineRunner($application, $human, !$asJson);
        $stages = $this->buildStages($input, $runner, $checks);
        $plan = $this->applySelection($stages, $from, $skip);

        if ($plan === []) {
            $human->writeln('<error>this selection leaves no stage to run.</error>');

            return Command::FAILURE;
        }

        $runId = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $database = $checks->database();

        if (!$asJson) {
            $human->writeln(sprintf('<info>pipeline:release</info> %s%s', $runId, $dryRun ? ' <comment>(dry run)</comment>' : ''));
            $this->printPlan($human, $plan);
        }

        $record = $runner->run($plan, $runId, $database, $dryRun);
        $record['record_path'] = null;

        $store = new RunRecordStore($this->stateDir ?? ExportConfig::rootDir() . '/storage/state');
        if (!$dryRun) {
            $record['record_path'] = $store->pathFor($runId);
            try {
                $store->write($record);
            } catch (\Throwable $e) {
                $record['record_error'] = $e->getMessage();
                $human->writeln('<error>could not write the run record: ' . $e->getMessage() . '</error>');
            }
        }

        $encoded = json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $output->writeln($encoded === false ? '{}' : $encoded);

        if (isset($record['record_error'])) {
            return Command::FAILURE;
        }

        return (int) $record['exit_code'];
    }

    /**
     * Resolve a --from/--skip token to a canonical stage id.
     *
     * @return string|false|null null for an empty token, false after reporting an unknown stage
     */
    private function resolveStageToken(mixed $token, string $option, OutputInterface $human): string|false|null
    {
        if (!is_string($token) || trim($token) === '') {
            return null;
        }

        $id = self::STAGE_ALIASES[strtolower(trim($token))] ?? null;
        if ($id === null) {
            $human->writeln(sprintf(
                '<error>%s: unknown stage "%s" (known: %s)</error>',
                $option,
                $token,
                implode(', ', self::STAGE_IDS),
            ));

            return false;
        }

        return $id;
    }

    /** @return list<Stage> */
    private function buildStages(InputInterface $input, PipelineRunner $runner, PipelineChecks $checks): array
    {
        $strategy = (string) ($input->getOption('strategy') ?? MetadataFuzzyStrategy::NAME);
        $minCoverage = $input->getOption('min-coverage');

        $combineParams = static function (string $base, ?string $into) use ($strategy, $minCoverage): array {
            $params = ['base' => $base, '--strategy' => $strategy, '--passthrough' => true];
            if ($into !== null) {
                $params['--into'] = $into;
            }
            if (is_string($minCoverage) && $minCoverage !== '') {
                $params['--min-coverage'] = $minCoverage;
            }

            return $params;
        };

        // combine already refuses to run on a schema with pending migrations;
        // this restates it so the chain stops before combining even when
        // `migrate` was skipped with --from/--skip.
        $pendingGate = new Precondition(
            'no_pending_migrations',
            'combine refuses to run while migrations are pending',
            static fn (): PreconditionResult => $checks->noPendingMigrations(),
        );

        $populatedGate = static function () use ($checks): PreconditionResult {
            foreach (self::COMBINED as $collection) {
                $result = $checks->collectionHasDocuments($collection);
                if (!$result->ok) {
                    return $result;
                }
            }

            return PreconditionResult::pass(
                'combined_collections_populated',
                'organized and organized_manga hold documents',
            );
        };

        // The combine lock guard returns SUCCESS without doing any work when
        // another process holds the lock. That must not look like a healthy run.
        $lockHeld = static function (string $output, int $exitCode): ?string {
            if (str_contains($output, 'already running in another process')) {
                return 'the combine lock is held by another process — this run did nothing';
            }

            return null;
        };

        // The live release gate: run the existing `combine:dupes` on the exact
        // state about to be published. Read-only, so a dry run can defer it.
        $dupesGate = new Precondition(
            'dupes_gate',
            'the release refuses to publish while a combined collection repeats an identity key or is empty',
            static function () use ($runner): PreconditionResult {
                $invocation = $runner->runCommand('combine:dupes', ['--json' => true]);
                if ($invocation['exit_code'] === Command::SUCCESS) {
                    return PreconditionResult::pass('dupes_gate', 'no duplicate identity keys and no empty combined collection');
                }

                $decoded = StageOutput::json($invocation['output']);
                $detail = is_array($decoded)
                    ? (string) json_encode($decoded, JSON_UNESCAPED_SLASHES)
                    : StageOutput::excerpt($invocation['output'], 800);

                return PreconditionResult::fail('dupes_gate', 'combine:dupes failed — ' . $detail);
            },
            isCommand: true,
        );

        $releaseParams = ['--verify' => true];
        foreach (['tag' => '--tag', 'repo' => '--repo', 'branch' => '--branch'] as $option => $flag) {
            $value = $input->getOption($option);
            if (is_string($value) && $value !== '') {
                $releaseParams[$flag] = $value;
            }
        }
        if ($input->getOption('draft')) {
            $releaseParams['--draft'] = true;
        }

        return [
            new Stage(
                id: 'migrate',
                label: 'Apply pending database migrations',
                command: 'migrate',
                params: [],
                precondition: new Precondition(
                    'database_reachable',
                    'the pipeline needs a reachable MongoDB before anything runs',
                    static fn (): PreconditionResult => $checks->databaseReachable(),
                ),
            ),
            new Stage(
                id: 'combine:anime',
                label: 'Combine anime sources into organized (--passthrough)',
                command: 'combine',
                params: $combineParams('anilist', null),
                precondition: $pendingGate,
                failureSignature: $lockHeld,
            ),
            new Stage(
                id: 'combine:manga',
                label: 'Combine manga sources into organized_manga (--passthrough)',
                command: 'combine',
                params: $combineParams('anilist_manga', 'organized_manga'),
                precondition: $pendingGate,
                failureSignature: $lockHeld,
            ),
            new Stage(
                id: 'hiyori-id',
                label: 'Backfill stable hiyori_id values',
                command: 'hiyori-id',
                params: ['--backfill' => true, '--json' => true],
                precondition: new Precondition(
                    'combined_collections_populated',
                    'the backfill needs the combined collections to hold documents',
                    $populatedGate,
                ),
            ),
            new Stage(
                id: 'dupes',
                label: 'Release gate: report duplicate identity keys',
                command: 'combine:dupes',
                params: ['--json' => true],
                precondition: new Precondition(
                    'combined_collections_populated',
                    'the duplicate gate is vacuous on an empty collection',
                    $populatedGate,
                ),
                repairableBy: 'dedupe',
            ),
            new Stage(
                id: 'dedupe',
                label: 'Repair duplicate identity keys',
                command: 'combine:dedupe',
                // The union of the per-collection identity keys the dupes gate
                // checks (mal,al for organized; mal_manga,al_manga for
                // organized_manga), so one invocation repairs both.
                params: ['--json' => true, '--primary' => 'mal,al,mal_manga,al_manga'],
                precondition: new Precondition(
                    'combined_collections_populated',
                    'there is nothing to repair in an empty collection',
                    $populatedGate,
                ),
            ),
            new Stage(
                id: 'release',
                label: 'Build, verify and publish the release',
                command: 'release',
                params: $releaseParams,
                precondition: $dupesGate,
            ),
        ];
    }

    /**
     * @param list<Stage> $stages
     * @param list<string> $skip
     *
     * @return list<Stage>
     */
    private function applySelection(array $stages, ?string $from, array $skip): array
    {
        $plan = [];
        $started = $from === null;

        foreach ($stages as $stage) {
            if (!$started) {
                if ($stage->id === $from) {
                    $started = true;
                } else {
                    continue;
                }
            }

            if (in_array($stage->id, $skip, true)) {
                continue;
            }

            $plan[] = $stage;
        }

        return $plan;
    }

    /** @param list<Stage> $plan */
    private function printPlan(OutputInterface $human, array $plan): void
    {
        $index = 0;
        foreach ($plan as $stage) {
            ++$index;
            $human->writeln(sprintf(
                '  %d. <info>%s</info> — %s %s',
                $index,
                $stage->id,
                $stage->command,
                $this->formatParams($stage->params),
            ));
            if ($stage->precondition !== null) {
                $human->writeln(sprintf(
                    '     precondition: %s — %s',
                    $stage->precondition->name,
                    $stage->precondition->description,
                ));
            }
        }
    }

    /** @param array<string, mixed> $params */
    private function formatParams(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if ($value === true) {
                $parts[] = $key;

                continue;
            }
            if ($value === false || $value === null) {
                continue;
            }
            $parts[] = $key . '=' . (is_scalar($value) ? (string) $value : (string) json_encode($value));
        }

        return $parts === [] ? '' : implode(' ', $parts);
    }

    private function defaultChecks(): PipelineChecks
    {
        return new DatabaseChecks(
            $this->hiyori->getDatabase()->getClient(),
            'hiyori',
            $this->hiyori->getMigrator(),
        );
    }
}
