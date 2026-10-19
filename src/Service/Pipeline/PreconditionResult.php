<?php

namespace Hiyori\Service\Pipeline;

/**
 * Outcome of a single precondition check.
 *
 * A precondition is a read-only assertion about the state a stage is about to
 * consume. `ok === false` means the pipeline must stop before the stage runs;
 * it is never a request to retry the stage.
 */
final class PreconditionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $name,
        public readonly string $detail,
        public readonly bool $deferred = false,
    ) {
    }

    public static function pass(string $name, string $detail = ''): self
    {
        return new self(true, $name, $detail);
    }

    public static function fail(string $name, string $detail): self
    {
        return new self(false, $name, $detail);
    }

    /**
     * A check that was not evaluated (e.g. a command-backed gate under
     * `--dry-run`, which must run nothing). It is reported, not assumed to pass.
     */
    public static function deferred(string $name, string $detail): self
    {
        return new self(true, $name, $detail, true);
    }

    /** @return array{name: string, ok: bool, deferred: bool, detail: string} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'ok' => $this->ok,
            'deferred' => $this->deferred,
            'detail' => $this->detail,
        ];
    }
}
