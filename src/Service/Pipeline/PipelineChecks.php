<?php

namespace Hiyori\Service\Pipeline;

/**
 * The read-only state the release chain reads before it runs a stage.
 *
 * Kept behind an interface so the orchestrator's decision logic is testable
 * without a live database.
 */
interface PipelineChecks
{
    /** The database these checks read, recorded in the run record. */
    public function database(): string;

    public function databaseReachable(): PreconditionResult;

    public function noPendingMigrations(): PreconditionResult;

    public function collectionHasDocuments(string $collection): PreconditionResult;
}
