<?php

namespace Doxa\Watch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use ReflectionProperty;
use Throwable;

use function dirname;

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
        try {
            $this->markPackageFilesAsInternal();
        } catch (Throwable $e) {
            Nightwatch::unrecoverableExceptionOccurred($e);
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            Console\AgentCommand::class,
            Console\StatusCommand::class,
            Console\DeployCommand::class,
        ]);
    }

    /**
     * Upstream recognises its own stack frames by the hard-coded install path
     * `vendor/laravel/nightwatch`. This package installs to `vendor/doxa/watch`,
     * so without this its middleware frames could be reported as the location
     * of queries issued by framework code (e.g. the database session driver).
     */
    private function markPackageFilesAsInternal(): void
    {
        if (! $this->app->bound(Core::class)) {
            return;
        }

        $location = $this->app->make(Core::class)->sensor->location;

        (new ReflectionProperty($location, 'nightwatchPath'))->setValue($location, dirname(__DIR__));
    }
}
