<!-- nav-group: Basic usage -->
# Basic usage

## Removing duplicates

A `Deduper` needs at least one key, which is the field to compare. A key can have a normalizer that cleans the value up first. `LowercaseTrim` trims the value, collapses inner whitespace and lowercases it.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\LowercaseTrim;

$signups = [
    ['id' => 1, 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ['id' => 2, 'name' => 'Bob Ray',  'email' => 'bob@example.com'],
    ['id' => 3, 'name' => 'Jane Doe', 'email' => ' Jane@Example.com'],
    ['id' => 4, 'name' => 'J. Doe',   'email' => 'JANE@EXAMPLE.COM '],
];

$result = Deduper::make()
    ->key('email', new LowercaseTrim)
    ->run($signups);

echo implode(', ', array_column($result->survivors, 'id')), PHP_EOL;

foreach ($result->audit as $entry) {
    echo "dropped {$entry->droppedId}: same {$entry->matchedKey} as {$entry->survivorId}", PHP_EOL;
}
```

```text
1, 2
dropped 3: same email as 1
dropped 4: same email as 1
```

`$result->survivors` holds the kept records exactly as you passed them in. `$result->audit` holds one `AuditEntry` for each dropped record.

## Finding out why a record was dropped

`$result->why()` returns the entry for a dropped id, or `null` when the record was kept.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\LowercaseTrim;

$signups = [
    ['id' => 1, 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ['id' => 2, 'name' => 'Bob Ray',  'email' => 'bob@example.com'],
    ['id' => 3, 'name' => 'Jane Doe', 'email' => ' Jane@Example.com'],
    ['id' => 4, 'name' => 'J. Doe',   'email' => 'JANE@EXAMPLE.COM '],
];

$result = Deduper::make()->key('email', new LowercaseTrim)->run($signups);

$result->why(4)->survivorId;           // '1'
$result->why(4)->matchedKey;           // 'email'
$result->why(4)->matchedValue;         // 'jane@example.com'
strlen($result->why(4)->droppedHash);  // 64 (a sha256 of record 4)
$result->why(2);                       // null: record 2 was kept
$result->droppedCount();               // 2
```

`matchedValue` is the normalized value the two records shared. `droppedHash` is a sha256 of the dropped record, so you can later prove which record it was without keeping a copy of it.

**Next:** [Normalizers](04-normalizers.md).
