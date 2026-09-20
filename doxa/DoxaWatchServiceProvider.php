<?php

namespace Doxa\Watch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Facades\Nightwatch;
use Throwable;

/**
 * The Doxa layer on top of the upstream package.
 *
 * This provider MUST be registered before
 * `Laravel\Nightwatch\NightwatchServiceProvider`, which reads its configuration
 * once during `register()`. Package auto-discovery guarantees that through the
 * order of `extra.laravel.providers` in `composer.json`.
 *
 * @internal
 */
final class DoxaWatchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        try {
            (new Configuration(
                config: $this->app->make(Repository::class),
                upstreamConfigPath: __DIR__.'/../config/nightwatch.php',
                configurationIsCached: $this->app instanceof CachesConfiguration && $this->app->configurationIsCached(),
            ))->apply();
        } catch (Throwable $e) {
            // Monitoring must never take the host application down.
            Nightwatch::unrecoverableExceptionOccurred($e);
        }
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\AgentCommand::class,
            Console\StatusCommand::class,
            Console\DeployCommand::class,
        ]);
    }
}
