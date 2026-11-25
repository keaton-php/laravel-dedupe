<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Feature;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Dedupe;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Facades\Dedupe as DedupeFacade;
use Keaton\Dedupe\Normalizers\PhoneDigits;
use Keaton\Dedupe\Tests\Fixtures\StripLeadingZeros;
use Keaton\Dedupe\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_merges_the_default_config_and_runs_the_migration(): void
    {
        $this->assertSame('first', config('dedupe.survivorship'));
        $this->assertSame('dedupe_audit_entries', config('dedupe.audit.table'));
        $this->assertTrue(Schema::hasColumns('dedupe_audit_entries', [
            'run_id', 'dropped_id', 'dropped_hash', 'survivor_id', 'matched_id', 'matched_key',
            'matched_value', 'dropped_record_at', 'seconds_apart', 'survivorship', 'window_seconds', 'deduped_at',
        ]));
    }

    #[Test]
    public function it_resolves_the_configured_entry_point_from_the_container_and_facade(): void
    {
        $this->assertInstanceOf(Dedupe::class, app(Dedupe::class));
        $this->assertSame(2, DedupeFacade::run($this->leads())->droppedCount());
    }

    #[Test]
    public function it_persists_which_survivor_and_which_key_each_dropped_record_matched(): void
    {
        $result = DedupeFacade::run($this->leads(), 'run-1');

        $this->assertSame(2, $result->droppedCount());

        $this->assertDatabaseHas('dedupe_audit_entries', [
            'run_id' => 'run-1',
            'dropped_id' => '102',
            'survivor_id' => '101',
            'matched_id' => '101',
            'matched_key' => 'email',
            'survivorship' => 'first',
        ]);
        $this->assertDatabaseHas('dedupe_audit_entries', [
            'run_id' => 'run-1',
            'dropped_id' => '103',
            'survivor_id' => '101',
            'matched_key' => 'phone',
        ]);
        $this->assertDatabaseCount('dedupe_audit_entries', 2);
    }

    #[Test]
    public function it_reads_the_persisted_trail_back_by_run_and_by_record(): void
    {
        $result = DedupeFacade::run($this->leads(), 'run-1');

        $byRun = DedupeFacade::audit('run-1');
        $byRecord = DedupeFacade::auditFor(103);

        $this->assertSame(['102', '103'], array_map(fn (AuditEntry $entry): string => $entry->droppedId, $byRun));
        $this->assertCount(1, $byRecord);
        $this->assertSame('phone', $byRecord[0]->matchedKey);
        $this->assertSame($result->audit[1]->droppedHash, $byRecord[0]->droppedHash);
    }

    #[Test]
    public function it_stores_hashes_and_ids_but_not_the_shared_value_by_default(): void
    {
        $result = DedupeFacade::run($this->leads(), 'run-1');

        $this->assertSame('jane@gmail.com', $result->audit[0]->matchedValue);
        $this->assertNull(DedupeFacade::audit('run-1')[0]->matchedValue);
        $this->assertSame(0, DB::table('dedupe_audit_entries')->whereNotNull('matched_value')->count());
    }

    #[Test]
    public function it_can_store_the_shared_value_when_configured_to(): void
    {
        config()->set('dedupe.audit.store_values', true);

        DedupeFacade::run($this->leads(), 'run-1');

        $this->assertSame('jane@gmail.com', DedupeFacade::audit('run-1')[0]->matchedValue);
    }

    #[Test]
    public function it_persists_the_window_and_the_gap_between_records(): void
    {
        config()->set('dedupe.window', '3600');

        DedupeFacade::run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => '2026-11-05 10:00:00'],
            ['id' => 2, 'email' => 'a@example.com', 'created_at' => '2026-11-05 10:20:00'],
            ['id' => 3, 'email' => 'a@example.com', 'created_at' => '2026-11-05 13:00:00'],
        ], 'run-1');

        $row = DB::table('dedupe_audit_entries')->where('run_id', 'run-1')->first();

        $this->assertNotNull($row);
        $this->assertSame('2', $row->dropped_id);
        $this->assertSame(3600, (int) $row->window_seconds);
        $this->assertSame(1200, (int) $row->seconds_apart);
        $this->assertDatabaseCount('dedupe_audit_entries', 1);
    }

    #[Test]
    public function it_does_not_write_a_trail_when_the_audit_is_switched_off(): void
    {
        config()->set('dedupe.audit.enabled', false);

        $result = DedupeFacade::run($this->leads(), 'run-1');

        $this->assertSame(2, $result->droppedCount());
        $this->assertDatabaseCount('dedupe_audit_entries', 0);

        $this->expectException(InvalidConfiguration::class);
        DedupeFacade::audit('run-1');
    }

    #[Test]
    public function it_resolves_normalizers_named_in_the_config_through_the_container(): void
    {
        app()->bind(PhoneDigits::class, fn (): PhoneDigits => new PhoneDigits(10));
        config()->set('dedupe.keys', [
            'phone' => ['phone' => PhoneDigits::class],
            'order' => ['ref' => StripLeadingZeros::class],
        ]);

        $result = DedupeFacade::run([
            ['id' => 1, 'phone' => '+1 512 555 0100', 'ref' => '00042'],
            ['id' => 2, 'phone' => '512-555-0100', 'ref' => '7'],
            ['id' => 3, 'phone' => '999', 'ref' => '42'],
        ]);

        // 2 only matches 1 because the container handed back the ten-digit PhoneDigits.
        $this->assertSame('phone', $result->why('2')?->matchedKey);
        $this->assertSame('order', $result->why('3')?->matchedKey);
    }

    #[Test]
    public function it_can_prune_the_persisted_trail_by_age(): void
    {
        DedupeFacade::deduper()
            ->clock(fn (): DateTimeImmutable => new DateTimeImmutable('-40 days'))
            ->run($this->leads(), 'old');
        DedupeFacade::run($this->leads(), 'new');

        $this->assertSame(2, DedupeFacade::prune(30));
        $this->assertSame([], DedupeFacade::audit('old'));
        $this->assertCount(2, DedupeFacade::audit('new'));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function auditSwitches(): array
    {
        return [
            'false' => [false, false],
            'the string false' => ['false', false],
            'the string 0' => ['0', false],
            'off' => ['off', false],
            'true' => [true, true],
            'the string 1' => ['1', true],
        ];
    }

    #[Test]
    #[DataProvider('auditSwitches')]
    public function it_reads_the_audit_switch_as_a_boolean(mixed $value, bool $enabled): void
    {
        config()->set('dedupe.audit.enabled', $value);

        DedupeFacade::run($this->leads(), 'run-1');

        $this->assertSame($enabled ? 2 : 0, DB::table('dedupe_audit_entries')->count());
    }

    #[Test]
    public function it_refuses_an_audit_switch_it_cannot_read(): void
    {
        config()->set('dedupe.audit.enabled', 'sometimes');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[dedupe.audit.enabled]');

        app(Dedupe::class);
    }

    #[Test]
    public function it_refuses_an_empty_table_name(): void
    {
        config()->set('dedupe.audit.table', '');

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[dedupe.audit.table]');

        app(Dedupe::class);
    }

    #[Test]
    public function it_runs_the_migration_on_the_configured_audit_connection(): void
    {
        config()->set('dedupe.audit.connection', 'audit');
        config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        $migration = require __DIR__.'/../../database/migrations/2026_11_05_000000_create_dedupe_audit_entries_table.php';

        $this->assertSame('audit', $migration->getConnection());

        $migration->up();

        $this->assertTrue(Schema::connection('audit')->hasTable('dedupe_audit_entries'));

        $migration->down();

        $this->assertFalse(Schema::connection('audit')->hasTable('dedupe_audit_entries'));
        $this->assertTrue(Schema::hasTable('dedupe_audit_entries'), 'the default connection was left alone');
    }

    #[Test]
    public function it_fails_loudly_rather_than_adopting_a_table_it_did_not_create(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_05_000000_create_dedupe_audit_entries_table.php';

        // The table already exists (RefreshDatabase ran the migration).
        $this->expectException(QueryException::class);

        $migration->up();
    }

    #[Test]
    public function it_runs_a_published_copy_and_the_auto_loaded_migration_once(): void
    {
        $published = sys_get_temp_dir().'/dedupe-published-'.bin2hex(random_bytes(4));
        mkdir($published);
        copy(__DIR__.'/../../database/migrations/2026_11_05_000000_create_dedupe_audit_entries_table.php', $published.'/2026_11_05_000000_create_dedupe_audit_entries_table.php');

        try {
            $files = app('migrator')->getMigrationFiles([__DIR__.'/../../database/migrations', $published]);
        } finally {
            unlink($published.'/2026_11_05_000000_create_dedupe_audit_entries_table.php');
            rmdir($published);
        }

        $this->assertSame(['2026_11_05_000000_create_dedupe_audit_entries_table'], array_keys($files));
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function unusableMigrationSettings(): array
    {
        return [
            'an empty table name' => ['dedupe.audit.table', ''],
            'a table name that is not a string' => ['dedupe.audit.table', ['audit']],
            'a connection that is not a string' => ['dedupe.audit.connection', ['audit']],
        ];
    }

    #[Test]
    #[DataProvider('unusableMigrationSettings')]
    public function it_refuses_to_migrate_with_settings_the_store_would_refuse(string $key, mixed $value): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_11_05_000000_create_dedupe_audit_entries_table.php';
        config()->set($key, $value);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage("[{$key}]");

        $migration->getConnection();
        $migration->up();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function leads(): array
    {
        return [
            ['id' => 101, 'email' => 'jane@gmail.com', 'phone' => '+1 (512) 555-0100'],
            ['id' => 102, 'email' => 'j.a.n.e+promo@gmail.com', 'phone' => '999 111 2222'],
            ['id' => 103, 'email' => 'bob@example.com', 'phone' => '15125550100'],
            ['id' => 104, 'email' => 'carol@example.com', 'phone' => '777 888 9999'],
        ];
    }
}
