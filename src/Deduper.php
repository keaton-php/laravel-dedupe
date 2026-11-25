<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Keaton\Dedupe\Contracts\AuditStore;
use Keaton\Dedupe\Contracts\Normalizer;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Exceptions\InvalidRecord;
use Keaton\Dedupe\Support\Candidate;
use Keaton\Dedupe\Support\Drop;
use Keaton\Dedupe\Support\RecordReader;

/**
 * The framework-free core. Records in, survivors and an audit trail out.
 *
 * How matching works:
 *  - A record is a duplicate when one of its match keys has the same normalized
 *    value as a record that is currently surviving. Keys are tried in the order
 *    they were added; the first one that matches is the one reported.
 *  - Only survivors are compared against. A dropped record never becomes a
 *    bridge, so duplicates are not chained transitively.
 *  - With a window, a group of duplicates opens at its first record and takes in
 *    records within that many seconds of it. Later ones start a new group.
 *
 * @phpstan-consistent-constructor
 */
class Deduper
{
    /** @var list<MatchKey> */
    protected array $keys = [];

    protected ?int $window = null;

    protected Survivorship $survivorship = Survivorship::First;

    protected string $idField = 'id';

    protected ?string $timestampField = null;

    protected ?AuditStore $store = null;

    protected ?Closure $clock = null;

    protected RecordReader $reader;

    public function __construct(?RecordReader $reader = null)
    {
        $this->reader = $reader ?? new RecordReader;
    }

    public static function make(): static
    {
        return new static;
    }

    /**
     * Match on one field. The key is named after the field unless $as says otherwise.
     */
    public function key(string $field, ?Normalizer $normalizer = null, ?string $as = null): static
    {
        return $this->addKey(MatchKey::on($field, $normalizer, $as));
    }

    /**
     * Match only when every listed field matches, e.g. last name and zip code.
     *
     * @param  array<string, Normalizer|null>  $fields  field name => normalizer
     */
    public function compositeKey(string $name, array $fields): static
    {
        return $this->addKey(MatchKey::composite($name, $fields));
    }

    public function addKey(MatchKey $key): static
    {
        foreach ($this->keys as $existing) {
            if ($existing->name === $key->name) {
                throw InvalidConfiguration::duplicateKeyName($key->name);
            }
        }

        $this->keys[] = $key;

        return $this;
    }

    /**
     * Records only collide when their timestamps are within this many seconds. Null means no window.
     */
    public function window(?int $seconds): static
    {
        if ($seconds !== null && $seconds < 0) {
            throw InvalidConfiguration::invalidWindow((string) $seconds);
        }

        $this->window = $seconds;

        return $this;
    }

    public function windowMinutes(int $minutes): static
    {
        if ($minutes < 0 || $minutes > intdiv(PHP_INT_MAX, 60)) {
            throw InvalidConfiguration::invalidWindow("{$minutes} minutes");
        }

        return $this->window($minutes * 60);
    }

    public function keepFirst(): static
    {
        return $this->survivorship(Survivorship::First);
    }

    public function keepLast(): static
    {
        return $this->survivorship(Survivorship::Last);
    }

    public function survivorship(Survivorship $survivorship): static
    {
        $this->survivorship = $survivorship;

        return $this;
    }

    /**
     * The field that identifies a record in the audit trail. Records without one are numbered "#1", "#2"...
     */
    public function id(string $field): static
    {
        if ($field === '') {
            throw InvalidConfiguration::emptyFieldName('id');
        }

        $this->idField = $field;

        return $this;
    }

    /**
     * The field holding when the record happened. Required for a window, optional otherwise.
     */
    public function timestamp(?string $field): static
    {
        if ($field === '') {
            throw InvalidConfiguration::emptyFieldName('timestamp');
        }

        $this->timestampField = $field;

        return $this;
    }

    /**
     * Persist every run's audit entries to this store.
     */
    public function auditTo(?AuditStore $store): static
    {
        $this->store = $store;

        return $this;
    }

