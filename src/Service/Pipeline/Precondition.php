<?php

namespace Hiyori\Service\Pipeline;

/**
 * A named, read-only assertion evaluated immediately before a stage runs.
 *
 * `isCommand` marks checks that have to execute a command (for example the
 * live `combine:dupes` release gate). `--dry-run` defers those instead of
 * running them, so a dry run really does run nothing.
 */
final class Precondition
{
    /** @param \Closure(): PreconditionResult $check */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        private readonly \Closure $check,
        public readonly bool $isCommand = false,
    ) {
    }

    public function evaluate(): PreconditionResult
    {
        return ($this->check)();
    }

    /** @return array{name: string, description: string, is_command: bool} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'is_command' => $this->isCommand,
        ];
    }
}
