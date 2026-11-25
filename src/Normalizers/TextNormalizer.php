<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Normalizers;

use Keaton\Dedupe\Contracts\Normalizer;
use Stringable;

abstract class TextNormalizer implements Normalizer
{
    public function normalize(mixed $value): ?string
    {
        $text = $this->text($value);

        // Blank never matches, whatever the transform keeps.
        if ($text === null || self::isBlank($text)) {
            return null;
        }

        $normalized = $this->transform($text);

        return self::isBlank($normalized) ? null : $normalized;
    }

    /**
     * Empty, or only whitespace: ASCII whitespace, Unicode spaces and separators
     * (a non-breaking space, an em space) and zero-width spaces. Text that is not
     * valid UTF-8 is only blank if it is ASCII whitespace.
     */
    public static function isBlank(string $text): bool
    {
        if (trim($text) === '') {
            return true;
        }

        return preg_match('/^[\s\p{Z}\x{200B}\x{FEFF}]*$/u', $text) === 1;
    }

    /**
     * Turn the already-stringified value into its comparison form.
     */
    abstract protected function transform(string $text): string;

    protected function text(mixed $value): ?string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }
}
