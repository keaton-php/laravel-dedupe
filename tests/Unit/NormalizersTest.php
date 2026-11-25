<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\Exact;
use Keaton\Dedupe\Normalizers\LowercaseTrim;
use Keaton\Dedupe\Normalizers\PhoneDigits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class NormalizersTest extends TestCase
{
    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function lowercaseTrimCases(): array
    {
        return [
            'trims and lowercases' => ['  Jane DOE ', 'jane doe'],
            'collapses inner whitespace' => ["Jane \t  Doe", 'jane doe'],
            'lowercases multibyte text' => ['ÅSA', 'åsa'],
            'integers become strings' => [42, '42'],
            'booleans become flags' => [true, '1'],
            'blank is unmatchable' => ['   ', null],
            'empty is unmatchable' => ['', null],
            'null is unmatchable' => [null, null],
            'arrays are unmatchable' => [['a'], null],
        ];
    }

    #[Test]
    #[DataProvider('lowercaseTrimCases')]
    public function it_can_lowercase_and_trim(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, (new LowercaseTrim)->normalize($input));
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function emailCases(): array
    {
        return [
            'lowercases and trims' => ['  Jane@Example.COM ', 'jane@example.com'],
            'strips a plus tag' => ['jane+news@example.com', 'jane@example.com'],
            'keeps dots on other providers' => ['jane.doe@example.com', 'jane.doe@example.com'],
            'removes dots on gmail' => ['j.a.n.e@gmail.com', 'jane@gmail.com'],
            'treats googlemail as gmail' => ['ja.ne+x@GoogleMail.com', 'jane@gmail.com'],
            'keeps a leading plus' => ['+tag@example.com', '+tag@example.com'],
            'leaves a non-address alone' => ['not-an-email', 'not-an-email'],
            'leaves a missing local part alone' => ['@example.com', '@example.com'],
            'leaves a missing domain alone' => ['jane@', 'jane@'],
            'splits on the last at sign' => ['"a@b"@Example.com', '"a@b"@example.com'],
            'blank is unmatchable' => ['  ', null],
            'null is unmatchable' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('emailCases')]
    public function it_can_canonicalize_emails(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, (new EmailCanonical)->normalize($input));
    }

    #[Test]
    public function it_can_keep_plus_tags_and_gmail_dots_when_asked(): void
    {
        $normalizer = new EmailCanonical(stripPlusTags: false, canonicalizeGmail: false);

        $this->assertSame('j.a.n.e+x@gmail.com', $normalizer->normalize('J.a.n.e+x@Gmail.com'));
        $this->assertSame('j.a.n.e+x@googlemail.com', $normalizer->normalize('j.a.n.e+x@googlemail.com'));
    }

    /**
     * @return array<string, array{mixed, string|null}>
     */
    public static function phoneCases(): array
    {
        return [
            'strips formatting' => ['+1 (512) 555-0100', '15125550100'],
            'accepts an integer' => [5125550100, '5125550100'],
            'drops letters' => ['512-555-0100 ext', '5125550100'],
            'no digits is unmatchable' => ['call me', null],
            'empty is unmatchable' => ['', null],
            'null is unmatchable' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('phoneCases')]
    public function it_can_reduce_a_phone_number_to_digits(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, (new PhoneDigits)->normalize($input));
    }

    #[Test]
    public function it_can_keep_only_the_significant_digits_of_a_phone_number(): void
    {
        $normalizer = new PhoneDigits(10);

        $this->assertSame('5125550100', $normalizer->normalize('+1 (512) 555-0100'));
        $this->assertSame('5125550100', $normalizer->normalize('512.555.0100'));
        // A short number is not padded.
        $this->assertSame('5550100', $normalizer->normalize('555-0100'));
    }

    #[Test]
    public function it_compares_exactly_by_default(): void
    {
        $normalizer = new Exact;

        $this->assertSame(' Jane ', $normalizer->normalize(' Jane '));
        $this->assertNull($normalizer->normalize(''));
        $this->assertNull($normalizer->normalize(null));
    }

    #[Test]
    public function it_never_matches_on_a_blank_value_even_when_comparing_exactly(): void
    {
        $this->assertNull((new Exact)->normalize('   '));
        $this->assertNull((new Exact)->normalize("\t\n"));
        $this->assertSame(' Jane ', (new Exact)->normalize(' Jane '));
    }

    #[Test]
    public function it_refuses_a_negative_number_of_significant_digits(): void
    {
        $this->expectException(InvalidConfiguration::class);

        new PhoneDigits(-1);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unicodeBlanks(): array
    {
        return [
            'a non-breaking space' => ["\u{A0}"],
            'an em space' => ["\u{2003}"],
            'mixed with ASCII' => [" \u{A0}\t\u{2003} "],
            'a zero-width space' => ["\u{200B}"],
            'a byte order mark' => ["\u{FEFF}"],
        ];
    }

    #[Test]
    #[DataProvider('unicodeBlanks')]
    public function it_never_matches_on_unicode_whitespace(string $blank): void
    {
        $this->assertNull((new Exact)->normalize($blank));
        $this->assertNull((new LowercaseTrim)->normalize($blank));
        $this->assertNull((new EmailCanonical)->normalize($blank));
    }

    #[Test]
    public function it_collapses_unicode_spaces_like_ascii_ones(): void
    {
        $this->assertSame('jane doe', (new LowercaseTrim)->normalize("\u{A0}Jane\u{2003}\u{A0} DOE "));
    }
}
