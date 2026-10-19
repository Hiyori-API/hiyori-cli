<?php

namespace Hiyori\Service\Pipeline;

/**
 * One step of the release chain.
 *
 * A stage never reimplements a command: it names an existing registered
 * command and the exact parameters it should be driven with, plus the
 * precondition that must hold before it is allowed to run.
 */
final class Stage
{
    /**
     * @param array<string, mixed> $params ArrayInput parameters for the existing command
     * @param ?\Closure(string, int): ?string $failureSignature Maps (captured output, exit code)
     *        to a failure reason for a command that exits 0 without doing the work
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $command,
        public readonly array $params = [],
        public readonly ?Precondition $precondition = null,
        public readonly ?string $repairableBy = null,
        public readonly ?\Closure $failureSignature = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stage' => $this->id,
            'label' => $this->label,
            'command' => $this->command,
            'params' => $this->params,
            'precondition' => $this->precondition?->toArray(),
            'repairable_by' => $this->repairableBy,
        ];
    }
}
