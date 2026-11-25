<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use ArrayAccess;
use ArrayObject;
use DateTimeImmutable;
use DateTimeZone;
use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\DedupeResult;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Exceptions\InvalidRecord;
use Keaton\Dedupe\MatchKey;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\LowercaseTrim;
use Keaton\Dedupe\Normalizers\PhoneDigits;
use Keaton\Dedupe\Stores\InMemoryAuditStore;
use Keaton\Dedupe\Support\RecordReader;
use Keaton\Dedupe\Survivorship;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

class DeduperTest extends TestCase
{
    #[Test]
    public function it_can_keep_the_first_record_of_each_group(): void
    {
        $result = Deduper::make()
            ->key('email', new LowercaseTrim)
            ->keepFirst()
            ->run([
                $this->lead(1, 'jane@example.com'),
                $this->lead(2, 'JANE@example.com'),
                $this->lead(3, 'bob@example.com'),
            ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
        $this->assertSame(3, $result->total);
        $this->assertSame(2, $result->survivorCount());
        $this->assertSame(1, $result->droppedCount());
        $this->assertSame('2', $result->audit[0]->droppedId);
        $this->assertSame('1', $result->audit[0]->survivorId);
    }

    #[Test]
    public function it_can_keep_the_last_record_of_each_group(): void
    {
        $result = Deduper::make()
            ->key('email', new LowercaseTrim)
            ->keepLast()
            ->run([
                $this->lead(1, 'jane@example.com'),
                $this->lead(2, 'JANE@example.com'),
                $this->lead(3, 'bob@example.com'),
            ]);

        $this->assertSame([2, 3], $this->survivorIds($result));
        $this->assertSame('1', $result->audit[0]->droppedId);
        $this->assertSame('2', $result->audit[0]->survivorId);
        $this->assertSame(Survivorship::Last, $result->audit[0]->survivorship);
    }

    #[Test]
    public function it_records_the_survivor_and_the_matching_key_in_the_audit_trail(): void
    {
        $result = Deduper::make()
            ->key('email', new EmailCanonical)
            ->key('phone', new PhoneDigits(10))
            ->keepFirst()
            ->run([
                $this->lead(101, 'jane@gmail.com', '+1 (512) 555-0100'),
                $this->lead(102, 'j.a.n.e+promo@gmail.com', '999 111 2222'),
                $this->lead(103, 'bob@example.com', '512-555-0100'),
                $this->lead(104, 'carol@example.com', '777 888 9999'),
            ], 'run-1');

        $this->assertSame([101, 104], $this->survivorIds($result));
        $this->assertCount(2, $result->audit);

        $byEmail = $result->why('102');
        $this->assertNotNull($byEmail);
        $this->assertSame('run-1', $byEmail->runId);
        $this->assertSame('101', $byEmail->survivorId);
        $this->assertSame('101', $byEmail->matchedId);
        $this->assertSame('email', $byEmail->matchedKey);
        $this->assertSame('jane@gmail.com', $byEmail->matchedValue);

        $byPhone = $result->why('103');
        $this->assertNotNull($byPhone);
        $this->assertSame('101', $byPhone->survivorId);
        $this->assertSame('phone', $byPhone->matchedKey);
        $this->assertSame('5125550100', $byPhone->matchedValue);

        $this->assertSame(['email' => 1, 'phone' => 1], $result->droppedByKey());
        $this->assertNull($result->why('104'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $byEmail->droppedHash);
        $this->assertNotSame($byEmail->droppedHash, $byPhone->droppedHash);
    }

    #[Test]
    public function it_reports_the_first_declared_key_when_several_match(): void
    {
        $records = [
            $this->lead(1, 'jane@example.com', '5125550100'),
            $this->lead(2, 'jane@example.com', '5125550100'),
        ];

        $emailFirst = Deduper::make()->key('email')->key('phone')->run($records);
        $phoneFirst = Deduper::make()->key('phone')->key('email')->run($records);

        $this->assertSame('email', $emailFirst->audit[0]->matchedKey);
        $this->assertSame('phone', $phoneFirst->audit[0]->matchedKey);
    }

    #[Test]
    public function it_never_matches_on_blank_values(): void
    {
        $result = Deduper::make()
            ->key('email', new LowercaseTrim)
            ->run([
                $this->lead(1, ''),
                $this->lead(2, '   '),
                ['id' => 3],
                ['id' => 4, 'email' => null],
                $this->lead(5, 'jane@example.com'),
            ]);

        $this->assertSame([1, 2, 3, 4, 5], $this->survivorIds($result));
        $this->assertSame([], $result->audit);
    }

    #[Test]
    public function it_compares_values_exactly_when_no_normalizer_is_given(): void
    {
        $records = [$this->lead(1, 'Jane@example.com'), $this->lead(2, 'jane@example.com')];

        $exact = Deduper::make()->key('email')->run($records);
        $normalized = Deduper::make()->key('email', new LowercaseTrim)->run($records);

        $this->assertSame([1, 2], $this->survivorIds($exact));
        $this->assertSame([1], $this->survivorIds($normalized));
    }

    #[Test]
    public function it_can_match_on_a_composite_key(): void
    {
        $result = Deduper::make()
            ->compositeKey('name_and_zip', ['last_name' => new LowercaseTrim, 'zip' => null])
            ->run([
                ['id' => 1, 'last_name' => 'Smith', 'zip' => '78676'],
                ['id' => 2, 'last_name' => ' smith ', 'zip' => '78676'],
                ['id' => 3, 'last_name' => 'Smith', 'zip' => '78701'],
            ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
        $this->assertSame('name_and_zip', $result->audit[0]->matchedKey);
    }

    #[Test]
    public function it_keeps_composite_parts_from_running_together(): void
    {
        $result = Deduper::make()
            ->compositeKey('pair', ['a' => null, 'b' => null])
            ->run([
                ['id' => 1, 'a' => 'a', 'b' => 'bc'],
                ['id' => 2, 'a' => 'ab', 'b' => 'c'],
            ]);

        $this->assertSame([1, 2], $this->survivorIds($result));
    }

    #[Test]
    public function it_only_collides_records_inside_the_window(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->timestamp('created_at')
            ->window(600)
            ->run([
                $this->lead(1, 'jane@example.com', '', 0),
                $this->lead(2, 'jane@example.com', '', 300),
                $this->lead(3, 'jane@example.com', '', 1000),
            ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
        $this->assertSame('2', $result->audit[0]->droppedId);
        $this->assertSame(600, $result->audit[0]->windowSeconds);
    }

    #[Test]
    public function it_includes_the_window_boundary_and_nothing_past_it(): void
    {
        $run = fn (int $secondsLater): array => $this->survivorIds(
            Deduper::make()->key('email')->timestamp('created_at')->window(600)->run([
                $this->lead(1, 'jane@example.com', '', 0),
                $this->lead(2, 'jane@example.com', '', $secondsLater),
            ]),
        );

        $this->assertSame([1], $run(600));
        $this->assertSame([1, 2], $run(601));
    }

    #[Test]
    public function it_collides_records_however_far_apart_when_there_is_no_window(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->timestamp('created_at')
            ->run([
                $this->lead(1, 'jane@example.com', '', 0),
                $this->lead(2, 'jane@example.com', '', 315_360_000),
            ]);

        $this->assertSame([1], $this->survivorIds($result));
    }

    #[Test]
    public function it_measures_the_window_from_the_first_record_of_a_group(): void
    {
        $records = [
            $this->lead(1, 'jane@example.com', '', 0),
            $this->lead(2, 'jane@example.com', '', 8),
            $this->lead(3, 'jane@example.com', '', 16),
        ];

        $first = Deduper::make()->key('email')->timestamp('created_at')->window(10)->keepFirst()->run($records);
        $last = Deduper::make()->key('email')->timestamp('created_at')->window(10)->keepLast()->run($records);

        // 16 is more than 10 seconds from the group's first record (0), so it opens a new group.
        $this->assertSame([1, 3], $this->survivorIds($first));
        $this->assertSame([2, 3], $this->survivorIds($last));
        $this->assertSame('2', $last->audit[0]->survivorId);
    }

    #[Test]
    public function it_falls_through_to_a_later_key_when_the_first_match_is_outside_the_window(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->key('phone')
            ->timestamp('created_at')
            ->window(600)
            ->run([
                $this->lead(1, 'a@example.com', '111', 0),
                $this->lead(2, 'b@example.com', '222', 500),
                $this->lead(3, 'a@example.com', '222', 900),
            ]);

        $this->assertSame([1, 2], $this->survivorIds($result));
        $this->assertSame('3', $result->audit[0]->droppedId);
        $this->assertSame('2', $result->audit[0]->survivorId);
        $this->assertSame('phone', $result->audit[0]->matchedKey);
    }

    #[Test]
    public function it_converts_minutes_to_a_window_in_seconds(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->timestamp('created_at')
            ->windowMinutes(10)
            ->run([
                $this->lead(1, 'jane@example.com', '', 0),
                $this->lead(2, 'jane@example.com', '', 540),
            ]);

        $this->assertSame(600, $result->audit[0]->windowSeconds);
    }

    #[Test]
    public function it_can_read_timestamps_from_dates_and_date_strings(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->timestamp('created_at')
            ->window(300)
            ->run([
                ['id' => 1, 'email' => 'jane@example.com', 'created_at' => '2026-11-05T10:00:00+00:00'],
                ['id' => 2, 'email' => 'jane@example.com', 'created_at' => new DateTimeImmutable('2026-11-05T10:04:00+00:00')],
                ['id' => 3, 'email' => 'jane@example.com', 'created_at' => (string) strtotime('2026-11-05T10:20:00+00:00')],
            ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
        $this->assertSame(240, $result->audit[0]->secondsApart);
        $this->assertSame('2026-11-05 10:04:00', $result->audit[0]->droppedRecordAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_records_the_survivor_even_when_last_wins_replaces_it_again(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->id('id')
            ->keepLast()
            ->run([
                $this->lead(1, 'jane@example.com'),
                $this->lead(2, 'jane@example.com'),
                $this->lead(3, 'jane@example.com'),
            ]);

        $this->assertSame([3], $this->survivorIds($result));

        $first = $result->why('1');
        $second = $result->why('2');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        // Record 1 lost to 2 directly, but 3 is who finally survives.
        $this->assertSame('2', $first->matchedId);
        $this->assertSame('3', $first->survivorId);
        $this->assertSame('3', $second->matchedId);
        $this->assertSame('3', $second->survivorId);
    }

    #[Test]
    public function it_matches_later_records_against_the_replacement_under_last_wins(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->key('phone')
            ->keepLast()
            ->run([
                $this->lead(1, 'jane@example.com', '111'),
                $this->lead(2, 'jane@example.com', '222'),
                $this->lead(3, 'bob@example.com', '222'),
                $this->lead(4, 'carol@example.com', '111'),
            ]);

        // 2 replaced 1, then 3 replaced 2 on phone. 1's phone no longer belongs to anyone, so 4 stands alone.
        $this->assertSame([3, 4], $this->survivorIds($result));
        $this->assertSame('phone', $result->why('2')?->matchedKey);
    }

    #[Test]
    public function it_does_not_chain_through_dropped_records(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->key('phone')
            ->keepFirst()
            ->run([
                $this->lead(1, 'jane@example.com', '111'),
                $this->lead(2, 'jane@example.com', '222'),
                $this->lead(3, 'bob@example.com', '222'),
            ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
    }

    #[Test]
    public function it_numbers_records_that_have_no_id(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->run([
                ['email' => 'jane@example.com'],
                ['email' => 'jane@example.com'],
            ]);

        $this->assertSame('#2', $result->audit[0]->droppedId);
        $this->assertSame('#1', $result->audit[0]->survivorId);
    }

    #[Test]
    public function it_can_use_another_field_as_the_id(): void
    {
        $result = Deduper::make()
            ->key('email')
            ->id('uuid')
            ->run([
                ['uuid' => 'a-1', 'email' => 'jane@example.com'],
                ['uuid' => 'b-2', 'email' => 'jane@example.com'],
            ]);

        $this->assertSame('b-2', $result->audit[0]->droppedId);
        $this->assertSame('a-1', $result->audit[0]->survivorId);
    }

    #[Test]
    public function it_hashes_the_dropped_payload_without_regard_to_key_order(): void
    {
        $survivor = ['id' => 1, 'email' => 'a@example.com', 'n' => 1];

        $one = Deduper::make()->key('email')->run([$survivor, ['id' => 2, 'email' => 'a@example.com', 'n' => 5]]);
        $two = Deduper::make()->key('email')->run([$survivor, ['n' => 5, 'email' => 'a@example.com', 'id' => 2]]);
        $three = Deduper::make()->key('email')->run([$survivor, ['id' => 2, 'email' => 'a@example.com', 'n' => 6]]);

        $this->assertSame(hash('sha256', '{"email":"a@example.com","id":2,"n":5}'), $one->audit[0]->droppedHash);
        $this->assertSame($one->audit[0]->droppedHash, $two->audit[0]->droppedHash);
        $this->assertNotSame($one->audit[0]->droppedHash, $three->audit[0]->droppedHash);
    }

    #[Test]
    public function it_accepts_arrayaccess_objects_and_plain_objects_and_returns_them_untouched(): void
    {
        $first = new ArrayObject(['id' => 1, 'email' => 'jane@example.com']);
        $second = new stdClass;
        $second->id = 2;
        $second->email = 'jane@example.com';

        $result = Deduper::make()->key('email')->run([$first, $second]);

        $this->assertCount(1, $result->survivors);
        $this->assertSame($first, $result->survivors[0]);
        $this->assertSame('2', $result->audit[0]->droppedId);
    }

    #[Test]
    public function it_can_read_a_generator(): void
    {
        $generator = (function (): iterable {
            yield $this->lead(1, 'jane@example.com');
            yield $this->lead(2, 'jane@example.com');
        })();

        $result = Deduper::make()->key('email')->run($generator);

        $this->assertSame(2, $result->total);
        $this->assertSame([1], $this->survivorIds($result));
    }

    #[Test]
    public function it_handles_empty_input(): void
    {
        $result = Deduper::make()->key('email')->run([]);

        $this->assertSame(0, $result->total);
        $this->assertSame([], $result->survivors);
        $this->assertSame([], $result->audit);
    }

    #[Test]
    public function it_stamps_every_entry_with_the_run_id_and_the_clock(): void
    {
        $now = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));

        $deduper = Deduper::make()->key('email')->clock(fn (): DateTimeImmutable => $now);
        $records = [$this->lead(1, 'a@example.com'), $this->lead(2, 'a@example.com'), $this->lead(3, 'a@example.com')];

        $named = $deduper->run($records, 'nightly-import');
        $anonymous = $deduper->run($records);

        $this->assertSame('nightly-import', $named->runId);
        $this->assertSame('nightly-import', $named->audit[1]->runId);
        $this->assertEquals($now, $named->audit[0]->dedupedAt);
        $this->assertEquals($now, $named->audit[1]->dedupedAt);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $anonymous->runId);
        $this->assertNotSame($named->runId, $anonymous->runId);
    }

    #[Test]
    public function it_hands_the_audit_entries_to_the_store(): void
    {
        $store = new InMemoryAuditStore;

        $result = Deduper::make()
            ->key('email')
            ->auditTo($store)
            ->run([$this->lead(1, 'a@example.com'), $this->lead(2, 'a@example.com')], 'run-a');

        $this->assertSame($result->audit, $store->forRun('run-a'));
        $this->assertSame([], $store->forRun('run-b'));
        $this->assertCount(1, $store->forDroppedRecord('2'));
    }

    #[Test]
    public function it_keeps_no_state_between_runs(): void
    {
        $deduper = Deduper::make()->key('email');

        $first = $deduper->run([$this->lead(1, 'a@example.com'), $this->lead(2, 'a@example.com')]);
        // If the first run's survivors leaked into this one, record 3 would be dropped as a duplicate of 1.
        $second = $deduper->run([$this->lead(3, 'a@example.com')]);

        $this->assertSame(1, $first->droppedCount());
        $this->assertSame(0, $second->droppedCount());
        $this->assertSame([3], $this->survivorIds($second));
    }

    #[Test]
    public function it_refuses_to_run_without_a_match_key(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('At least one match key is required');

        Deduper::make()->run([$this->lead(1, 'a@example.com')]);
    }

    #[Test]
    public function it_refuses_two_keys_with_the_same_name(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[email]');

        Deduper::make()->key('email')->key('contact_email', null, 'email');
    }

    #[Test]
    public function it_refuses_a_negative_window(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[-1]');

        Deduper::make()->window(-1);
    }

    #[Test]
    public function it_refuses_a_window_without_a_timestamp_field(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('needs a timestamp field');

        Deduper::make()->key('email')->window(60)->run([$this->lead(1, 'a@example.com')]);
    }

    #[Test]
    public function it_refuses_a_record_with_no_readable_timestamp_when_a_window_is_set(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [7] has no readable timestamp in [created_at]');

        Deduper::make()->key('email')->timestamp('created_at')->window(60)->run([
            ['id' => 7, 'email' => 'a@example.com', 'created_at' => '%%%'],
        ]);
    }

    #[Test]
    public function it_ignores_missing_timestamps_when_there_is_no_window(): void
    {
        $result = Deduper::make()->key('email')->timestamp('created_at')->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => '  '],
            ['id' => 2, 'email' => 'a@example.com'],
            ['id' => 3, 'email' => 'a@example.com', 'created_at' => null],
        ]);

        $this->assertSame([1], $this->survivorIds($result));
        $this->assertNull($result->audit[0]->secondsApart);
        $this->assertNull($result->audit[0]->droppedRecordAt);
    }

    #[Test]
    public function it_refuses_an_unreadable_timestamp_even_without_a_window(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [1] has no readable timestamp in [created_at]');

        Deduper::make()->key('email')->timestamp('created_at')->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => '%%%'],
        ]);
    }

    #[Test]
    public function it_says_a_timestamp_is_missing_when_a_window_needs_it(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [1] has no timestamp in [created_at], which the window needs');

        Deduper::make()->key('email')->timestamp('created_at')->window(60)->run([
            ['id' => 1, 'email' => 'a@example.com'],
        ]);
    }

    #[Test]
    public function it_keeps_a_shared_value_indexed_for_every_group_that_holds_it(): void
    {
        // (b,1) shares phone 1 with (a,1). When (a,3) replaces (a,1), phone 1
        // must stay matched to (b,1), so (c,1) is its duplicate.
        $result = Deduper::make()->key('email')->key('phone')->keepLast()->run([
            ['id' => 1, 'email' => 'a', 'phone' => '1'],
            ['id' => 2, 'email' => 'b', 'phone' => '2'],
            ['id' => 3, 'email' => 'b', 'phone' => '1'],
            ['id' => 4, 'email' => 'a', 'phone' => '3'],
            ['id' => 5, 'email' => 'c', 'phone' => '1'],
        ]);

        $three = $result->why(3);

        $this->assertSame([4, 5], $this->survivorIds($result));
        $this->assertNotNull($three);
        $this->assertSame('phone', $three->matchedKey);
        $this->assertSame('5', $three->matchedId);
    }

    #[Test]
    public function it_finds_an_earlier_group_for_out_of_order_input_inside_its_window(): void
    {
        $result = Deduper::make()->key('email')->timestamp('t')->window(10)->run([
            ['id' => 1, 'email' => 'x', 't' => 0],
            ['id' => 2, 'email' => 'x', 't' => 20],
            ['id' => 3, 'email' => 'x', 't' => 5],
        ]);

        $this->assertSame([1, 2], $this->survivorIds($result));
        $this->assertSame('1', $result->why(3)?->survivorId);
    }

    #[Test]
    public function it_prefers_the_oldest_group_when_several_hold_a_value_inside_the_window(): void
    {
        $result = Deduper::make()->key('email')->timestamp('t')->window(10)->run([
            ['id' => 1, 'email' => 'x', 't' => 0],
            ['id' => 2, 'email' => 'x', 't' => 15],
            ['id' => 3, 'email' => 'x', 't' => 8],
        ]);

        $this->assertSame('1', $result->why(3)?->survivorId);
    }

    #[Test]
    public function it_reads_signed_large_and_fractional_timestamps_exactly(): void
    {
        $reader = new RecordReader;

        $this->assertSame(5, $reader->timestamp(['t' => '+5'], 't', '1'));
        $this->assertSame(-5, $reader->timestamp(['t' => ' -5 '], 't', '1'));
        $this->assertSame(RecordReader::LATEST, $reader->timestamp(['t' => (string) RecordReader::LATEST], 't', '1'));
        $this->assertSame(RecordReader::EARLIEST, $reader->timestamp(['t' => RecordReader::EARLIEST], 't', '1'));
        $this->assertSame(-2, $reader->timestamp(['t' => '-1.5'], 't', '1'));
        $this->assertSame(1, $reader->timestamp(['t' => 1.9], 't', '1'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableTimestamps(): array
    {
        return [
            'NAN' => [NAN],
            'INF' => [INF],
            'too big' => ['99999999999999999999'],
            'garbage' => ['%%%'],
            'February 30th' => ['2026-02-30'],
            'hour 25' => ['2026-11-05 25:00:00'],
            'array' => [['2026-11-05']],
            'bool' => [true],
        ];
    }

    #[Test]
    #[DataProvider('unreadableTimestamps')]
    public function it_refuses_timestamps_it_cannot_read(mixed $value): void
    {
        $this->expectException(InvalidRecord::class);

        (new RecordReader)->timestamp(['t' => $value], 't', '1');
    }

    #[Test]
    public function it_numbers_a_record_whose_stringable_id_is_empty(): void
    {
        $id = new class implements Stringable
        {
            public function __toString(): string
            {
                return '';
            }
        };

        $result = Deduper::make()->key('email')->run([
            ['id' => 1, 'email' => 'a@example.com'],
            ['id' => $id, 'email' => 'a@example.com'],
        ]);

        $this->assertSame('#2', $result->audit[0]->droppedId);
    }

    #[Test]
    public function it_keeps_objects_and_lists_apart_in_the_payload_hash(): void
    {
        $reader = new RecordReader;

        $this->assertNotSame($reader->hash(['tags' => ['a', 'b']], '1'), $reader->hash(['tags' => (object) ['a', 'b']], '1'));
        $this->assertNotSame($reader->hash(['tags' => []], '1'), $reader->hash(['tags' => new stdClass], '1'));
        $this->assertSame($reader->hash(['b' => 1, 'a' => ['y' => 1, 'x' => 2]], '1'), $reader->hash(['a' => ['x' => 2, 'y' => 1], 'b' => 1], '1'));
        $this->assertSame($reader->hash([2 => 'a', 1 => 'b'], '1'), $reader->hash([1 => 'b', 2 => 'a'], '1'));
    }

    #[Test]
    public function it_stamps_entries_to_the_whole_second(): void
    {
        $result = Deduper::make()->key('email')
            ->clock(fn () => new DateTimeImmutable('2026-11-05 12:00:00.987654', new DateTimeZone('UTC')))
            ->run([$this->lead(1, 'a@example.com'), $this->lead(2, 'a@example.com')]);

        $this->assertSame('2026-11-05 12:00:00.000000', $result->audit[0]->dedupedAt->format('Y-m-d H:i:s.u'));
    }

    #[Test]
    public function it_refuses_a_record_that_is_not_an_array_or_object(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record #2 is a [string]');

        Deduper::make()->key('email')->run([$this->lead(1, 'a@example.com'), 'jane@example.com']);
    }

    #[Test]
    public function it_refuses_a_dropped_record_it_cannot_hash(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [2] cannot be encoded to JSON');

        // NAN has no JSON form, so this payload cannot be hashed.
        Deduper::make()->key('email')->run([
            $this->lead(1, 'a@example.com'),
            ['id' => 2, 'email' => 'a@example.com', 'score' => NAN],
        ]);
    }

    #[Test]
    public function it_reads_a_signed_numeric_string_as_unix_seconds(): void
    {
        $result = Deduper::make()->key('email')->timestamp('created_at')->window(60)->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => '-30'],
            ['id' => 2, 'email' => 'a@example.com', 'created_at' => '20'],
            ['id' => 3, 'email' => 'a@example.com', 'created_at' => '40.9'],
        ]);

        $this->assertSame([1, 3], $this->survivorIds($result));
        $this->assertSame(50, $result->why(2)?->secondsApart);
    }

    #[Test]
    public function it_numbers_records_whose_id_is_null_or_empty(): void
    {
        $result = Deduper::make()->key('email')->run([
            ['id' => 1, 'email' => 'a@example.com'],
            ['id' => null, 'email' => 'a@example.com'],
            ['id' => '', 'email' => 'a@example.com'],
        ]);

        $this->assertSame(['#2', '#3'], array_map(fn ($entry) => $entry->droppedId, $result->audit));
    }

    #[Test]
    public function it_refuses_an_id_that_is_not_an_integer_or_a_string(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record #2 has a [float] in its id field [id]');

        Deduper::make()->key('email')->run([
            ['id' => 1, 'email' => 'a@example.com'],
            ['id' => 2.5, 'email' => 'b@example.com'],
        ]);
    }

    #[Test]
    public function it_finds_a_dropped_record_by_an_integer_id(): void
    {
        $result = Deduper::make()->key('email')->run([$this->lead(1, 'a@example.com'), $this->lead(2, 'a@example.com')]);

        $this->assertSame('2', $result->why(2)?->droppedId);
        $this->assertNull($result->why(1));
    }

    #[Test]
    public function it_refuses_a_composite_key_given_a_list_of_fields(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The match key [name_and_zip] must list at least one field');

        // @phpstan-ignore argument.type (a list instead of field => normalizer is the mistake under test)
        Deduper::make()->compositeKey('name_and_zip', ['last_name', 'zip']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function timestampsOutsideTheStorableYears(): array
    {
        return [
            'PHP_INT_MAX' => [PHP_INT_MAX],
            'PHP_INT_MIN' => [PHP_INT_MIN],
            'one second after 9999' => [RecordReader::LATEST + 1],
            'one second before 1753' => [RecordReader::EARLIEST - 1],
            'a numeric string' => [(string) (RecordReader::LATEST + 1)],
            'a date' => [new DateTimeImmutable('+10000 years')],
        ];
    }

    #[Test]
    #[DataProvider('timestampsOutsideTheStorableYears')]
    public function it_refuses_a_timestamp_the_audit_trail_cannot_store(mixed $value): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [1] has a timestamp in [t] outside the years 1753 to 9999');

        Deduper::make()->key('email')->timestamp('t')->run([['id' => 1, 'email' => 'x', 't' => $value]]);
    }

    #[Test]
    public function it_measures_the_widest_storable_gap(): void
    {
        $result = Deduper::make()->key('email')->timestamp('t')->run([
            ['id' => 1, 'email' => 'x', 't' => RecordReader::EARLIEST],
            ['id' => 2, 'email' => 'x', 't' => RecordReader::LATEST],
        ]);

        $this->assertSame(RecordReader::LATEST - RecordReader::EARLIEST, $result->why(2)?->secondsApart);
    }

    #[Test]
    public function it_refuses_to_hash_an_object_whose_content_it_cannot_see(): void
    {
        $opaque = new class(['id' => 2, 'email' => 'x']) implements ArrayAccess
        {
            /** @param array<string, mixed> $data */
            public function __construct(private array $data)
            {
                //
            }

            public function offsetExists(mixed $offset): bool
            {
                return isset($this->data[$offset]);
            }

            public function offsetGet(mixed $offset): mixed
            {
                return $this->data[$offset];
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
                //
            }

            public function offsetUnset(mixed $offset): void
            {
                //
            }
        };

        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [2] cannot be encoded to JSON');

        Deduper::make()->key('email')->run([['id' => 1, 'email' => 'x'], $opaque]);
    }

    #[Test]
    public function it_hashes_traversable_records_by_their_contents(): void
    {
        $reader = new RecordReader;

        $this->assertSame($reader->hash(['id' => 2, 'email' => 'x'], '2'), $reader->hash(new ArrayObject(['email' => 'x', 'id' => 2]), '2'));
        $this->assertNotSame($reader->hash(new ArrayObject(['id' => 2, 'email' => 'x']), '2'), $reader->hash(new ArrayObject(['id' => 2, 'email' => 'y']), '2'));
    }

    #[Test]
    public function it_lets_the_newest_record_replace_every_group_it_matches_under_last_wins(): void
    {
        $result = Deduper::make()->key('email')->key('phone')->keepLast()->run([
            ['id' => 1, 'email' => 'a', 'phone' => '1'],
            ['id' => 2, 'email' => 'b', 'phone' => '2'],
            ['id' => 3, 'email' => 'a', 'phone' => '2'],
        ]);

        $this->assertSame([3], $this->survivorIds($result));
        $this->assertSame(['1' => 'email', '2' => 'phone'], array_column(array_map(fn ($e) => ['id' => $e->droppedId, 'key' => $e->matchedKey], $result->audit), 'key', 'id'));

        foreach ($result->audit as $entry) {
            $this->assertSame('3', $entry->matchedId);
            $this->assertSame('3', $entry->survivorId);
        }
    }

    #[Test]
    public function it_points_earlier_drops_at_the_record_that_finally_survived_a_merge(): void
    {
        $result = Deduper::make()->key('email')->key('phone')->keepLast()->run([
            ['id' => 1, 'email' => 'a', 'phone' => '1'],
            ['id' => 2, 'email' => 'b', 'phone' => '2'],
            ['id' => 3, 'email' => 'b', 'phone' => '3'],
            ['id' => 4, 'email' => 'a', 'phone' => '3'],
        ]);

        $this->assertSame([4], $this->survivorIds($result));
        $two = $result->why(2);

        $this->assertNotNull($two);
        $this->assertSame('3', $two->matchedId);
        $this->assertSame('4', $two->survivorId);
        $this->assertSame('4', $result->why(3)?->survivorId);
        $this->assertSame('4', $result->why(1)?->survivorId);
    }

    #[Test]
    public function it_drops_the_newcomer_once_under_first_wins_however_many_groups_it_matches(): void
    {
        $result = Deduper::make()->key('email')->key('phone')->run([
            ['id' => 1, 'email' => 'a', 'phone' => '1'],
            ['id' => 2, 'email' => 'b', 'phone' => '2'],
            ['id' => 3, 'email' => 'a', 'phone' => '2'],
        ]);

        $this->assertSame([1, 2], $this->survivorIds($result));
        $this->assertSame(1, $result->droppedCount());
        $this->assertSame('1', $result->why(3)?->survivorId);
    }

    #[Test]
    public function it_refuses_a_window_in_minutes_that_does_not_fit_in_seconds(): void
    {
        $this->expectException(InvalidConfiguration::class);

        Deduper::make()->windowMinutes(intdiv(PHP_INT_MAX, 60) + 1);
    }

    #[Test]
    public function it_refuses_a_negative_window_in_minutes(): void
    {
        $this->expectException(InvalidConfiguration::class);

        Deduper::make()->windowMinutes(-1);
    }

    #[Test]
    public function it_refuses_to_hash_invalid_utf8_rather_than_blur_it(): void
    {
        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('invalid UTF-8');

        (new RecordReader)->hash(['id' => 1, 'note' => "\x80"], '1');
    }

    #[Test]
    public function it_keeps_composite_values_with_invalid_utf8_byte_for_byte(): void
    {
        $key = MatchKey::composite('pair', ['a' => null, 'b' => null]);
        $reader = new RecordReader;

        $this->assertNotSame($key->valueFor(['a' => "\x80", 'b' => 'x'], $reader), $key->valueFor(['a' => "\x81", 'b' => 'x'], $reader));
        $this->assertSame($key->valueFor(['a' => "\x80", 'b' => 'x'], $reader), $key->valueFor(['a' => "\x80", 'b' => 'x'], $reader));
        $this->assertSame('["doe","78701"]', $key->valueFor(['a' => 'doe', 'b' => '78701'], $reader));
    }

    #[Test]
    public function it_refuses_to_hash_an_object_read_through_magic_getters(): void
    {
        $magic = new class
        {
            /** @var array<string, mixed> */
            private array $data = ['id' => 2, 'email' => 'x'];

            public function __isset(string $name): bool
            {
                return isset($this->data[$name]);
            }

            public function __get(string $name): mixed
            {
                return $this->data[$name];
            }
        };

        $this->expectException(InvalidRecord::class);
        $this->expectExceptionMessage('Record [2] cannot be encoded to JSON');

        Deduper::make()->key('email')->run([['id' => 1, 'email' => 'x'], $magic]);
    }

    #[Test]
    public function it_keeps_the_window_of_the_group_that_opened_first_when_groups_merge(): void
    {
        // The third record matches the newer group on email first, then the older one on phone.
        // The merged group must still be measured from t=0, so t=20 starts a new group.
        $result = Deduper::make()->key('email')->key('phone')->timestamp('t')->window(10)->keepLast()->run([
            ['id' => 1, 'email' => 'old', 'phone' => 'shared', 't' => 0],
            ['id' => 2, 'email' => 'new', 'phone' => 'other', 't' => 15],
            ['id' => 3, 'email' => 'new', 'phone' => 'shared', 't' => 8],
            ['id' => 4, 'email' => 'new', 'phone' => 'x', 't' => 20],
        ]);

        $this->assertSame([3, 4], $this->survivorIds($result));
        $this->assertSame('email', $result->why(2)?->matchedKey);
        $this->assertSame('phone', $result->why(1)?->matchedKey);
    }

    #[Test]
    public function it_refuses_an_empty_id_field_name(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The id field name cannot be empty');

        Deduper::make()->id('');
    }

    #[Test]
    public function it_refuses_an_empty_timestamp_field_name(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('Pass null for no timestamp');

        Deduper::make()->timestamp('');
    }

    #[Test]
    public function it_refuses_an_empty_run_id(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('A run id cannot be empty');

        Deduper::make()->key('email')->run([$this->lead(1, 'a@example.com')], '');
    }

    #[Test]
    public function it_keeps_values_that_php_would_read_as_the_same_number_apart(): void
    {
        foreach ([false, true] as $last) {
            $deduper = Deduper::make()->key('ref');

            if ($last) {
                $deduper->keepLast();
            }

            $result = $deduper->run([
                ['id' => 1, 'ref' => '0'],
                ['id' => 2, 'ref' => '-0'],
                ['id' => 3, 'ref' => '00'],
                ['id' => 4, 'ref' => '1'],
                ['id' => 5, 'ref' => '01'],
                ['id' => 6, 'ref' => '1.0'],
                ['id' => 7, 'ref' => 1],
            ]);

            $this->assertSame($last ? [1, 2, 3, 5, 6, 7] : [1, 2, 3, 4, 5, 6], $this->survivorIds($result));
            $this->assertSame([$last ? '4' : '7'], array_map(fn ($entry) => $entry->droppedId, $result->audit));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lead(int $id, string $email, string $phone = '', int $createdAt = 0): array
    {
        return ['id' => $id, 'email' => $email, 'phone' => $phone, 'created_at' => $createdAt];
    }

    /**
     * @return list<mixed>
     */
    private function survivorIds(DedupeResult $result): array
    {
        return array_map(
            fn (mixed $record): mixed => is_array($record) ? $record['id'] : null,
            $result->survivors,
        );
    }
}