    /**
     * Replace "now" for the dedupedAt stamp, mostly for tests.
     *
     * @param  Closure(): DateTimeInterface  $clock
     */
    public function clock(Closure $clock): static
    {
        $this->clock = $clock;

        return $this;
    }

    /**
     * @param  iterable<mixed>  $records  Arrays, ArrayAccess objects (models) or plain objects.
     */
    public function run(iterable $records, ?string $runId = null): DedupeResult
    {
        if ($this->keys === []) {
            throw InvalidConfiguration::noMatchKeys();
        }

        if ($this->window !== null && $this->timestampField === null) {
            throw InvalidConfiguration::windowNeedsTimestamp();
        }

        if ($runId === '') {
            throw InvalidConfiguration::emptyRunId();
        }

        $runId ??= bin2hex(random_bytes(16));
        $decidedAt = $this->now();

        /** @var array<int, Candidate> $slots */
        $slots = [];
        /** @var array<string, array<array-key, list<int>>> $index match key name => value => the live groups holding it */
        $index = [];
        /** @var list<Drop> $drops */
        $drops = [];
        /** @var array<int, int> $mergedInto a slot folded into another under keepLast => that slot */
        $mergedInto = [];
        $nextSlot = 0;
        $position = 0;

        foreach ($records as $record) {
            $position++;

            $this->reader->assertSupported($record, $position);

            $id = $this->reader->id($record, $this->idField, $position);
            $time = $this->timeOf($record, $id);
            $values = $this->valuesOf($record);
            $hits = $this->findMatches($values, $time, $index, $slots);

            if ($hits === []) {
                $slot = $nextSlot++;
                $slots[$slot] = new Candidate($record, $id, $position, $time, $time, $values);
                $this->claim($index, $slot, $values);

                continue;
            }

            // The first hit is the one reported: the first key in declared order, the oldest group.
            [$slot, $key, $value] = $hits[0];

            if ($this->survivorship === Survivorship::First) {
                $survivor = $slots[$slot];
                $apart = $this->apart($time, $survivor);

                $drops[] = new Drop($id, $this->reader->hash($record, $id), $time, $survivor->id, $key, $value, $apart, $slot);

                continue;
            }

            // Last-wins: every survivor the newcomer directly matches loses to it. The groups
            // fold into the one that opened first, which keeps its slot and its window anchor.
            $slot = min(array_column($hits, 0));

            foreach ($hits as [$hitSlot, $hitKey, $hitValue]) {
                $standing = $slots[$hitSlot];

                $drops[] = new Drop($standing->id, $this->reader->hash($standing->record, $standing->id), $standing->time, $id, $hitKey, $hitValue, $this->apart($time, $standing), $slot);

                $this->release($index, $hitSlot, $standing->values);

                if ($hitSlot !== $slot) {
                    unset($slots[$hitSlot]);
                    $mergedInto[$hitSlot] = $slot;
                }
            }

            $survivor = $slots[$slot];
            $survivor->record = $record;
            $survivor->id = $id;
            $survivor->position = $position;
            $survivor->time = $time;
            $survivor->values = $values;

            $this->claim($index, $slot, $values);
        }

        $entries = array_map(
            fn (Drop $drop): AuditEntry => $this->entry($runId, $decidedAt, $drop, $slots[self::finalSlot($drop->slot, $mergedInto)]),
            $drops,
        );

        $this->store?->store($entries);

        usort($slots, fn (Candidate $a, Candidate $b): int => $a->position <=> $b->position);

        return new DedupeResult(
            runId: $runId,
            total: $position,
            survivors: array_map(fn (Candidate $candidate): mixed => $candidate->record, $slots),
            audit: $entries,
        );
    }

    protected function timeOf(mixed $record, string $id): ?int
    {
        if ($this->timestampField === null) {
            return null;
        }

        $time = $this->reader->timestamp($record, $this->timestampField, $id);

        if ($time === null && $this->window !== null) {
            throw InvalidRecord::missingTimestamp($id, $this->timestampField);
        }

        return $time;
    }

