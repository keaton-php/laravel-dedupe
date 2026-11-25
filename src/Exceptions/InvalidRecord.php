<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Exceptions;

use InvalidArgumentException;

class InvalidRecord extends InvalidArgumentException
{
    public static function unsupported(int $position, string $type): self
    {
        return new self("Record #{$position} is a [{$type}]. Records must be arrays, ArrayAccess objects or plain objects.");
    }

    public static function unreadableId(int $position, string $field, string $type): self
    {
        return new self("Record #{$position} has a [{$type}] in its id field [{$field}]. Ids must be integers, strings or Stringable objects.");
    }

    public static function unreadableTimestamp(string $recordId, string $field): self
    {
        return new self("Record [{$recordId}] has no readable timestamp in [{$field}].");
    }

    public static function timestampOutOfRange(string $recordId, string $field): self
    {
        return new self("Record [{$recordId}] has a timestamp in [{$field}] outside the years 1753 to 9999, which the audit trail can store.");
    }

    public static function missingTimestamp(string $recordId, string $field): self
    {
        return new self("Record [{$recordId}] has no timestamp in [{$field}], which the window needs.");
    }

    public static function unhashable(string $recordId): self
    {
        return new self("Record [{$recordId}] cannot be encoded to JSON (a NAN or INF float, a resource, invalid UTF-8, or an object with no visible content), so its payload hash cannot be computed.");
    }
}
