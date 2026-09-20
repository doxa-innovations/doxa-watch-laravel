<?php

namespace Doxa\Watch\Console;

use Illuminate\Console\Command;
use Laravel\Nightwatch\Facades\Nightwatch;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Alias of the upstream `nightwatch:status` command.
 *
 * @internal
 */
#[AsCommand(name: 'watch:status', description: 'Get the current status of the Doxa Watch agent.')]
final class StatusCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'watch:status';

    /**
     * @var string
     */
    protected $description = 'Get the current status of the Doxa Watch agent.';

    public function handle(): int
    {
        try {
            // See AgentCommand: health checks run every few seconds and must
            // not be recorded as commands.
            Nightwatch::dontSample();
        } catch (Throwable $e) {
            Nightwatch::unrecoverableExceptionOccurred($e);
        }

        return $this->call('nightwatch:status');
    }
}
