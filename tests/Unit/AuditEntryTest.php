<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Exceptions\InvalidAuditEntry;
use Keaton\Dedupe\Support\RecordReader;
use Keaton\Dedupe\Survivorship;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AuditEntryTest extends TestCase
{
    #[Test]
    public function it_can_round_trip_through_an_array(): void
    {
        $entry = new AuditEntry(
            runId: 'run-1',
            droppedId: '812',
            droppedHash: str_repeat('a', 64),
            survivorId: '640',
            matchedId: '700',
            matchedKey: 'email',
            matchedValue: 'jane@example.com',
            droppedRecordAt: new DateTimeImmutable('2026-11-05 10:04:00', new DateTimeZone('UTC')),
            secondsApart: 240,
            survivorship: Survivorship::Last,
            windowSeconds: 86_400,
            dedupedAt: new DateTimeImmutable('2026-11-05 12:00:00', new DateTimeZone('UTC')),
        );

        $this->assertEquals($entry, AuditEntry::fromArray($entry->toArray()));
    }

    #[Test]
    public function it_writes_dates_in_utc(): void
    {
        $entry = $this->entry(new DateTimeImmutable('2026-11-05 12:00:00', new DateTimeZone('America/Chicago')));

        $this->assertSame('2026-11-05 18:00:00', $entry->toArray()['deduped_at']);
    }

    #[Test]
    public function it_keeps_missing_optional_values_as_null(): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();

        $this->assertNull($row['matched_value']);
        $this->assertNull($row['dropped_record_at']);
        $this->assertNull($row['seconds_apart']);
        $this->assertNull($row['window_seconds']);
        $this->assertSame('first', $row['survivorship']);
    }

    #[Test]
    public function it_can_tell_whether_a_record_is_the_one_that_was_dropped(): void
    {
        $result = Deduper::make()->key('email')->run([
            ['id' => 1, 'email' => 'jane@example.com'],
            ['id' => 2, 'email' => 'jane@example.com', 'source' => 'partner'],
        ]);
        $entry = $result->audit[0];

        $this->assertTrue($entry->isFor(['source' => 'partner', 'email' => 'jane@example.com', 'id' => 2]));
        $this->assertTrue($entry->isFor((object) ['id' => 2, 'email' => 'jane@example.com', 'source' => 'partner']));
        $this->assertFalse($entry->isFor(['id' => 2, 'email' => 'jane@example.com', 'source' => 'web']));
        $this->assertFalse($entry->isFor(['id' => '2', 'email' => 'jane@example.com', 'source' => 'partner']));
        $this->assertFalse($entry->isFor(['id' => 1, 'email' => 'jane@example.com']));
    }

    #[Test]
    public function it_refuses_a_row_missing_a_required_field(): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();
        unset($row['survivor_id']);

        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage('An audit row needs a [survivor_id] value');

        AuditEntry::fromArray($row);
    }

    #[Test]
    public function it_refuses_a_row_without_a_decision_time(): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();
        $row['deduped_at'] = null;

        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage('[deduped_at]');

        AuditEntry::fromArray($row);
    }

    #[Test]
    public function it_refuses_a_row_with_an_unknown_survivorship(): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();
        $row['survivorship'] = 'middle';

        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage('Got [middle]');

        AuditEntry::fromArray($row);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function unreadableRowValues(): array
    {
        return [
            'seconds that are not a number' => ['seconds_apart', 'soon'],
            'negative seconds' => ['seconds_apart', -1],
            'a fractional window' => ['window_seconds', '1.5'],
            'a date that rolls over' => ['deduped_at', '2026-13-45 00:00:00'],
            'a five-digit year' => ['dropped_record_at', '10000-01-01 00:00:00'],
            'a date in another format' => ['dropped_record_at', '05/11/2026'],
            'a matched value that is an array' => ['matched_value', ['x']],
        ];
    }

    #[Test]
    #[DataProvider('unreadableRowValues')]
    public function it_refuses_a_row_value_it_cannot_read(string $field, mixed $value): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();
        $row[$field] = $value;

        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage("[{$field}]");

        AuditEntry::fromArray($row);
    }

    #[Test]
    public function it_reads_counts_stored_as_numeric_strings(): void
    {
        $row = $this->entry(new DateTimeImmutable('@0'))->toArray();
        $row['seconds_apart'] = '2520';
        $row['window_seconds'] = 86_400;

        $entry = AuditEntry::fromArray($row);

        $this->assertSame(2520, $entry->secondsApart);
        $this->assertSame(86_400, $entry->windowSeconds);
    }

    #[Test]
    public function it_keeps_dates_to_the_second_so_a_row_round_trips(): void
    {
        $entry = new AuditEntry(
            runId: 'run-1',
            droppedId: '2',
            droppedHash: str_repeat('b', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: new DateTimeImmutable('2026-11-05 09:42:00.250000', new DateTimeZone('UTC')),
            secondsApart: 2520,
            survivorship: Survivorship::First,
            windowSeconds: null,
            dedupedAt: new DateTimeImmutable('2026-11-05 12:00:00.987654', new DateTimeZone('UTC')),
        );

        $this->assertSame('00:00.000000', $entry->dedupedAt->format('i:s.u'));
        $this->assertSame('42:00.000000', $entry->droppedRecordAt?->format('i:s.u'));
        $this->assertEquals($entry, AuditEntry::fromArray($entry->toArray()));
    }

    #[Test]
    public function it_refuses_to_be_built_with_an_empty_required_field(): void
    {
        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage('[runId]');

        new AuditEntry(
            runId: '',
            droppedId: '2',
            droppedHash: str_repeat('b', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: null,
            secondsApart: null,
            survivorship: Survivorship::First,
            windowSeconds: null,
            dedupedAt: new DateTimeImmutable('@0'),
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function counts(): array
    {
        return ['seconds apart' => ['secondsApart'], 'window' => ['windowSeconds']];
    }

    #[Test]
    #[DataProvider('counts')]
    public function it_refuses_to_be_built_with_a_negative_count(string $field): void
    {
        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage("[{$field}]");

        new AuditEntry(
            runId: 'run-1',
            droppedId: '2',
            droppedHash: str_repeat('b', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: null,
            secondsApart: $field === 'secondsApart' ? -1 : null,
            survivorship: Survivorship::First,
            windowSeconds: $field === 'windowSeconds' ? -1 : null,
            dedupedAt: new DateTimeImmutable('@0'),
        );
    }

    #[Test]
    public function it_refuses_to_be_built_with_a_date_the_audit_table_cannot_hold(): void
    {
        $this->expectException(InvalidAuditEntry::class);
        $this->expectExceptionMessage('[droppedRecordAt]');

        new AuditEntry(
            runId: 'run-1',
            droppedId: '2',
            droppedHash: str_repeat('b', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: new DateTimeImmutable('@'.(RecordReader::LATEST + 1)),
            secondsApart: null,
            survivorship: Survivorship::First,
            windowSeconds: null,
            dedupedAt: new DateTimeImmutable('@0'),
        );
    }

    private function entry(DateTimeImmutable $dedupedAt): AuditEntry
    {
        return new AuditEntry(
            runId: 'run-1',
            droppedId: '2',
            droppedHash: str_repeat('b', 64),
            survivorId: '1',
            matchedId: '1',
            matchedKey: 'email',
            matchedValue: null,
            droppedRecordAt: null,
            secondsApart: null,
            survivorship: Survivorship::First,
            windowSeconds: null,
            dedupedAt: $dedupedAt,
        );
    }
}
