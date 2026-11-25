<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

use Keaton\Dedupe\Contracts\Normalizer;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Normalizers\Exact;
use Keaton\Dedupe\Support\RecordReader;

/**
 * One way two records can be "the same": one or more fields, each normalized.
 * The name is what the audit trail reports as the key that matched.
 */
final readonly class MatchKey
{
    /**
     * @param  array<string, Normalizer>  $fields  field name => normalizer
     */
    public function __construct(public string $name, public array $fields)
    {
        if ($name === '' || $fields === []) {
            throw InvalidConfiguration::invalidKeyDefinition($name);
        }

        foreach ($fields as $field => $normalizer) {
            if (! is_string($field) || $field === '' || ! $normalizer instanceof Normalizer) {
                throw InvalidConfiguration::invalidKeyDefinition($name);
            }
        }
    }

    public static function on(string $field, ?Normalizer $normalizer = null, ?string $as = null): self
    {
        return new self($as ?? $field, [$field => $normalizer ?? new Exact]);
    }

    /**
     * @param  array<string, Normalizer|null>  $fields  field name => normalizer, null compares the value as it is
     */
    public static function composite(string $name, array $fields): self
    {
        return new self($name, array_map(
            fn (mixed $normalizer): mixed => $normalizer ?? new Exact,
            $fields,
        ));
    }

    /**
     * The string this record is compared on, or null when any field is blank.
     */
    public function valueFor(mixed $record, RecordReader $reader): ?string
    {
        $parts = [];

        foreach ($this->fields as $field => $normalizer) {
            $part = $normalizer->normalize($reader->get($record, $field));

            if ($part === null) {
                return null;
            }

            $parts[] = $part;
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        // JSON keeps ["a","bc"] and ["ab","c"] apart. Parts that are not valid UTF-8
        // are length-prefixed instead, byte for byte (JSON would have to replace them).
        foreach ($parts as $part) {
            if (! mb_check_encoding($part, 'UTF-8')) {
                return implode('|', array_map(fn (string $part): string => strlen($part).':'.$part, $parts));
            }
        }

        return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
