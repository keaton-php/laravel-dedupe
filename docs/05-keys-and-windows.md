<!-- nav-group: Advanced -->
# Keys and windows

## Matching on more than one key

When a deduper has several keys, a record is a duplicate when any one of them matches. Keys are tried in the order you add them, and the first one that matches is reported. In this example record 3 has a different email, but the same phone number in another format.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\PhoneDigits;

$signups = [
    ['id' => 1, 'email' => 'jane.doe@gmail.com',      'phone' => '(512) 555-0100'],
    ['id' => 2, 'email' => 'Jane.Doe+news@GMAIL.com', 'phone' => ''],
    ['id' => 3, 'email' => 'jd@work.example',         'phone' => '512.555.0100'],
    ['id' => 4, 'email' => 'bob@example.com',         'phone' => '512-555-0199'],
];

$result = Deduper::make()
    ->key('email', new EmailCanonical)
    ->key('phone', new PhoneDigits)
    ->run($signups);

foreach ($result->audit as $entry) {
    echo "dropped {$entry->droppedId}: same {$entry->matchedKey} as {$entry->survivorId} ({$entry->matchedValue})", PHP_EOL;
}
```

```text
dropped 2: same email as 1 (janedoe@gmail.com)
dropped 3: same phone as 1 (5125550100)
```

## Composite keys

When no single field identifies a record, you can match on a combination. `compositeKey()` only matches when every field matches. Each field takes its own normalizer, or `null` for `Exact`.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\LowercaseTrim;

$customers = [
    ['id' => 1, 'last_name' => 'Doe',  'zip' => '78701'],
    ['id' => 2, 'last_name' => 'DOE ', 'zip' => '78701'],
    ['id' => 3, 'last_name' => 'Doe',  'zip' => '10001'],
];

$result = Deduper::make()
    ->compositeKey('name_and_zip', ['last_name' => new LowercaseTrim, 'zip' => null])
    ->run($customers);

foreach ($result->audit as $entry) {
    echo "dropped {$entry->droppedId}: same {$entry->matchedKey} as {$entry->survivorId}", PHP_EOL;
}
```

```text
dropped 2: same name_and_zip as 1
```

## Time windows

The same email twice in an hour is probably one person clicking twice. A week apart, it is probably someone coming back. A window sets how close in time two records must be to count as duplicates. Call `timestamp()` to say which field holds each record's time.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;

$signups = [
    ['id' => 1, 'email' => 'jane@example.com', 'created_at' => '2026-11-05 09:00:00'],
    ['id' => 2, 'email' => 'jane@example.com', 'created_at' => '2026-11-05 09:42:00'],
    ['id' => 3, 'email' => 'jane@example.com', 'created_at' => '2026-11-07 10:15:00'],
];

$result = Deduper::make()
    ->key('email', new EmailCanonical)
    ->timestamp('created_at')
    ->window(86_400)   // 24 hours, in seconds
    ->run($signups);

echo implode(', ', array_column($result->survivors, 'id')), PHP_EOL;

foreach ($result->audit as $entry) {
    $minutes = intdiv($entry->secondsApart, 60);

    echo "dropped {$entry->droppedId}: same {$entry->matchedKey} as {$entry->survivorId}, {$minutes} minutes apart", PHP_EOL;
}
```

```text
1, 3
dropped 2: same email as 1, 42 minutes apart
```

Record 3 has the same email but arrives two days later, so it is kept and starts a new group.

The window is measured from the first record of each group, and the boundary is included. `windowMinutes(1440)` sets the same window in minutes. A timestamp can be a unix timestamp, a `DateTimeInterface` or a date string, between the years 1753 and 9999. Without a window, matching records are duplicates no matter how far apart they are.

**Next:** [The audit trail](06-laravel-and-audit.md).
