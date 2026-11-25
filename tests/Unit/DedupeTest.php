<?php

declare(strict_types=1);

namespace Keaton\Dedupe\Tests\Unit;

use DateTimeImmutable;
use Keaton\Dedupe\Dedupe;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Normalizers\EmailCanonical;
use Keaton\Dedupe\Normalizers\LowercaseTrim;
use Keaton\Dedupe\Normalizers\PhoneDigits;
use Keaton\Dedupe\Normalizers\TextNormalizer;
use Keaton\Dedupe\Stores\InMemoryAuditStore;
use Keaton\Dedupe\Survivorship;
use Keaton\Dedupe\Tests\Fixtures\NotANormalizer;
use Keaton\Dedupe\Tests\Fixtures\StripLeadingZeros;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DedupeTest extends TestCase
{
    #[Test]
    public function it_can_run_from_a_config_array(): void
    {
        $dedupe = new Dedupe($this->config());

        $result = $dedupe->run([
            ['id' => 1, 'email' => 'jane@gmail.com', 'phone' => '512 555 0100'],
            ['id' => 2, 'email' => 'J.ANE+x@gmail.com', 'phone' => '000'],
            ['id' => 3, 'email' => 'bob@example.com', 'phone' => '(512) 555-0100'],
        ]);

        $two = $result->why('2');
        $three = $result->why('3');

        $this->assertCount(1, $result->survivors);
        $this->assertNotNull($two);
        $this->assertNotNull($three);
        $this->assertSame('email', $two->matchedKey);
        $this->assertSame('1', $two->survivorId);
        $this->assertSame('phone', $three->matchedKey);
    }

    #[Test]
    public function it_reads_survivorship_and_window_from_the_config(): void
    {
        $dedupe = new Dedupe($this->config(['survivorship' => 'last', 'window' => 600]));

        $result = $dedupe->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => 0],
            ['id' => 2, 'email' => 'a@example.com', 'created_at' => 60],
            ['id' => 3, 'email' => 'a@example.com', 'created_at' => 5000],
        ]);

        $this->assertSame(Survivorship::Last, $result->audit[0]->survivorship);
        $this->assertSame(600, $result->audit[0]->windowSeconds);
        $this->assertSame('2', $result->audit[0]->survivorId);
        $this->assertCount(2, $result->survivors);
    }

    /**
     * @return array<string, array{mixed, int|null}>
     */
    public static function windowValues(): array
    {
        return [
            'null' => [null, null],
            'empty env value' => ['', null],
            'integer' => [90, 90],
            'zero' => [0, 0],
            'numeric string from env()' => ['600', 600],
        ];
    }

    #[Test]
    #[DataProvider('windowValues')]
    public function it_accepts_these_window_values(mixed $window, ?int $expected): void
    {
        $result = (new Dedupe($this->config(['window' => $window])))->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => 0],
            ['id' => 2, 'email' => 'a@example.com', 'created_at' => 0],
        ]);

        $this->assertSame($expected, $result->audit[0]->windowSeconds);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badWindows(): array
    {
        return [
            'too big for an int' => ['99999999999999999999'],
            'negative' => [-5],
            'word' => ['soon'],
            'decimal string' => ['1.5'],
            'float' => [12.5],
            'array' => [[60]],
        ];
    }

    #[Test]
    #[DataProvider('badWindows')]
    public function it_refuses_a_bad_window(mixed $window): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The window must be a whole number of seconds');

        (new Dedupe($this->config(['window' => $window])))->deduper();
    }

    #[Test]
    public function it_refuses_an_unknown_survivorship(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[newest]');

        (new Dedupe($this->config(['survivorship' => 'newest'])))->deduper();
    }

    #[Test]
    public function it_refuses_a_config_without_keys(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('At least one match key is required');

        (new Dedupe(['keys' => []]))->deduper();
    }

    #[Test]
    public function it_accepts_the_shorthand_for_raw_fields(): void
    {
        $dedupe = new Dedupe($this->config(['keys' => ['email', 'phone']]));

        $result = $dedupe->run([
            ['id' => 1, 'email' => 'A@example.com', 'phone' => '1'],
            ['id' => 2, 'email' => 'a@example.com', 'phone' => '1'],
        ]);

        // Raw comparison: the email differs by case, so the phone is what matches.
        $this->assertSame('phone', $result->audit[0]->matchedKey);
    }

    #[Test]
    public function it_accepts_normalizer_instances_and_composite_keys(): void
    {
        $dedupe = new Dedupe($this->config(['keys' => [
            'name_zip' => ['last_name' => new LowercaseTrim, 'zip'],
        ]]));

        $result = $dedupe->run([
            ['id' => 1, 'last_name' => 'Smith', 'zip' => '78676'],
            ['id' => 2, 'last_name' => 'SMITH ', 'zip' => '78676'],
        ]);

        $this->assertSame('name_zip', $result->audit[0]->matchedKey);
    }

    #[Test]
    public function it_refuses_a_key_with_a_malformed_definition(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[broken]');

        (new Dedupe($this->config(['keys' => ['broken' => 'email']])))->deduper();
    }

    #[Test]
    public function it_refuses_a_key_definition_with_no_name(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The match key [0] must list at least one field');

        // A field map without a name would otherwise become a key called "0".
        (new Dedupe($this->config(['keys' => [['email' => EmailCanonical::class]]])))->deduper();
    }

    #[Test]
    public function it_refuses_a_class_that_is_not_a_normalizer(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage(NotANormalizer::class);

        (new Dedupe($this->config(['keys' => ['email' => ['email' => NotANormalizer::class]]])))->deduper();
    }

    #[Test]
    public function it_refuses_a_class_that_does_not_exist(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('App\\Missing\\Normalizer');

        (new Dedupe($this->config(['keys' => ['email' => ['email' => 'App\\Missing\\Normalizer']]])))->deduper();
    }

    #[Test]
    public function it_refuses_an_abstract_normalizer_class_without_a_resolver(): void
    {
        // TextNormalizer is abstract: is_a(..., true) accepts it, but `new` would fatal.
        // With no resolver to bind it to a concrete class it cannot be used, so it is refused.
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('['.TextNormalizer::class.']');

        (new Dedupe($this->config(['keys' => ['email' => ['email' => TextNormalizer::class]]])))->deduper();
    }

    #[Test]
    public function it_builds_normalizers_through_the_resolver_when_given_one(): void
    {
        $resolved = [];

        $dedupe = new Dedupe(
            $this->config(['keys' => ['order' => ['ref' => StripLeadingZeros::class]]]),
            null,
            function (string $class) use (&$resolved): object {
                $resolved[] = $class;

                return new $class;
            },
        );

        $result = $dedupe->run([['id' => 1, 'ref' => '00042'], ['id' => 2, 'ref' => '42']]);

        $this->assertSame([StripLeadingZeros::class], $resolved);
        $this->assertSame('order', $result->audit[0]->matchedKey);
    }

    #[Test]
    public function it_refuses_a_resolver_that_returns_something_else(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('['.NotANormalizer::class.']');

        (new Dedupe(
            $this->config(['keys' => ['order' => ['ref' => StripLeadingZeros::class]]]),
            null,
            fn (string $class): object => new NotANormalizer,
        ))->deduper();
    }

    #[Test]
    public function it_persists_and_reads_back_the_audit_trail_through_its_store(): void
    {
        $dedupe = new Dedupe($this->config(), new InMemoryAuditStore);

        $result = $dedupe->run([
            ['id' => 1, 'email' => 'a@example.com'],
            ['id' => 2, 'email' => 'a@example.com'],
        ], 'run-1');

        $this->assertSame($result->audit, $dedupe->audit('run-1'));
        $this->assertSame($result->audit, $dedupe->auditFor(2));
        $this->assertSame([], $dedupe->auditFor('1'));
    }

    #[Test]
    public function it_prunes_old_entries_from_its_store(): void
    {
        $store = new InMemoryAuditStore;
        $dedupe = new Dedupe($this->config(), $store);
        $records = [['id' => 1, 'email' => 'a@example.com'], ['id' => 2, 'email' => 'a@example.com']];

        $dedupe->deduper()->clock(fn (): DateTimeImmutable => new DateTimeImmutable('-40 days'))->run($records, 'old');
        $dedupe->run($records, 'new');

        $this->assertSame(1, $dedupe->prune(30));
        $this->assertSame([], $dedupe->audit('old'));
        $this->assertCount(1, $dedupe->audit('new'));
    }

    #[Test]
    public function it_refuses_to_read_the_trail_when_no_store_is_configured(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('No audit store is configured');

        (new Dedupe($this->config()))->audit('run-1');
    }

    #[Test]
    public function it_refuses_a_negative_retention(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('[-1]');

        (new Dedupe($this->config(), new InMemoryAuditStore))->prune(-1);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function invalidFieldOptions(): array
    {
        return [
            'an id that is a number' => ['id', 5],
            'an id switched off' => ['id', null],
            'an empty id' => ['id', ''],
            'a timestamp that is an array' => ['timestamp', ['created_at']],
        ];
    }

    #[Test]
    #[DataProvider('invalidFieldOptions')]
    public function it_refuses_a_field_option_it_cannot_use(string $key, mixed $value): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage("The config value [dedupe.{$key}]");

        (new Dedupe($this->config([$key => $value])))->deduper();
    }

    #[Test]
    public function it_runs_without_a_timestamp_when_the_option_is_null(): void
    {
        $result = (new Dedupe($this->config(['timestamp' => null])))->run([
            ['id' => 1, 'email' => 'a@example.com', 'created_at' => 'not a date'],
            ['id' => 2, 'email' => 'a@example.com'],
        ]);

        $this->assertSame(1, $result->droppedCount());
        $this->assertNull($result->audit[0]->droppedRecordAt);
    }

    #[Test]
    public function it_refuses_shorthand_that_repeats_a_named_key(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('A match key named [email] is already defined');

        (new Dedupe($this->config(['keys' => ['email' => ['email' => EmailCanonical::class], 'email']])))->deduper();
    }

    #[Test]
    public function it_refuses_a_field_listed_twice_in_one_key(): void
    {
        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('The match key [name_zip]');

        (new Dedupe($this->config(['keys' => ['name_zip' => ['last_name' => LowercaseTrim::class, 'zip', 'last_name']]])))->deduper();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_merge([
            'keys' => [
                'email' => ['email' => EmailCanonical::class],
                'phone' => ['phone' => PhoneDigits::class],
            ],
            'window' => null,
            'survivorship' => 'first',
            'id' => 'id',
            'timestamp' => 'created_at',
        ], $overrides);
    }
}
