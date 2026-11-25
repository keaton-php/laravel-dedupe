<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Fixtures;

use Keaton\Dedupe\Normalizers\TextNormalizer;

/**
 * A custom normalizer, the way a user would write one: "00042" and "42" are the same order.
 */
class StripLeadingZeros extends TextNormalizer
{
    protected function transform(string $text): string
    {
        return ltrim(trim($text), '0');
    }
}
