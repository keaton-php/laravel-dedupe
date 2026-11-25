<!-- nav-group: Start here -->
# Introduction

Laravel Dedupe removes duplicates from a list of records and tells you why each one was removed. Every dropped record comes back with the record it matched and the key it matched on.

## Before and after

Say a list of signups has the same person in it three times. The usual way to clean it up is a collection's `unique()`:

```php
<?php

$signups = [
    ['id' => 1, 'name' => 'Jane Doe', 'email' => 'jane@example.com'],
    ['id' => 2, 'name' => 'Bob Ray',  'email' => 'bob@example.com'],
    ['id' => 3, 'name' => 'Jane Doe', 'email' => ' Jane@Example.com'],
    ['id' => 4, 'name' => 'J. Doe',   'email' => 'JANE@EXAMPLE.COM '],
];

$survivors = collect($signups)
    ->unique(fn (array $signup) => strtolower(trim($signup['email'])));

echo $survivors->pluck('id')->implode(', '), "\n";
```

```text
1, 2
```

Those are the right two records. But 3 and 4 are gone, and nothing says which record each one matched, or on what. When someone asks where their signup went, you have no answer.

Here's the same list with the package:

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

echo implode(', ', array_column($result->survivors, 'id')), "\n";

foreach ($result->audit as $drop) {
    echo "dropped {$drop->droppedId}: same {$drop->matchedKey} as {$drop->survivorId}\n";
}
```

```text
1, 2
dropped 3: same email as 1
dropped 4: same email as 1
```

The same two records are kept, and each dropped record now says which one it matched and on which key. `key()` names the field to compare, and `LowercaseTrim` is the rule that cleans the value up first. In a Laravel app the trail can be stored in the database, so you can still answer that question a month later.

## What else it does

- Clean values up before comparing them. There are rules for emails and phone numbers, and you can write your own. See [Normalizers](04-normalizers.md).
- Match on a combination of fields, like last name and zip code, when no single field identifies a record. See [Keys and windows](05-keys-and-windows.md).
- Set a time window, so two records only count as duplicates when they are close together in time. See [Keys and windows](05-keys-and-windows.md).
- Keep the first or the last record of each group. See [Behavior](07-behavior.md).
- Store the audit trail in the database, and read it back by run or by record. See [The audit trail](06-laravel-and-audit.md).
- Each entry holds a hash of the dropped record, so you can prove which record it was without keeping a copy of it. See [The audit trail](06-laravel-and-audit.md).

It works on plain arrays without booting the framework. In a Laravel app it can store that audit trail in a database table.

## When to use it

A collection's `unique()` drops duplicates, but it can't tell you why a record went. This package is for the cases where you need that answer later, like deduping leads, signups or imports.

There is no fuzzy matching. Two values match only when they normalize to the same string.

**Next:** [Installation](02-installation.md).
