<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Keaton\Dedupe\Exceptions\InvalidAuditEntry;
use Keaton\Dedupe\Support\RecordReader;

/**
 * One dropped record: what went, which record it lost to, and why.
 */
final readonly class AuditEntry
{
    public const DATE_FORMAT = 'Y-m-d H:i:s';

    public ?DateTimeImmutable $droppedRecordAt;

    public DateTimeImmutable $dedupedAt;

    /**
     * @param  string  $droppedId  Id of the record that was removed ("#3" when it had no id).
     * @param  string  $droppedHash  sha256 of its content. The payload itself is never stored.
     * @param  string  $survivorId  Id of the record that is kept in the end.
     * @param  string  $matchedId  Id of the record it was directly compared with. Differs from $survivorId only under last-wins, when the match was itself replaced later.
     * @param  string  $matchedKey  Name of the match key that tied the two together.
     * @param  string|null  $matchedValue  The normalized value both records share.
     * @param  DateTimeImmutable|null  $droppedRecordAt  The dropped record's own timestamp, to the second.
     * @param  int|null  $secondsApart  Gap between the two records' timestamps.
     * @param  DateTimeImmutable  $dedupedAt  When the run made the decision, to the second.
     */
    public function __construct(
        public string $runId,
        public string $droppedId,
        public string $droppedHash,
        public string $survivorId,
        public string $matchedId,
        public string $matchedKey,
        public ?string $matchedValue,
        ?DateTimeImmutable $droppedRecordAt,
        public ?int $secondsApart,
        public Survivorship $survivorship,
        public ?int $windowSeconds,
        DateTimeImmutable $dedupedAt,
    ) {
        foreach (['runId' => $runId, 'droppedId' => $droppedId, 'droppedHash' => $droppedHash, 'survivorId' => $survivorId, 'matchedId' => $matchedId, 'matchedKey' => $matchedKey] as $field => $value) {
            if ($value === '') {
                throw InvalidAuditEntry::missingField($field);
            }
        }

        foreach (['secondsApart' => $secondsApart, 'windowSeconds' => $windowSeconds] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw InvalidAuditEntry::invalidField($field);
            }
        }

        foreach (['droppedRecordAt' => $droppedRecordAt, 'dedupedAt' => $dedupedAt] as $field => $date) {
            if ($date !== null && ($date->getTimestamp() < RecordReader::EARLIEST || $date->getTimestamp() > RecordReader::LATEST)) {
                throw InvalidAuditEntry::invalidField($field);
            }
        }

        // The audit table stores whole seconds, so an entry reads back exactly as it was made.
        $this->droppedRecordAt = $droppedRecordAt === null ? null : self::wholeSeconds($droppedRecordAt);
        $this->dedupedAt = self::wholeSeconds($dedupedAt);
    }

    protected static function wholeSeconds(DateTimeImmutable $date): DateTimeImmutable
    {
        return $date->setTime((int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s'));
    }

    /**
     * Is this the record that was dropped? Compares the hash of the record's
     * JSON form with the one stored, so the trail can prove it without keeping
     * the data. Field order does not matter; values and their types do.
     */
    public function isFor(mixed $record): bool
    {
        return hash_equals($this->droppedHash, (new RecordReader)->hash($record, $this->droppedId));
    }

    /**
     * Flat snake_case row, dates in UTC, ready for a database insert.
     *
     * @return array<string, string|int|null>
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'dropped_id' => $this->droppedId,
            'dropped_hash' => $this->droppedHash,
            'survivor_id' => $this->survivorId,
            'matched_id' => $this->matchedId,
            'matched_key' => $this->matchedKey,
            'matched_value' => $this->matchedValue,
            'dropped_record_at' => $this->droppedRecordAt === null ? null : self::format($this->droppedRecordAt),
            'seconds_apart' => $this->secondsApart,
            'survivorship' => $this->survivorship->value,
            'window_seconds' => $this->windowSeconds,
            'deduped_at' => self::format($this->dedupedAt),
        ];
    }

    /**
     * @param  array<string, mixed>  $row  The shape toArray() produces.
     *
     * @throws InvalidAuditEntry when a required field is missing
     */
    public static function fromArray(array $row): self
    {
        $survivorship = self::string($row, 'survivorship');

        return new self(
            runId: self::string($row, 'run_id'),
            droppedId: self::string($row, 'dropped_id'),
            droppedHash: self::string($row, 'dropped_hash'),
            survivorId: self::string($row, 'survivor_id'),
            matchedId: self::string($row, 'matched_id'),
            matchedKey: self::string($row, 'matched_key'),
            matchedValue: self::nullableString($row, 'matched_value'),
            droppedRecordAt: self::date($row, 'dropped_record_at'),
            secondsApart: self::count($row, 'seconds_apart'),
            survivorship: Survivorship::tryFrom($survivorship) ?? throw InvalidAuditEntry::unknownSurvivorship($survivorship),
            windowSeconds: self::count($row, 'window_seconds'),
            dedupedAt: self::date($row, 'deduped_at') ?? throw InvalidAuditEntry::missingField('deduped_at'),
        );
    }

    protected static function format(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function string(array $row, string $field): string
    {
        $value = $row[$field] ?? null;

        if (! is_scalar($value) || (string) $value === '') {
            throw InvalidAuditEntry::missingField($field);
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function nullableString(array $row, string $field): ?string
    {
        $value = $row[$field] ?? null;

        if ($value !== null && ! is_scalar($value)) {
            throw InvalidAuditEntry::invalidField($field);
        }

        return $value === null ? null : (string) $value;
    }

    /**
     * A whole number of seconds, zero or more, or null.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function count(array $row, string $field): ?int
    {
        $value = $row[$field] ?? null;

        if ($value === null) {
            return null;
        }

        $count = is_int($value) ? $value : (is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false);

        return is_int($count) && $count >= 0 ? $count : throw InvalidAuditEntry::invalidField($field);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function date(array $row, string $field): ?DateTimeImmutable
    {
        $value = $row[$field] ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $value, new DateTimeZone('UTC')) : false;

        // createFromFormat() rolls "2026-13-45" over into a real date and only warns.
        return $date !== false && DateTimeImmutable::getLastErrors() === false ? $date : throw InvalidAuditEntry::invalidField($field);
    }
}
