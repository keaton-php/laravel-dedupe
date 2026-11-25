<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Normalizers;

class LowercaseTrim extends TextNormalizer
{
    protected function transform(string $text): string
    {
        // Collapse runs of whitespace, Unicode spaces included, so "Jane\u{A0}  Doe" equals "jane doe".
        $collapsed = preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        // Not valid UTF-8: preg_replace() gives up, so fall back to ASCII whitespace.
        $collapsed ??= preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_strtolower(trim($collapsed));
    }
}
