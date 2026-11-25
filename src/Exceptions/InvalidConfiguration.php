<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Exceptions;

use InvalidArgumentException;

class InvalidConfiguration extends InvalidArgumentException
{
    public static function noMatchKeys(): self
    {
        return new self('At least one match key is required. Add one with key() or under [dedupe.keys].');
    }

    public static function duplicateKeyName(string $name): self
    {
        return new self("A match key named [{$name}] is already defined. Key names appear in the audit trail, so they must be unique.");
    }

    public static function invalidKeyDefinition(string $name): self
    {
        return new self("The match key [{$name}] must list at least one field, as ['field' => Normalizer::class] or ['field'].");
    }

    public static function unknownNormalizer(string $class): self
    {
        return new self("[{$class}] is not a class that implements Keaton\\Dedupe\\Contracts\\Normalizer.");
    }

    public static function invalidWindow(string $value): self
    {
        return new self("The window must be a whole number of seconds, zero or more, or null for no window. Got [{$value}].");
    }

    public static function invalidSurvivorship(string $value): self
    {
        return new self("Survivorship must be [first] or [last]. Got [{$value}].");
    }

    public static function windowNeedsTimestamp(): self
    {
        return new self('A window needs a timestamp field to measure against. Set one with timestamp() or [dedupe.timestamp].');
    }

    public static function auditDisabled(): self
    {
        return new self('No audit store is configured, so there is no persisted audit trail to read. Set [dedupe.audit.enabled] to true.');
    }

    public static function invalidOption(string $key, string $value): self
    {
        return new self("The config value [{$key}] cannot be [{$value}].");
    }

    public static function emptyFieldName(string $role): self
    {
        return new self("The {$role} field name cannot be empty.".($role === 'timestamp' ? ' Pass null for no timestamp.' : ''));
    }

    public static function emptyRunId(): self
    {
        return new self('A run id cannot be empty. Pass null to have one made for you.');
    }

    public static function invalidChunkSize(int $size): self
    {
        return new self("The audit store must insert at least one row per query. Got a chunk size of [{$size}].");
    }

    public static function invalidSignificantDigits(int $digits): self
    {
        return new self("PhoneDigits keeps the last N digits, so N must be zero (all of them) or more. Got [{$digits}].");
    }

    public static function invalidRetention(int $days): self
    {
        return new self("Audit entries can only be pruned by a retention of zero days or more. Got [{$days}].");
    }
}
