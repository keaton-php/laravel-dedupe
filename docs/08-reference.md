<!-- nav-group: Reference -->
# Reference

## `Deduper`

| Method | What it does |
|---|---|
| `Deduper::make()` | A new deduper with no keys. |
| `key(string $field, ?Normalizer $normalizer = null, ?string $as = null)` | Match on one field. Named after the field unless `$as` is given. No normalizer means `Exact`. |
| `compositeKey(string $name, array $fields)` | Match only when every field matches. `$fields` maps a field name to a normalizer, or `null` for `Exact`. |
| `addKey(MatchKey $key)` | Add a prebuilt `MatchKey`. |
| `window(?int $seconds)` / `windowMinutes(int $minutes)` | Set the window; `window(null)` removes it. Negative values throw. |
| `keepFirst()` / `keepLast()` / `survivorship(Survivorship $s)` | Which record of a group survives. `keepFirst()` is the default. |
| `id(string $field)` | The field that names a record in the trail. Default `id`. |
| `timestamp(?string $field)` | The field holding each record's time. Required for a window. |
| `auditTo(?AuditStore $store)` | Save each run's entries to this store. |
| `run(iterable $records, ?string $runId = null)` | Dedupe the records and return a `DedupeResult`. A `null` run id makes a random one; an empty one throws. |

## `DedupeResult`

| Member | What it is |
|---|---|
| `runId`, `total` | The run id, and how many records were read. |
| `survivors` | The kept records, exactly as passed in, in input order. |
| `audit` | `list<AuditEntry>`, in the order records were dropped. |
| `survivorCount()` / `droppedCount()` | Counts. |
| `droppedByKey()` | How many records each key removed, e.g. `['email' => 2, 'phone' => 1]`. |
| `why(string\|int $droppedId)` | The `AuditEntry` for a dropped record, or `null` if it was kept. |

## `AuditEntry`

One entry per dropped record. In the database each property is a snake_case column.

| Property / column | Meaning |
|---|---|
| `runId`, `droppedId` | The run id, and the dropped record's id as a string (records with no id are numbered `#3`). |
| `droppedHash` | sha256 of the dropped record's JSON form, with object keys sorted, so field order does not change it. |
| `survivorId` / `matchedId` | The record kept in the end, and the one it was directly compared with (they differ only under `keepLast()`). |
| `matchedKey` / `matchedValue` | The key that matched, and the normalized value both records shared (stored in the database only when `store_values` is true). |
| `droppedRecordAt` / `secondsApart` | The dropped record's own time, and the gap to its survivor, when a timestamp is set. |
| `survivorship`, `windowSeconds`, `dedupedAt` | First or last, the window in force, and when the run started (UTC). |

`$entry->isFor($record)` is true when `$record` has the same JSON form as the dropped record. `toArray()` and `AuditEntry::fromArray()` convert to and from a database row.

## Normalizers

| Class | What it does |
|---|---|
| `Exact` | Compares the value as a string (`1`, `'1'` and `true` all match). Used when you give no normalizer. |
| `LowercaseTrim` | Trims, collapses whitespace runs to one space, lowercases (multibyte safe). |
| `EmailCanonical` | Lowercases, strips a `+tag`, and for Gmail removes dots and folds `googlemail.com` into `gmail.com`. Each rule is a constructor flag. |
| `PhoneDigits` | Keeps only the digits. `new PhoneDigits(10)` keeps the last ten. |

## The `Dedupe` facade

| Method | What it does |
|---|---|
| `Dedupe::run(iterable $records, ?string $runId = null)` | Build a `Deduper` from the config and run it, saving the trail when enabled. |
| `Dedupe::audit(string $runId)` | Every entry a run saved, oldest first. |
| `Dedupe::auditFor(string\|int $recordId)` | Every entry for a record id, across runs, oldest first. |
| `Dedupe::prune(int $olderThanDays)` | Delete entries older than that many days, and return how many. |

## Configuration

You can publish the config file with `php artisan vendor:publish --tag=dedupe-config`. Config values are validated, and an invalid one throws `InvalidConfiguration`.

| Key | Default | What it does |
|---|---|---|
| `dedupe.keys` | `email`, `phone` | Each key maps field names to a normalizer class, or `null` for `Exact`. |
| `dedupe.window` | none | Whole seconds, or empty for no window. |
| `dedupe.survivorship` | `first` | `first` or `last`. |
| `dedupe.id` / `dedupe.timestamp` | `id` / `created_at` | The id and timestamp fields. |
| `dedupe.audit.enabled` | `true` | Save the trail. When false, `audit()`, `auditFor()` and `prune()` throw. |
| `dedupe.audit.connection` / `.table` | default / `dedupe_audit_entries` | Where the trail is stored. |
| `dedupe.audit.store_values` | `false` | Also save `matched_value`. |

## Exceptions

Every exception extends `InvalidArgumentException`.

| Exception | Thrown when |
|---|---|
| `InvalidConfiguration` | No keys, a duplicate or malformed key, an unknown normalizer, a bad window or survivorship, a window without a timestamp, the trail read while disabled, or a config value the package cannot use. |
| `InvalidRecord` | A record is not an array or object, its id or timestamp cannot be read, a window is set and a record has no time, a dropped record cannot be encoded as JSON, or a time falls outside 1753–9999. |
| `InvalidAuditEntry` | `AuditEntry::fromArray()` gets a row it cannot read, or an id or key name is longer than its audit-table column. |

Run ids and record ids can be up to 191 characters long, and key names up to 128. `DatabaseAuditStore` throws `InvalidAuditEntry` for anything longer.
