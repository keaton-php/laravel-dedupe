<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Support;

use ArrayAccess;
use DateTimeImmutable;
use DateTimeInterface;
use JsonSerializable;
use Keaton\Dedupe\Exceptions\InvalidRecord;
use stdClass;
use Stringable;
use Traversable;

/**
 * Reads fields from arrays, ArrayAccess objects (Eloquent models) and plain objects.
 */
class RecordReader
{
    // No JSON_INVALID_UTF8_SUBSTITUTE: it would map different invalid bytes to the same
    // character, so different records could share a hash. Invalid UTF-8 cannot be hashed.
    /**
     * 1753-01-01 00:00:00 and 9999-12-31 23:59:59 UTC: the range a datetime
     * column is documented to hold on every database Laravel supports (SQL
     * Server's starts at 1753) and a four-digit year can be written in. The
     * suite exercises it on SQLite only.
     */
    public const EARLIEST = -6_847_804_800;

    public const LATEST = 253_402_300_799;

    protected const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION;

    public function assertSupported(mixed $record, int $position): void
    {
        if (! is_array($record) && ! is_object($record)) {
            throw InvalidRecord::unsupported($position, get_debug_type($record));
        }
    }

    public function get(mixed $record, string $field): mixed
    {
        if (is_array($record)) {
            return $record[$field] ?? null;
        }

        if ($record instanceof ArrayAccess) {
            return $record->offsetExists($field) ? $record->offsetGet($field) : null;
        }

        if (is_object($record)) {
            return isset($record->{$field}) ? $record->{$field} : null;
        }

        return null;
    }

    /**
     * The record's id as a string, or its 1-based stream position ("#3") when it has none.
     */
    public function id(mixed $record, string $field, int $position): string
    {
        $id = $this->get($record, $field);

        if ($id !== null && ! is_int($id) && ! is_string($id) && ! $id instanceof Stringable) {
            throw InvalidRecord::unreadableId($position, $field, get_debug_type($id));
        }

        $id = (string) $id;

        return $id === '' ? "#{$position}" : $id;
    }

    /**
     * Unix seconds from an int, a float, a numeric string, a date string or a
     * DateTimeInterface. Null when the field is missing, null or blank; a value
     * that is there but cannot be read, or falls outside the years 1753 to 9999,
     * throws. Date strings without an offset are read in PHP's default timezone.
     */
    public function timestamp(mixed $record, string $field, string $recordId): ?int
    {
        $value = $this->get($record, $field);

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        $seconds = $this->seconds($value) ?? throw InvalidRecord::unreadableTimestamp($recordId, $field);

        if ($seconds < self::EARLIEST || $seconds > self::LATEST) {
            throw InvalidRecord::timestampOutOfRange($recordId, $field);
        }

        return $seconds;
    }

    protected function seconds(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            // A numeric string is unix seconds, signed or not ("-5" is not a timezone).
            if (preg_match('/^[+-]?\d+$/', $value) === 1) {
                $seconds = filter_var($value, FILTER_VALIDATE_INT);

                return is_int($seconds) ? $seconds : null;
            }

            if (is_numeric($value)) {
                $value = (float) $value;
            }
        }

        if (is_float($value)) {
            $seconds = floor($value);

            return is_finite($seconds) && $seconds >= PHP_INT_MIN && $seconds < PHP_INT_MAX ? (int) $seconds : null;
        }

        if (is_string($value)) {
            $date = date_create_immutable($value);

            // "2026-02-30" parses, with a warning, as March 2nd: refuse it instead.
            return $date === false || DateTimeImmutable::getLastErrors() !== false ? null : $date->getTimestamp();
        }

        return null;
    }

    /**
     * A sha256 of the record's JSON form (what json_encode() sees: array
     * values, public properties, JsonSerializable output, a Traversable's
     * items) with object keys sorted, so field order does not change it.
     * State JSON cannot see, such as private properties, is not part of it.
     * The audit trail stores this instead of the payload.
     */
    public function hash(mixed $record, string $recordId): string
    {
        // Round-trip through JSON so objects, dates and models all reduce to
        // JSON objects and lists, which stay distinct from each other.
        $view = json_decode($this->encode($this->payload($record, $recordId), $recordId));

        return hash('sha256', $this->encode($this->canonical($view), $recordId));
    }

    /**
     * What a record's content is, for hashing. An object that is neither
     * JsonSerializable nor Traversable and has no public properties would
     * encode as {} whatever it holds (the deduper read its fields through
     * ArrayAccess or magic getters), so it is refused rather than hashed
     * like every other such record.
     */
    protected function payload(mixed $record, string $recordId): mixed
    {
        if (! is_object($record) || $record instanceof JsonSerializable) {
            return $record;
        }

        if ($record instanceof Traversable) {
            return iterator_to_array($record);
        }

        if (get_object_vars($record) === []) {
            throw InvalidRecord::unhashable($recordId);
        }

        return $record;
    }

    protected function encode(mixed $value, string $recordId): string
    {
        $json = json_encode($value, self::JSON_FLAGS);

        if ($json === false) {
            throw InvalidRecord::unhashable($recordId);
        }

        return $json;
    }

    /**
     * JSON objects with their keys sorted at every level; lists keep their order.
     */
    protected function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        }

        if (! $value instanceof stdClass) {
            return $value;
        }

        $properties = array_map(fn (mixed $item): mixed => $this->canonical($item), get_object_vars($value));
        ksort($properties, SORT_STRING);

        return (object) $properties;
    }
}
