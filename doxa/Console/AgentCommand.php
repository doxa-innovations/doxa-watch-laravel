<?php

namespace Doxa\Watch\Console;

use Illuminate\Console\Command;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

use function array_filter;

/**
 * Alias of the upstream `nightwatch:agent` command.
 *
 * @internal
 */
#[AsCommand(name: 'watch:agent', description: 'Run the Doxa Watch agent.')]
final class AgentCommand extends Command
{
    /**
     * Mirrors the upstream signature so every option can be forwarded.
     *
     * @var string
     */
    protected $signature = 'watch:agent
        {--listen-on=}
        {--auth-connection-timeout=}
        {--auth-timeout=}
        {--ingest-connection-timeout=}
        {--ingest-timeout=}
        {--server=}
        {--silent : Do not output any message}';

    /**
     * @var string
     */
    protected $description = 'Run the Doxa Watch agent.';

    public function handle(): int
    {
        try {
            // Upstream never samples its own long-running commands. It matches
            // them by name, so the alias has to opt out explicitly.
            Nightwatch::dontSample();
        } catch (Throwable $e) {
            Nightwatch::unrecoverableExceptionOccurred($e);
        }

        return $this->call('nightwatch:agent', array_filter([
            '--listen-on' => $this->option('listen-on'),
            '--auth-connection-timeout' => $this->option('auth-connection-timeout'),
            '--auth-timeout' => $this->option('auth-timeout'),
            '--ingest-connection-timeout' => $this->option('ingest-connection-timeout'),
            '--ingest-timeout' => $this->option('ingest-timeout'),
            '--server' => $this->option('server'),
            '--silent' => $this->option('silent'),
            '--quiet' => $this->option('quiet'),
            '--verbose' => $this->option('verbose'),
        ], static fn ($value) => $value !== null && $value !== false));
    }
}
