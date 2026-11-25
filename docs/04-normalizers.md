<!-- nav-group: Basic usage -->
# Normalizers

A normalizer cleans a value before it is compared, so that values that only differ in formatting end up as the same string. A key without a normalizer uses `Exact`, which compares the value as a string, so `1` and `'1'` match.

## Available normalizers

The package ships with four normalizers.

```php
<?php

use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\Exact;
use Keaton\Dedupe\Normalizers\LowercaseTrim;
use Keaton\Dedupe\Normalizers\PhoneDigits;

(new Exact)->normalize(' Jane ');                           // ' Jane '
(new LowercaseTrim)->normalize('  Jane   DOE ');            // 'jane doe'
(new EmailCanonical)->normalize('Jane.Doe+news@GMAIL.com'); // 'janedoe@gmail.com'
(new PhoneDigits)->normalize('+1 (512) 555-0100');          // '15125550100'
(new PhoneDigits(10))->normalize('+1 (512) 555-0100');      // '5125550100'
```

`PhoneDigits(10)` keeps the last ten digits, so a number matches with or without its `+1` country code.

`EmailCanonical` lowercases the address and strips a `+tag` from the local part. For Gmail addresses it also ignores dots and treats `googlemail.com` as `gmail.com`, so `Jane.Doe+news@GMAIL.com` and `janedoe@googlemail.com` both become `janedoe@gmail.com`. Each of these rules can be turned off with a constructor flag.

## Blank values

A key whose value is missing, empty or only whitespace is skipped for that record. Two signups without a phone number are not the same person.

```php
<?php

use Keaton\Dedupe\Deduper;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\PhoneDigits;

$signups = [
    ['id' => 1, 'email' => 'jane@example.com', 'phone' => ''],
    ['id' => 2, 'email' => 'bob@example.com',  'phone' => '   '],
    ['id' => 3, 'email' => 'ann@example.com'],
];

$result = Deduper::make()
    ->key('email', new EmailCanonical)
    ->key('phone', new PhoneDigits)
    ->run($signups);

$result->survivorCount();   // 3
```

## Writing your own normalizer

A normalizer has one method. It takes the raw value and returns the string to compare on, or `null` to skip the value. This one makes order numbers match with or without leading zeros.

```php
<?php

use Keaton\Dedupe\Contracts\Normalizer;

class StripLeadingZeros implements Normalizer
{
    public function normalize(mixed $value): ?string
    {
        $normalized = ltrim(trim((string) $value), '0');

        return $normalized === '' ? null : $normalized;
    }
}
```

```php
<?php

use Keaton\Dedupe\Deduper;

$orders = [
    ['id' => 'A', 'order_ref' => '00042'],
    ['id' => 'B', 'order_ref' => '42'],
    ['id' => 'C', 'order_ref' => '000'],
    ['id' => 'D', 'order_ref' => '0'],
];

$result = Deduper::make()
    ->key('order_ref', new StripLeadingZeros)
    ->run($orders);

implode(', ', array_column($result->survivors, 'id'));   // 'A, C, D'
$result->why('B')->matchedValue;                          // '42'
```

Orders `'000'` and `'0'` normalize to an empty string, so they are skipped and not matched with each other.

You can also extend `Keaton\Dedupe\Normalizers\TextNormalizer`. It casts the value to a string and handles blank values, so you only write `transform(string $text): string`.

**Next:** [Keys and windows](05-keys-and-windows.md).