    /**
     * @return array<string, string> match key name => normalized value, blanks left out
     */
    protected function valuesOf(mixed $record): array
    {
        $values = [];

        foreach ($this->keys as $key) {
            $value = $key->valueFor($record, $this->reader);

            if ($value !== null) {
                $values[$key->name] = $value;
            }
        }

        return $values;
    }

    /**
     * Every live group, within the window, that shares a value with this
     * record: by key in declared order, then oldest group first. Each group
     * appears once, under the first key that matched it.
     *
     * @param  array<string, string>  $values
     * @param  array<string, array<array-key, list<int>>>  $index
     * @param  array<int, Candidate>  $slots
     * @return list<array{int, string, string}> slot, key name, shared value
     */
    protected function findMatches(array $values, ?int $time, array $index, array $slots): array
    {
        $hits = [];

        foreach ($this->keys as $key) {
            $value = $values[$key->name] ?? null;

            if ($value === null) {
                continue;
            }

            $owners = $index[$key->name][$value] ?? [];
            sort($owners);

            foreach ($owners as $slot) {
                if (! isset($hits[$slot]) && $this->withinWindow($slots[$slot]->anchor, $time)) {
                    $hits[$slot] = [$slot, $key->name, $value];
                }
            }
        }

        return array_values($hits);
    }

    protected function apart(?int $time, Candidate $standing): ?int
    {
        return $time === null || $standing->time === null ? null : abs($time - $standing->time);
    }

    /**
     * Where a group ended up after any keepLast merges.
     *
     * @param  array<int, int>  $mergedInto
     */
    protected static function finalSlot(int $slot, array $mergedInto): int
    {
        while (isset($mergedInto[$slot])) {
            $slot = $mergedInto[$slot];
        }

        return $slot;
    }

    protected function withinWindow(?int $anchor, ?int $time): bool
    {
        if ($this->window === null) {
            return true;
        }

        // Timestamps are kept to years 1753-9999 (RecordReader), so the gap always fits in an int.
        return $anchor !== null && $time !== null && abs($time - $anchor) <= $this->window;
    }

    /**
     * Register a survivor's values so later records can match it. Several
     * live groups can hold the same value; each is indexed.
     *
     * @param  array<string, array<array-key, list<int>>>  $index
     * @param  array<string, string>  $values
     */
    protected function claim(array &$index, int $slot, array $values): void
    {
        foreach ($values as $name => $value) {
            if (! in_array($slot, $index[$name][$value] ?? [], true)) {
                $index[$name][$value][] = $slot;
            }
        }
    }

    /**
     * Forget a replaced survivor's values for its own group only.
     *
     * @param  array<string, array<array-key, list<int>>>  $index
     * @param  array<string, string>  $values
     */
    protected function release(array &$index, int $slot, array $values): void
    {
        foreach ($values as $name => $value) {
            $slots = array_values(array_diff($index[$name][$value] ?? [], [$slot]));

            if ($slots === []) {
                unset($index[$name][$value]);
            } else {
                $index[$name][$value] = $slots;
            }
        }
    }

    protected function entry(string $runId, DateTimeImmutable $decidedAt, Drop $drop, Candidate $survivor): AuditEntry
    {
        return new AuditEntry(
            runId: $runId,
            droppedId: $drop->droppedId,
            droppedHash: $drop->droppedHash,
            survivorId: $survivor->id,
            matchedId: $drop->matchedId,
            matchedKey: $drop->matchedKey,
            matchedValue: $drop->matchedValue,
            droppedRecordAt: $drop->droppedTime === null ? null : new DateTimeImmutable('@'.$drop->droppedTime),
            secondsApart: $drop->secondsApart,
            survivorship: $this->survivorship,
            windowSeconds: $this->window,
            dedupedAt: $decidedAt,
        );
    }

    protected function now(): DateTimeImmutable
    {
        return $this->clock === null
            ? new DateTimeImmutable
            : DateTimeImmutable::createFromInterface(($this->clock)());
    }
}
