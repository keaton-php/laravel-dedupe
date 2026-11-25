<!-- nav-group: Advanced -->
# Behavior

## Keeping the first or the last

By default the first record of each group is kept. Use `keepLast()` when you want the newest one, for example the latest version of a profile.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;

$profiles = [
    ['id' => 1, 'email' => 'jane@example.com', 'plan' => 'free'],
    ['id' => 2, 'email' => 'jane@example.com', 'plan' => 'trial'],
    ['id' => 3, 'email' => 'jane@example.com', 'plan' => 'pro'],
];

$result = Deduper::make()
    ->key('email', new EmailCanonical)
    ->keepLast()
    ->run($profiles);

echo 'kept: ', $result->survivors[0]['plan'], PHP_EOL;

foreach ($result->audit as $entry) {
    echo "dropped {$entry->droppedId}: lost to {$entry->matchedId}, survived by {$entry->survivorId}", PHP_EOL;
}
```

```text
kept: pro
dropped 1: lost to 2, survived by 3
dropped 2: lost to 3, survived by 3
```

With `keepLast()` a survivor can itself be replaced later on, so each entry carries two ids. `matchedId` is the record it directly lost to, and `survivorId` is the record that was kept in the end. With `keepFirst()` they are the same.

## Only survivors are compared

A record is compared with the records that are currently kept, not with the ones already dropped. This means duplicates do not chain. If A and B share an email, and B and C share a phone number, B is dropped and C is kept.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\PhoneDigits;

$signups = [
    ['id' => 'A', 'email' => 'jane@example.com', 'phone' => '512-555-0100'],
    ['id' => 'B', 'email' => 'jane@example.com', 'phone' => '512-555-0199'],
    ['id' => 'C', 'email' => 'bob@example.com',  'phone' => '512-555-0199'],
];

$result = Deduper::make()
    ->key('email', new EmailCanonical)
    ->key('phone', new PhoneDigits)
    ->run($signups);

implode(', ', array_column($result->survivors, 'id'));   // 'A, C'
```

## Sort by time first

Records are read in the order you pass them, and a window opens at the first record of a group. If your input is not in time order, sort it before running the deduper.

## Unreadable timestamps

A timestamp that is present but can't be parsed throws an `InvalidRecord` exception.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Exceptions\InvalidRecord;

try {
    Deduper::make()
        ->key('email')
        ->timestamp('created_at')
        ->run([
            ['id' => 1, 'email' => 'jane@example.com', 'created_at' => '2026-11-05 09:00:00'],
            ['id' => 2, 'email' => 'jane@example.com', 'created_at' => 'yesterday-ish'],
        ]);
} catch (InvalidRecord $e) {
    echo $e->getMessage();
}
```

```text
Record [2] has no readable timestamp in [created_at].
```

A missing timestamp (no field, `null` or blank) only throws when a window is set.

## Matching and memory

There is no fuzzy matching, so `Jon` and `John` are two different people. If two values should match, write a normalizer that makes them equal.

The hash of a dropped record is taken from its JSON form. Arrays, plain objects, Eloquent models and anything `JsonSerializable` or `Traversable` work out of the box. Give objects with private state a `jsonSerialize()` method, or pass arrays.

Records can come from any iterable, including a generator. The kept records and the match index stay in memory for the length of a run, so split very large inputs into several runs, for example one per day or per source.

**Next:** [Reference](08-reference.md).
