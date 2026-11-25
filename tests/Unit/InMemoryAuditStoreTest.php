<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Stores\InMemoryAuditStore;
use Keaton\Dedupe\Survivorship;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InMemoryAuditStoreTest extends TestCase
{
    #[Test]
    public function it_keeps_an_entry_from_the_same_second_as_a_fractional_cutoff_like_the_database_store(): void
    {
        $store = new InMemoryAuditStore;
        $store->store([$this->entryAt('2026-11-05 12:00:00'), $this->entryAt('2026-11-05 11:59:59')]);

        $removed = $store->prune(new DateTimeImmutable('2026-11-05 12:00:00.500000', new DateTimeZone('UTC')));

        $this->assertSame(1, $removed);
        $this->assertSame(['2026-11-05 12:00:00'], array_map(fn (AuditEntry $entry): string => $entry->dedupedAt->format('Y-m-d H:i:s'), $store->all()));
    }

    private function entryAt(string $dedupedAt): AuditEntry
    {
        return new AuditEntry(
            runId: 'run-1',
            droppedId: '2',
            droppedHash: str_repeat('a', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: null,
            secondsApart: null,
            survivorship: Survivorship::First,
            windowSeconds: null,
            dedupedAt: new DateTimeImmutable($dedupedAt, new DateTimeZone('UTC')),
        );
    }
}
