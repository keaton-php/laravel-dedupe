# Laravel Dedupe

[![Latest Version on Packagist](https://img.shields.io/packagist/v/keaton-php/laravel-dedupe.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-dedupe)
[![Tests](https://img.shields.io/github/actions/workflow/status/keaton-php/laravel-dedupe/run-tests.yml?branch=1.x&label=tests&style=flat-square)](https://github.com/keaton-php/laravel-dedupe/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/keaton-php/laravel-dedupe.svg?style=flat-square)](https://packagist.org/packages/keaton-php/laravel-dedupe)

Laravel Dedupe removes duplicates from a list of records and tells you why each one was removed. Every dropped record comes back with the record it matched and the key it matched on.

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

## More than one key

When a deduper has several keys, a record is a duplicate when any one of them matches. Here record 3 has a different email, but the same phone number written another way.

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

foreach ($result->audit as $drop) {
    echo "dropped {$drop->droppedId}: same {$drop->matchedKey} as {$drop->survivorId}\n";
}
```

```text
dropped 2: same email as 1
dropped 3: same phone as 1
```

## What else it does

- Clean values up before comparing them. There are rules for emails and phone numbers, and you can write your own.
- Match on a combination of fields, like last name and zip code, when no single field identifies a record.
- Set a time window, so two records only count as duplicates when they are close together in time.
- Keep the first or the last record of each group.
- Store the audit trail in the database, and read it back by run or by record.
- Each entry holds a hash of the dropped record, so you can prove which record it was without keeping a copy of it.

## Installation

You can install the package via composer:

```bash
composer require keaton-php/laravel-dedupe
```

The package requires PHP 8.2 or higher and Laravel 11, 12 or 13.

The audit trail is stored in a `dedupe_audit_entries` table. The migration is loaded for you, so you only need to run it:

```bash
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag=dedupe-config
```

## Documentation

You'll find the documentation on [kevineaton.dev/docs/laravel-dedupe](https://kevineaton.dev/docs/laravel-dedupe/). The same pages are in the [docs](docs) folder of this repository:

- [Introduction](docs/index.md)
- [Installation](docs/02-installation.md)
- [Basic usage](docs/03-basic-usage.md)
- [Normalizers](docs/04-normalizers.md)
- [Keys and windows](docs/05-keys-and-windows.md)
- [The audit trail](docs/06-laravel-and-audit.md)
- [Behavior](docs/07-behavior.md)
- [Reference](docs/08-reference.md)

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Kevin Eaton](https://github.com/keaton-php)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
