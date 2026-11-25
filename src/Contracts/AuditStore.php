<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Contracts;

use DateTimeInterface;
use Keaton\Dedupe\AuditEntry;

interface AuditStore
{
    /**
     * Persist the audit entries produced by one run.
     *
     * @param  list<AuditEntry>  $entries
     */
    public function store(array $entries): void;

    /**
     * Every entry written by one run, oldest first.
     *
     * @return list<AuditEntry>
     */
    public function forRun(string $runId): array;

    /**
     * Every time a record was dropped, across all runs, oldest first.
     *
     * @return list<AuditEntry>
     */
    public function forDroppedRecord(string $recordId): array;

    /**
     * Delete entries recorded before the given moment and return how many went.
     */
    public function prune(DateTimeInterface $before): int;
}
