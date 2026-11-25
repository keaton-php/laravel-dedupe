<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Normalizers;

use Keaton\Dedupe\Exceptions\InvalidConfiguration;

class PhoneDigits extends TextNormalizer
{
    /**
     * @param  int  $significantDigits  Keep only the last N digits so "+1 512 555 0100" equals "5125550100". Zero keeps them all.
     */
    public function __construct(protected int $significantDigits = 0)
    {
        if ($significantDigits < 0) {
            throw InvalidConfiguration::invalidSignificantDigits($significantDigits);
        }
    }

    protected function transform(string $text): string
    {
        $digits = preg_replace('/\D+/', '', $text) ?? '';

        if ($this->significantDigits > 0 && strlen($digits) > $this->significantDigits) {
            return substr($digits, -$this->significantDigits);
        }

        return $digits;
    }
}
