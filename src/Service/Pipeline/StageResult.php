<?php

namespace Hiyori\Service\Pipeline;

/**
 * The outcome of one stage: what ran, whether it was allowed to run, how long
 * it took and whatever it reported.
 */
final class StageResult
{
    /**
     * @param array<string, mixed> $params
     * @param mixed $reported decoded JSON for a `--json` stage, or an output excerpt plus parsed numbers
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $command,
        public readonly array $params,
        public readonly string $status,
        public readonly ?int $exitCode,
        public readonly int $durationMs,
        public readonly ?PreconditionResult $precondition = null,
        public readonly mixed $reported = null,
        public readonly ?string $failureReason = null,
        public readonly bool $tolerated = false,
        public readonly ?string $repairedBy = null,
    ) {
    }

    /**
     * Mark a failed stage as tolerated because the named repair stage follows.
     */
    public function tolerate(string $repairedBy): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            command: $this->command,
            params: $this->params,
            status: $this->status,
            exitCode: $this->exitCode,
            durationMs: $this->durationMs,
            precondition: $this->precondition,
            reported: $this->reported,
            failureReason: $this->failureReason,
            tolerated: true,
            repairedBy: $repairedBy,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stage' => $this->id,
            'label' => $this->label,
            'command' => $this->command,
            'params' => $this->params,
            'status' => $this->status,
            'exit_code' => $this->exitCode,
            'duration_ms' => $this->durationMs,
            'precondition' => $this->precondition?->toArray(),
            'reported' => $this->reported,
            'failure_reason' => $this->failureReason,
            'tolerated' => $this->tolerated,
            'repaired_by' => $this->repairedBy,
        ];
    }
}
