<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Contracts;

interface Normalizer
{
    /**
     * Turn a raw field value into the string that two records are compared on.
     *
     * Return null when the value cannot be matched on (blank, wrong type).
     * A null part means the match key is skipped for that record, so two
     * records that are both blank never collide with each other.
     */
    public function normalize(mixed $value): ?string;
}
