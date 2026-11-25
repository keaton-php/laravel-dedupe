<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Normalizers;

/**
 * The default: compare the value as it is, only refusing to match on blanks.
 */
class Exact extends TextNormalizer
{
    protected function transform(string $text): string
    {
        return $text;
    }
}
