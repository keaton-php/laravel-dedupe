<?php

declare(strict_types=1);

namespace Keaton\Dedupe;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\ServiceProvider;
use Keaton\Dedupe\Exceptions\InvalidConfiguration;
use Keaton\Dedupe\Stores\DatabaseAuditStore;

class DedupeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dedupe.php', 'dedupe');

        $this->app->bind(DatabaseAuditStore::class, function (Application $app): DatabaseAuditStore {
            $config = $app->make(Repository::class);

            return new DatabaseAuditStore(
                $app->make(ConnectionResolverInterface::class),
                DatabaseAuditStore::tableFrom($config->get('dedupe.audit.table', 'dedupe_audit_entries')),
                DatabaseAuditStore::connectionFrom($config->get('dedupe.audit.connection')),
                $this->flag($config, 'dedupe.audit.store_values', false),
            );
        });

        $this->app->bind(Dedupe::class, function (Application $app): Dedupe {
            $config = $app->make(Repository::class);

            $settings = $config->get('dedupe', []);
            $store = $this->flag($config, 'dedupe.audit.enabled', true) ? $app->make(DatabaseAuditStore::class) : null;

            return new Dedupe(
                is_array($settings) ? $settings : [],
                $store,
                fn (string $class): object => $app->make($class),
            );
        });
    }

    /**
     * A true/false setting. Accepts what env() and a config file produce:
     * booleans, and "true"/"false"/"1"/"0"/"on"/"off"/"yes"/"no".
     */
    protected function flag(Repository $config, string $key, bool $default): bool
    {
        $value = $config->get($key, $default);

        if ($value === null || $value === '') {
            return $default;
        }

        $flag = is_bool($value) ? $value : filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $flag ?? throw InvalidConfiguration::invalidOption($key, is_scalar($value) ? var_export($value, true) : get_debug_type($value));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/dedupe.php' => config_path('dedupe.php'),
            ], 'dedupe-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'dedupe-migrations');
        }
    }
}
