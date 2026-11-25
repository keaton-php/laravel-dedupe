<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Stores;

use DateTimeImmutable;
use DateTimeInterface;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Contracts\AuditStore;

/**
 * Keeps the trail in memory. For tests, scripts and one-off runs.
 */
class InMemoryAuditStore implements AuditStore
{
    /** @var list<AuditEntry> */
    protected array $entries = [];

    public function store(array $entries): void
    {
        foreach ($entries as $entry) {
            $this->entries[] = $entry;
        }
    }

    public function forRun(string $runId): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (AuditEntry $entry): bool => $entry->runId === $runId,
        ));
    }

    public function forDroppedRecord(string $recordId): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (AuditEntry $entry): bool => $entry->droppedId === $recordId,
        ));
    }

    public function prune(DateTimeInterface $before): int
    {
        // Entries are kept to the second, so the cutoff is too, as in the database.
        $cutoff = (new DateTimeImmutable('@'.$before->getTimestamp()));

        $kept = array_values(array_filter(
            $this->entries,
            fn (AuditEntry $entry): bool => $entry->dedupedAt >= $cutoff,
        ));

        $removed = count($this->entries) - count($kept);
        $this->entries = $kept;

        return $removed;
    }

    /**
     * @return list<AuditEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }
}
