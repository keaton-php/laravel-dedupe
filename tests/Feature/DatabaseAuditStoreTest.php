<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Exceptions\InvalidAuditEntry;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Stores\DatabaseAuditStore;
use Keaton\Dedupe\Support\RecordReader;
use Keaton\Dedupe\Survivorship;
use Keaton\Dedupe\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DatabaseAuditStoreTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_store_an_entry_and_read_it_back_unchanged(): void
    {
        $store = $this->store();
        $entry = $this->entry('run-1', '812', '640', 'email', '2026-11-05 12:00:00');

        $store->store([$entry]);

        $this->assertEquals([$entry], $store->forRun('run-1'));
    }

    #[Test]
    public function it_keeps_each_runs_entries_apart(): void
    {
        $store = $this->store();

        $store->store([
            $this->entry('run-1', '2', '1', 'email', '2026-11-05 12:00:00'),
            $this->entry('run-2', '9', '8', 'phone', '2026-11-06 12:00:00'),
            $this->entry('run-1', '3', '1', 'email', '2026-11-05 12:00:00'),
        ]);

        $this->assertSame(['2', '3'], array_map(fn (AuditEntry $e): string => $e->droppedId, $store->forRun('run-1')));
        $this->assertSame(['9'], array_map(fn (AuditEntry $e): string => $e->droppedId, $store->forRun('run-2')));
        $this->assertSame([], $store->forRun('run-3'));
    }

    #[Test]
    public function it_can_find_every_time_a_record_was_dropped(): void
    {
        $store = $this->store();

        $store->store([
            $this->entry('run-1', '42', '1', 'email', '2026-11-05 12:00:00'),
            $this->entry('run-2', '42', '7', 'phone', '2026-11-06 12:00:00'),
            $this->entry('run-2', '43', '7', 'phone', '2026-11-06 12:00:00'),
        ]);

        $entries = $store->forDroppedRecord('42');

        $this->assertSame(['run-1', 'run-2'], array_map(fn (AuditEntry $e): string => $e->runId, $entries));
        $this->assertSame(['1', '7'], array_map(fn (AuditEntry $e): string => $e->survivorId, $entries));
    }

    #[Test]
    public function it_inserts_in_chunks_without_losing_or_reordering_entries(): void
    {
        $store = $this->store(chunkSize: 2);
        $entries = array_map(
            fn (int $n): AuditEntry => $this->entry('run-1', (string) $n, '0', 'email', '2026-11-05 12:00:00'),
            range(1, 5),
        );

        $store->store($entries);

        $this->assertEquals($entries, $store->forRun('run-1'));
    }

    #[Test]
    public function it_refuses_a_chunk_size_below_one(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Got a chunk size of [0]');

        // @phpstan-ignore argument.type (zero is the mistake under test)
        new DatabaseAuditStore(app(ConnectionResolverInterface::class), chunkSize: 0);
    }

    #[Test]
    public function it_does_nothing_for_an_empty_run(): void
    {
        $this->store()->store([]);

        $this->assertSame(0, DB::table('dedupe_audit_entries')->count());
    }

    #[Test]
    public function it_leaves_the_matched_value_out_unless_asked_to_keep_it(): void
    {
        $entry = $this->entry('run-1', '2', '1', 'email', '2026-11-05 12:00:00');

        $this->store(storeValues: false)->store([$entry]);
        $this->store(storeValues: true)->store([$entry]);

        $values = DB::table('dedupe_audit_entries')->orderBy('id')->pluck('matched_value')->all();

        $this->assertSame([null, 'jane@example.com'], $values);
    }

    #[Test]
    public function it_can_prune_entries_recorded_before_a_date(): void
    {
        $store = $this->store();

        $store->store([
            $this->entry('old', '1', '0', 'email', '2026-01-01 00:00:00'),
            $this->entry('old', '2', '0', 'email', '2026-01-02 00:00:00'),
            $this->entry('new', '3', '0', 'email', '2026-06-01 00:00:00'),
        ]);

        $removed = $store->prune(new DateTimeImmutable('2026-03-01 00:00:00', new DateTimeZone('UTC')));

        $this->assertSame(2, $removed);
        $this->assertSame([], $store->forRun('old'));
        $this->assertCount(1, $store->forRun('new'));
    }

    #[Test]
    public function it_reads_back_exactly_what_a_run_with_the_real_clock_returned(): void
    {
        $store = $this->store();

        $result = Deduper::make()->key('email')->timestamp('at')->auditTo($store)->run([
            ['id' => 1, 'email' => 'a@example.com', 'at' => '2026-11-05 09:00:00'],
            ['id' => 2, 'email' => 'a@example.com', 'at' => '2026-11-05 09:42:00'],
        ], 'run-1');

        $this->assertEquals($result->audit, $store->forRun('run-1'));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function overlongColumns(): array
    {
        return [
            'run id' => ['run_id', 191],
            'dropped id' => ['dropped_id', 191],
            'key name' => ['matched_key', 128],
        ];
    }

    #[Test]
    #[DataProvider('overlongColumns')]
    public function it_refuses_a_value_longer_than_its_column_and_writes_nothing(string $column, int $max): void
    {
        $long = str_repeat('x', $max + 1);
        $deduper = Deduper::make()->key('email', as: $column === 'matched_key' ? $long : null)->id('id')->auditTo($this->store());
        $records = [
            ['id' => 'ok', 'email' => 'a@example.com'],
            ['id' => $column === 'dropped_id' ? $long : 'two', 'email' => 'a@example.com'],
        ];

        try {
            $deduper->run($records, $column === 'run_id' ? $long : 'run-1');
            $this->fail('An over-long value was written');
        } catch (InvalidAuditEntry $e) {
            $this->assertStringContainsString("[{$column}] is longer than the {$max} characters", $e->getMessage());
        }

        $this->assertSame(0, DB::table('dedupe_audit_entries')->count());
    }

    #[Test]
    public function it_accepts_values_exactly_as_long_as_their_columns(): void
    {
        $id = str_repeat('é', 191);

        Deduper::make()->key('email')->auditTo($this->store())->run([
            ['id' => 'ok', 'email' => 'a@example.com'],
            ['id' => $id, 'email' => 'a@example.com'],
        ], str_repeat('r', 191));

        $this->assertSame($id, DB::table('dedupe_audit_entries')->value('dropped_id'));
    }

    #[Test]
    public function it_keeps_an_entry_from_the_same_second_as_a_fractional_cutoff_like_the_memory_store(): void
    {
        $store = $this->store();
        $store->store([
            $this->entry('run-1', '2', '1', 'email', '2026-11-05 12:00:00'),
            $this->entry('run-1', '3', '1', 'email', '2026-11-05 11:59:59'),
        ]);

        $removed = $store->prune(new DateTimeImmutable('2026-11-05 12:00:00.500000', new DateTimeZone('UTC')));

        $this->assertSame(1, $removed);
        $this->assertSame(['2'], array_map(fn (AuditEntry $entry): string => $entry->droppedId, $store->forRun('run-1')));
    }

    #[Test]
    public function it_reads_back_every_entry_a_run_could_write(): void
    {
        $store = $this->store();

        $result = Deduper::make()->key('email')->auditTo($store)->run([
            ['email' => 'a@example.com'],
            ['email' => 'a@example.com'],
        ]);

        $this->assertSame('#2', $store->forRun($result->runId)[0]->droppedId);
        $this->assertEquals($result->audit, $store->forRun($result->runId));
    }

    #[Test]
    public function it_stores_and_reads_back_entries_at_both_ends_of_the_storable_years(): void
    {
        $store = $this->store();

        $result = Deduper::make()->key('email')->timestamp('t')->auditTo($store)->run([
            ['id' => 1, 'email' => 'a@example.com', 't' => RecordReader::LATEST],
            ['id' => 2, 'email' => 'a@example.com', 't' => RecordReader::EARLIEST],
            ['id' => 3, 'email' => 'a@example.com', 't' => '9999-12-31 23:59:59'],
        ], 'edges');

        $this->assertEquals($result->audit, $store->forRun('edges'));
        $this->assertSame('1753-01-01 00:00:00', $store->forRun('edges')[0]->droppedRecordAt?->format('Y-m-d H:i:s'));
    }

    /**
     * @param  positive-int  $chunkSize
     */
    private function store(bool $storeValues = true, int $chunkSize = 75): DatabaseAuditStore
    {
        return new DatabaseAuditStore(
            app(ConnectionResolverInterface::class),
            'dedupe_audit_entries',
            null,
            $storeValues,
            $chunkSize,
        );
    }

    private function entry(string $runId, string $droppedId, string $survivorId, string $key, string $dedupedAt): AuditEntry
    {
        $utc = new DateTimeZone('UTC');

        return new AuditEntry(
            runId: $runId,
            droppedId: $droppedId,
            droppedHash: hash('sha256', $droppedId),
            survivorId: $survivorId,
            matchedId: $survivorId,
            matchedKey: $key,
            matchedValue: 'jane@example.com',
            droppedRecordAt: new DateTimeImmutable('2026-11-05 11:55:00', $utc),
            secondsApart: 300,
            survivorship: Survivorship::First,
            windowSeconds: 86_400,
            dedupedAt: new DateTimeImmutable($dedupedAt, $utc),
        );
    }
}
