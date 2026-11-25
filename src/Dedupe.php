<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

use Closure;
use DateTimeImmutable;
use Keaton\Dedupe\Contracts\AuditStore;
use Keaton\Dedupe\Contracts\Normalizer;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use ReflectionClass;

/**
 * The configured entry point: builds a Deduper from the config array and runs it.
 * Use the Deduper directly when you want to configure everything in code.
 */
class Dedupe
{
    /**
     * @param  array<string, mixed>  $config  The contents of config/dedupe.php.
     * @param  (Closure(string): object)|null  $resolver  Builds normalizers named by class, e.g. through the container.
     */
    public function __construct(
        protected array $config = [],
        protected ?AuditStore $store = null,
        protected ?Closure $resolver = null,
    ) {
        //
    }

    /**
     * A fresh Deduper configured from the config array.
     */
    public function deduper(): Deduper
    {
        $deduper = Deduper::make()
            ->survivorship($this->survivorship())
            ->window($this->window())
            ->id($this->field('id', required: true) ?? 'id')
            ->timestamp($this->field('timestamp', required: false))
            ->auditTo($this->store);

        foreach ($this->keyDefinitions() as $name => $fields) {
            $deduper->compositeKey($name, $this->normalizers($name, $fields));
        }

        return $deduper;
    }

    /**
     * @param  iterable<mixed>  $records
     */
    public function run(iterable $records, ?string $runId = null): DedupeResult
    {
        return $this->deduper()->run($records, $runId);
    }

    /**
     * Everything one run dropped, from the persisted audit trail.
     *
     * @return list<AuditEntry>
     */
    public function audit(string $runId): array
    {
        return $this->auditStore()->forRun($runId);
    }

    /**
     * Every time this record id was dropped, across runs.
     *
     * @return list<AuditEntry>
     */
    public function auditFor(string|int $recordId): array
    {
        return $this->auditStore()->forDroppedRecord((string) $recordId);
    }

    /**
     * Delete audit entries older than the given number of days.
     */
    public function prune(int $olderThanDays): int
    {
        if ($olderThanDays < 0) {
            throw InvalidConfiguration::invalidRetention($olderThanDays);
        }

        return $this->auditStore()->prune(new DateTimeImmutable("-{$olderThanDays} days"));
    }

    protected function auditStore(): AuditStore
    {
        return $this->store ?? throw InvalidConfiguration::auditDisabled();
    }

    protected function survivorship(): Survivorship
    {
        $value = $this->config['survivorship'] ?? Survivorship::First;

        if ($value instanceof Survivorship) {
            return $value;
        }

        $survivorship = is_string($value) ? Survivorship::tryFrom($value) : null;

        return $survivorship ?? throw InvalidConfiguration::invalidSurvivorship(is_scalar($value) ? (string) $value : get_debug_type($value));
    }

    protected function window(): ?int
    {
        $value = $this->config['window'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        // Environment variables arrive as strings.
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $seconds = filter_var($value, FILTER_VALIDATE_INT);

            if (is_int($seconds)) {
                return $seconds;
            }
        }

        throw InvalidConfiguration::invalidWindow(is_scalar($value) ? (string) $value : get_debug_type($value));
    }

    /**
     * A field name from the config. Missing means the default; a required
     * field cannot be switched off with null or ''.
     */
    protected function field(string $key, bool $required): ?string
    {
        if (! array_key_exists($key, $this->config)) {
            return null;
        }

        $value = $this->config[$key];

        if (! $required && ($value === null || $value === '')) {
            return null;
        }

        return is_string($value) && $value !== ''
            ? $value
            : throw InvalidConfiguration::invalidOption("dedupe.{$key}", is_scalar($value) ? var_export($value, true) : get_debug_type($value));
    }

    /**
     * @return array<string, mixed> match key name => its fields
     */
    protected function keyDefinitions(): array
    {
        $keys = $this->config['keys'] ?? null;

        if (! is_array($keys) || $keys === []) {
            throw InvalidConfiguration::noMatchKeys();
        }

        $definitions = [];

        foreach ($keys as $name => $fields) {
            // Shorthand: ['email', 'phone'] means two keys that compare the raw field.
            if (is_int($name) && is_string($fields)) {
                [$name, $fields] = [$fields, [$fields]];
            }

            if (is_int($name)) {
                throw InvalidConfiguration::invalidKeyDefinition((string) $name);
            }

            if (array_key_exists($name, $definitions)) {
                throw InvalidConfiguration::duplicateKeyName($name);
            }

            $definitions[$name] = $fields;
        }

        return $definitions;
    }

    /**
     * @return array<string, Normalizer|null>
     */
    protected function normalizers(string $keyName, mixed $fields): array
    {
        if (! is_array($fields)) {
            throw InvalidConfiguration::invalidKeyDefinition($keyName);
        }

        $resolved = [];

        foreach ($fields as $field => $spec) {
            // Shorthand: ['email'] means the raw field with no normalizer.
            if (is_int($field)) {
                if (! is_string($spec)) {
                    throw InvalidConfiguration::invalidKeyDefinition($keyName);
                }

                $field = $spec;
                $spec = null;
            }

            if (array_key_exists($field, $resolved)) {
                throw InvalidConfiguration::invalidKeyDefinition($keyName);
            }

            $resolved[$field] = $this->normalizer($spec);
        }

        return $resolved;
    }

    protected function normalizer(mixed $spec): ?Normalizer
    {
        if ($spec === null || $spec instanceof Normalizer) {
            return $spec;
        }

        if (! is_string($spec) || ! is_a($spec, Normalizer::class, true)) {
            throw InvalidConfiguration::unknownNormalizer(is_string($spec) ? $spec : get_debug_type($spec));
        }

        if ($this->resolver !== null) {
            $normalizer = ($this->resolver)($spec);
        } elseif ((new ReflectionClass($spec))->isInstantiable()) {
            $normalizer = new $spec;
        } else {
            // An abstract class or interface satisfies is_a() but cannot be instantiated;
            // with no resolver to bind it to a concrete class, it is a config value the
            // package cannot use, so refuse it rather than fatal on `new`.
            throw InvalidConfiguration::unknownNormalizer($spec);
        }

        return $normalizer instanceof Normalizer ? $normalizer : throw InvalidConfiguration::unknownNormalizer(get_debug_type($normalizer));
    }
}
