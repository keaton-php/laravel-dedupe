<!-- nav-group: Advanced -->
# The audit trail

A `DedupeResult` only lasts as long as the variable that holds it. To keep the audit trail after a run, give the deduper an audit store.

## Storing the trail in memory

`InMemoryAuditStore` keeps the entries of each run and lets you read them back by run or by record. A `Deduper` holds no state between runs, so you can reuse one instance.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Stores\InMemoryAuditStore;

$store = new InMemoryAuditStore;

$deduper = Deduper::make()
    ->key('email', new EmailCanonical)
    ->auditTo($store);

$deduper->run([
    ['id' => 1, 'email' => 'jane@example.com'],
    ['id' => 2, 'email' => 'JANE@example.com'],
], 'monday');

$deduper->run([
    ['id' => 7, 'email' => 'bob@example.com'],
    ['id' => 2, 'email' => 'bob@example.com'],
], 'tuesday');

count($store->forRun('monday'));            // 1
count($store->forDroppedRecord('2'));       // 2
$store->forDroppedRecord('2')[1]->runId;    // 'tuesday'
```

## Using the facade

In a Laravel app, the `Dedupe` facade builds a `Deduper` from the config file and stores every run in the `dedupe_audit_entries` table. The default config matches on `email` and `phone`.

```php
<?php

use Illuminate\Support\Facades\DB;
use Keaton\Dedupe\Facades\Dedupe;
use Keaton\Dedupe\Normalizers\PhoneDigits;

// In an app these two lines live in .env (DEDUPE_WINDOW=86400) and a service provider.
config()->set('dedupe.window', 86_400);
app()->bind(PhoneDigits::class, fn () => new PhoneDigits(10));

$leads = [
    ['id' => 640, 'email' => 'jane.doe@gmail.com',       'phone' => '+1 (512) 555-0100', 'created_at' => '2026-11-05 09:00:00'],
    ['id' => 701, 'email' => 'bob@example.com',          'phone' => '512-555-0199',      'created_at' => '2026-11-05 09:10:00'],
    ['id' => 812, 'email' => 'Jane.Doe+promo@GMAIL.com', 'phone' => '',                  'created_at' => '2026-11-05 09:42:00'],
    ['id' => 903, 'email' => 'jd@work.example',          'phone' => '512.555.0100',      'created_at' => '2026-11-05 11:30:00'],
    ['id' => 954, 'email' => 'bob@example.com',          'phone' => '',                  'created_at' => '2026-11-07 09:10:00'],
];

$result = Dedupe::run($leads, 'signups-2026-11-05');

$rows = DB::table('dedupe_audit_entries')
    ->where('run_id', 'signups-2026-11-05')
    ->orderBy('id')
    ->get(['dropped_id', 'survivor_id', 'matched_key', 'seconds_apart', 'matched_value']);

foreach ($rows as $row) {
    printf("%-4s %-4s %-6s %-5s %s\n", $row->dropped_id, $row->survivor_id, $row->matched_key, $row->seconds_apart, $row->matched_value ?? 'null');
}
```

```text
812  640  email  2520  null
903  640  phone  9000  null
```

The `matched_value` column is empty. By default the table stores ids and hashes, and leaves out the email addresses and phone numbers that matched. Set `DEDUPE_AUDIT_STORE_VALUES=true` to store them as well.

## Reading the trail back

You can read the stored entries back by run or by record, from anywhere in your app.

```php
<?php

use Keaton\Dedupe\Facades\Dedupe;

count(Dedupe::audit('signups-2026-11-05'));   // 2
count(Dedupe::auditFor(812));                 // 1
Dedupe::auditFor(812)[0]->survivorId;         // '640'
Dedupe::auditFor(812)[0]->matchedKey;         // 'email'
Dedupe::auditFor(812)[0]->secondsApart;       // 2520
Dedupe::auditFor(640);                        // [] (640 was kept)
Dedupe::prune(90);                            // 0 (nothing is 90 days old yet)
```

`AuditEntry::isFor($record)` checks a record against the stored hash, so you can confirm which record was dropped without the table holding a copy of it.

`Dedupe::prune($days)` deletes entries older than the given number of days and returns how many were deleted. You can schedule it if you don't want to keep the trail forever.

To store the trail somewhere else, implement `Keaton\Dedupe\Contracts\AuditStore`.

**Next:** [Behavior](07-behavior.md).
