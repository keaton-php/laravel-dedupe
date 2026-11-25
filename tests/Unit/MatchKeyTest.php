<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\MatchKey;
use Keaton\Dedupe\Normalizers\LowercaseTrim;
use Keaton\Dedupe\Support\RecordReader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class MatchKeyTest extends TestCase
{
    #[Test]
    public function it_is_named_after_its_field_unless_told_otherwise(): void
    {
        $this->assertSame('email', MatchKey::on('email')->name);
        $this->assertSame('contact', MatchKey::on('email', null, 'contact')->name);
    }

    #[Test]
    public function it_returns_the_normalized_value_of_a_single_field(): void
    {
        $key = MatchKey::on('email', new LowercaseTrim);

        $this->assertSame('jane@example.com', $key->valueFor(['email' => ' JANE@example.com '], new RecordReader));
    }

    #[Test]
    public function it_returns_null_when_any_field_of_a_composite_key_is_blank(): void
    {
        $key = MatchKey::composite('name_zip', ['name' => new LowercaseTrim, 'zip' => null]);

        $this->assertNotNull($key->valueFor(['name' => 'Smith', 'zip' => '78676'], new RecordReader));
        $this->assertNull($key->valueFor(['name' => 'Smith', 'zip' => ''], new RecordReader));
        $this->assertNull($key->valueFor(['name' => 'Smith'], new RecordReader));
    }

    #[Test]
    public function it_refuses_an_empty_name(): void
    {
        $this->expectException(InvalidConfiguration::class);

        MatchKey::on('email', null, '');
    }

    #[Test]
    public function it_refuses_a_key_without_fields(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[empty]');

        MatchKey::composite('empty', []);
    }
}
