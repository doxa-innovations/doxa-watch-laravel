<?php

namespace Doxa\Watch\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

use function array_filter;
use function config;

/**
 * Alias of the upstream `nightwatch:deploy` command.
 *
 * @internal
 */
#[AsCommand(name: 'watch:deploy', description: 'Send deployment metadata to Doxa Watch.')]
final class DeployCommand extends Command
{
    /**
     * Mirrors the upstream signature so every argument and option can be forwarded.
     *
     * @var string
     */
    protected $signature = 'watch:deploy
        {deploy? : A unique value for the deploy <comment>[default: `DOXA_WATCH_DEPLOY`]</comment>}
        {--ref= : The git ref (tag or hash) of the deploy}
        {--name= : The human-readable name of the deploy}
        {--url= : A URL with information related to the deploy}
        {--timestamp= : The timestamp of the deploy <comment>[default: `now()`]</comment>}';

    /**
     * @var string
     */
    protected $description = 'Send deployment metadata to Doxa Watch.';

    public function handle(): int
    {
        // Upstream performs the same two checks, but its messages name the
        // upstream variables. Like upstream, a misconfiguration must not fail
        // a deployment pipeline, hence the zero exit code.
        if (! ($this->argument('deploy') ?? config('nightwatch.deployment'))) {
            $this->components->error('Please configure the [DOXA_WATCH_DEPLOY] environment variable.');

            return 0;
        }

        if (! config('nightwatch.token')) {
            $this->components->error('Please configure the [DOXA_WATCH_TOKEN] environment variable.');

            return 0;
        }

        return $this->call('nightwatch:deploy', array_filter([
            'deploy' => $this->argument('deploy'),
            '--ref' => $this->option('ref'),
            '--name' => $this->option('name'),
            '--url' => $this->option('url'),
            '--timestamp' => $this->option('timestamp'),
        ], static fn ($value) => $value !== null));
    }
}
