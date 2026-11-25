<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

final readonly class DedupeResult
{
    /**
     * @param  int  $total  Records read from the input.
     * @param  list<mixed>  $survivors  The kept records, exactly as they were passed in, in input order.
     * @param  list<AuditEntry>  $audit  One entry per dropped record, in the order they were dropped.
     */
    public function __construct(
        public string $runId,
        public int $total,
        public array $survivors,
        public array $audit,
    ) {
        //
    }

    public function survivorCount(): int
    {
        return count($this->survivors);
    }

    public function droppedCount(): int
    {
        return count($this->audit);
    }

    /**
     * How many records each match key removed, keyed by the key's name.
     *
     * @return array<string, int>
     */
    public function droppedByKey(): array
    {
        $counts = [];

        foreach ($this->audit as $entry) {
            $counts[$entry->matchedKey] = ($counts[$entry->matchedKey] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Why was this record dropped? Null when it was not.
     */
    public function why(string|int $droppedId): ?AuditEntry
    {
        foreach ($this->audit as $entry) {
            if ($entry->droppedId === (string) $droppedId) {
                return $entry;
            }
        }

        return null;
    }
}
