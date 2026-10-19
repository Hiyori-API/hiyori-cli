<?php

namespace Hiyori\Service\Pipeline;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drives the release chain through the registered Symfony commands, so every
 * stage behaves exactly as it does when run by hand, and turns the run into a
 * single legible record.
 */
final class PipelineRunner
{
    public function __construct(
        private Application $application,
        private OutputInterface $output,
        private bool $narrate = true,
    ) {
    }

    /**
     * @param list<Stage> $stages
     *
     * @return array<string, mixed> the run record
     */
    public function run(array $stages, string $runId, string $database, bool $dryRun): array
    {
        $startedAt = gmdate('c');
        $start = microtime(true);
        $results = [];
        $tolerated = [];
        $failedStage = null;
        $wouldFailAt = null;

        foreach ($stages as $index => $stage) {
            $precondition = null;
            if ($stage->precondition !== null) {
                $precondition = $this->evaluate($stage->precondition, $dryRun);
                if (!$precondition->ok) {
                    $results[] = new StageResult(
                        id: $stage->id,
                        label: $stage->label,
                        command: $stage->command,
                        params: $stage->params,
                        status: 'aborted',
                        exitCode: null,
                        durationMs: 0,
                        precondition: $precondition,
                        reported: null,
                        failureReason: $precondition->detail,
                    );
                    $failedStage = $stage->id;
                    $wouldFailAt = $stage->id;
                    $this->narrate(sprintf('<error>✗ %s: precondition %s failed — %s</error>', $stage->id, $precondition->name, $precondition->detail));
                    break;
                }
            }

            if ($dryRun) {
                $results[] = new StageResult(
                    id: $stage->id,
                    label: $stage->label,
                    command: $stage->command,
                    params: $stage->params,
                    status: 'planned',
                    exitCode: null,
                    durationMs: 0,
                    precondition: $precondition,
                );
                continue;
            }

            $result = $this->runStage($stage, $precondition);
            if ($result->status === 'failed'
                && $stage->repairableBy !== null
                && $this->hasStageAfter($stages, $index, $stage->repairableBy)
            ) {
                $results[] = $result->tolerate($stage->repairableBy);
                $tolerated[] = $stage->id;
                $this->narrate(sprintf(
                    '<comment>! %s failed but `%s` follows — treating it as the repair trigger</comment>',
                    $stage->id,
                    $stage->repairableBy,
                ));

                continue;
            }

            $results[] = $result;
            if ($result->status === 'failed') {
                $failedStage = $stage->id;
                $this->narrate(sprintf('<error>✗ %s failed (exit %s) — stopping the chain</error>', $stage->id, (string) $result->exitCode));
                break;
            }
        }

        $status = $dryRun ? 'dry-run' : ($failedStage === null ? 'success' : 'failed');

        return [
            'pipeline' => 'release',
            'version' => 1,
            'run_id' => $runId,
            'started_at' => $startedAt,
            'finished_at' => gmdate('c'),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => $status,
            'exit_code' => $failedStage !== null ? Command::FAILURE : Command::SUCCESS,
            'dry_run' => $dryRun,
            'database' => $database,
            'failed_stage' => $failedStage,
            'would_fail_at' => $dryRun ? $wouldFailAt : null,
            'tolerated_failures' => $tolerated,
            'plan' => array_map(static fn (Stage $stage): array => $stage->toArray(), $stages),
            'stages' => array_map(static fn (StageResult $result): array => $result->toArray(), $results),
        ];
    }

    /**
     * Run one registered command and capture everything it printed.
     *
     * @param array<string, mixed> $params
     *
     * @return array{exit_code: int, output: string, error: ?string, duration_ms: int}
     */
    public function runCommand(string $command, array $params, int $verbosity = OutputInterface::VERBOSITY_NORMAL): array
    {
        $start = microtime(true);
        try {
            $definition = $this->application->find($command);
        } catch (\Throwable $e) {
            return ['exit_code' => Command::FAILURE, 'output' => '', 'error' => $e->getMessage(), 'duration_ms' => 0];
        }

        $input = new ArrayInput($params);
        $input->setInteractive(false);
        $buffer = new BufferedOutput($verbosity);

        try {
            $exitCode = $definition->run($input, $buffer);
            $error = null;
        } catch (\Throwable $e) {
            $exitCode = Command::FAILURE;
            $error = $e->getMessage();
        }

        return [
            'exit_code' => $exitCode,
            'output' => $buffer->fetch(),
            'error' => $error,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];
    }

    private function runStage(Stage $stage, ?PreconditionResult $precondition): StageResult
    {
        $invocation = $this->runCommand($stage->command, $stage->params, $this->output->getVerbosity());
        $output = $invocation['output'];
        $exitCode = $invocation['exit_code'];
        $reason = $invocation['error'];

        if ($exitCode !== Command::SUCCESS) {
            $reason ??= sprintf('`%s` exited %d', $stage->command, $exitCode);
        } elseif ($stage->failureSignature !== null) {
            $reason = ($stage->failureSignature)($output, $exitCode);
            if ($reason !== null) {
                $exitCode = Command::FAILURE;
            }
        }

        if ($this->narrate) {
            $this->narrate($output);
            $this->narrate($exitCode === Command::SUCCESS
                ? sprintf('<info>✓ %s</info> (%d ms)', $stage->id, $invocation['duration_ms'])
                : sprintf('<error>✗ %s (exit %d)</error>', $stage->id, $exitCode));
        }

        return new StageResult(
            id: $stage->id,
            label: $stage->label,
            command: $stage->command,
            params: $stage->params,
            status: $exitCode === Command::SUCCESS ? 'success' : 'failed',
            exitCode: $exitCode,
            durationMs: $invocation['duration_ms'],
            precondition: $precondition,
            reported: $this->reported($output),
            failureReason: $reason,
        );
    }

    private function evaluate(Precondition $precondition, bool $dryRun): PreconditionResult
    {
        if ($dryRun && $precondition->isCommand) {
            return PreconditionResult::deferred($precondition->name, 'deferred: --dry-run runs no command');
        }

        try {
            return $precondition->evaluate();
        } catch (\Throwable $e) {
            return PreconditionResult::fail($precondition->name, 'could not evaluate: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function reported(string $output): array
    {
        $json = StageOutput::json($output);
        if ($json !== null) {
            return $json;
        }

        $reported = ['output' => StageOutput::excerpt($output)];
        $coverage = StageOutput::combineCoverage($output);
        if ($coverage !== null) {
            $reported['coverage'] = $coverage;
        }

        return $reported;
    }

    /** @param list<Stage> $stages */
    private function hasStageAfter(array $stages, int $index, string $stageId): bool
    {
        $count = count($stages);
        for ($i = $index + 1; $i < $count; $i++) {
            if ($stages[$i]->id === $stageId) {
                return true;
            }
        }

        return false;
    }

    private function narrate(string $message): void
    {
        if ($this->narrate && $message !== '') {
            $this->output->writeln($message);
        }
    }
}
