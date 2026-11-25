<!-- nav-group: Start here -->
# Installation

## Requirements

- PHP 8.2 or higher, with the `mbstring` extension
- Laravel 11.33.2 or higher, 12 or 13 (Laravel 13 needs PHP 8.3)

The `Deduper` class works in plain PHP. The config file, the audit table and the `Dedupe` facade are only used in a Laravel app.

## Installing the package

You can install the package via composer and run the migration:

```bash
composer require keaton-php/laravel-dedupe
php artisan migrate
```

The service provider is discovered automatically. The migration creates the `dedupe_audit_entries` table that the database audit store writes to. You can skip it if you only use the in-memory store, or no store at all.

## Compatibility

The test suite runs on PHP 8.2 to 8.4 and Laravel 11 to 13, against SQLite. MySQL and PostgreSQL are not part of the CI matrix.

**Next:** [Basic usage](03-basic-usage.md).
