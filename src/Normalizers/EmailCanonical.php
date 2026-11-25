<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Normalizers;

class EmailCanonical extends TextNormalizer
{
    /**
     * Consumer Gmail ignores dots in the local part, and googlemail.com is the same mailbox.
     */
    protected const GMAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public function __construct(
        protected bool $stripPlusTags = true,
        protected bool $canonicalizeGmail = true,
    ) {
        //
    }

    protected function transform(string $text): string
    {
        $address = mb_strtolower(trim($text));
        $at = strrpos($address, '@');

        // Not an address we can reason about: fall back to lowercase and trim.
        if ($at === false || $at === 0 || $at === strlen($address) - 1) {
            return $address;
        }

        $local = substr($address, 0, $at);
        $domain = substr($address, $at + 1);

        if ($this->stripPlusTags && ($plus = strpos($local, '+')) !== false && $plus > 0) {
            $local = substr($local, 0, $plus);
        }

        if ($this->canonicalizeGmail && in_array($domain, self::GMAIL_DOMAINS, true)) {
            $local = str_replace('.', '', $local);
            $domain = 'gmail.com';
        }

        return "{$local}@{$domain}";
    }
}
