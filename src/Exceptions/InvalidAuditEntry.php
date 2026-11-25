<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Exceptions;

use InvalidArgumentException;

class InvalidAuditEntry extends InvalidArgumentException
{
    public static function missingField(string $field): self
    {
        return new self("An audit row needs a [{$field}] value, and this one has none.");
    }

    public static function invalidField(string $field): self
    {
        return new self("An audit row's [{$field}] cannot be read. Dates are 'Y-m-d H:i:s' in UTC, counts are whole numbers of zero or more.");
    }

    public static function tooLong(string $field, int $max): self
    {
        return new self("An audit entry's [{$field}] is longer than the {$max} characters the audit table holds.");
    }

    public static function unknownSurvivorship(string $value): self
    {
        return new self("An audit row's survivorship must be [first] or [last]. Got [{$value}].");
    }
}
