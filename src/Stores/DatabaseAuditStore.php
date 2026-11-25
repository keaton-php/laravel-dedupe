<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Stores;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Keaton\Dedupe\AuditEntry;
use Keaton\Dedupe\Contracts\AuditStore;
use Keaton\Dedupe\Exceptions\InvalidAuditEntry;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;

class DatabaseAuditStore implements AuditStore
{
    /**
     * @param  bool  $storeValues  Also keep the normalized value both records shared (an email address, for instance). Off by default: the trail then holds hashes and ids only.
     * @param  positive-int  $chunkSize  Rows per insert. 75 rows of 12 columns stays under SQLite's old 999-variable limit.
     */
    public function __construct(
        protected ConnectionResolverInterface $db,
        protected string $table = 'dedupe_audit_entries',
        protected ?string $connection = null,
        protected bool $storeValues = false,
        protected int $chunkSize = 75,
    ) {
        if ($chunkSize < 1) {
            throw InvalidConfiguration::invalidChunkSize($chunkSize);
        }
    }

    /**
     * The table name from config: a non-empty string.
     */
    public static function tableFrom(mixed $value): string
    {
        return is_string($value) && $value !== ''
            ? $value
            : throw InvalidConfiguration::invalidOption('dedupe.audit.table', is_scalar($value) ? var_export($value, true) : get_debug_type($value));
    }

    /**
     * The connection name from config: null or '' for the default connection.
     */
    public static function connectionFrom(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value)
            ? $value
            : throw InvalidConfiguration::invalidOption('dedupe.audit.connection', is_scalar($value) ? var_export($value, true) : get_debug_type($value));
    }

    public function store(array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $connection = $this->db->connection($this->connection);

        $connection->transaction(function () use ($entries): void {
            foreach (array_chunk($entries, $this->chunkSize) as $chunk) {
                $this->query()->insert(array_map(fn (AuditEntry $entry): array => $this->row($entry), $chunk));
            }
        });
    }

    public function forRun(string $runId): array
    {
        return $this->hydrate($this->query()->where('run_id', $runId));
    }

    public function forDroppedRecord(string $recordId): array
    {
        return $this->hydrate($this->query()->where('dropped_id', $recordId));
    }

    public function prune(DateTimeInterface $before): int
    {
        $cutoff = DateTimeImmutable::createFromInterface($before)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(AuditEntry::DATE_FORMAT);

        return $this->query()->where('deduped_at', '<', $cutoff)->delete();
    }

    protected function query(): Builder
    {
        return $this->db->connection($this->connection)->table($this->table);
    }

    /**
     * @return list<AuditEntry>
     */
    protected function hydrate(Builder $query): array
    {
        $rows = $query->orderBy('id')->get()->all();

        return array_values(array_map(
            fn (object $row): AuditEntry => AuditEntry::fromArray((array) $row),
            $rows,
        ));
    }

    /**
     * The widths of the indexed columns in the package's migration.
     */
    protected const MAX_LENGTHS = [
        'run_id' => 191,
        'dropped_id' => 191,
        'survivor_id' => 191,
        'matched_id' => 191,
        'matched_key' => 128,
    ];

    /**
     * @return array<string, string|int|null>
     */
    protected function row(AuditEntry $entry): array
    {
        $row = $entry->toArray();

        // Refuse rather than let a database truncate an id or a key name.
        foreach (self::MAX_LENGTHS as $column => $max) {
            if (mb_strlen((string) $row[$column]) > $max) {
                throw InvalidAuditEntry::tooLong($column, $max);
            }
        }

        if (! $this->storeValues) {
            $row['matched_value'] = null;
        }

        return $row;
    }
}
