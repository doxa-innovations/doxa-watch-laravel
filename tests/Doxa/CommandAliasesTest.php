<?php

namespace Tests\Doxa;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\WithEnv;
use RuntimeException;
use Tests\FakeIngest;

use function array_keys;
use function file_get_contents;
use function json_decode;

class CommandAliasesTest extends TestCase
{
    public function test_aliases_are_registered_alongside_the_upstream_commands(): void
    {
        $commands = Artisan::all();

        foreach (['agent', 'status', 'deploy'] as $command) {
            $this->assertArrayHasKey("watch:{$command}", $commands);
            $this->assertArrayHasKey("nightwatch:{$command}", $commands, 'Existing supervisor programs call the upstream name.');
        }
    }

    public function test_alias_descriptions_do_not_use_the_upstream_product_name(): void
    {
        foreach (['agent', 'status', 'deploy'] as $command) {
            $this->assertStringNotContainsStringIgnoringCase('nightwatch', Artisan::all()["watch:{$command}"]->getDescription());
        }
    }

    public function test_the_agent_alias_accepts_every_upstream_option(): void
    {
        $commands = Artisan::all();

        $this->assertEqualsCanonicalizing(
            array_keys($commands['nightwatch:agent']->getDefinition()->getOptions()),
            array_keys($commands['watch:agent']->getDefinition()->getOptions()),
            'Upstream changed the options of nightwatch:agent: mirror them in Doxa\Watch\Console\AgentCommand.',
        );
    }

    public function test_the_deploy_alias_accepts_every_upstream_argument_and_option(): void
    {
        $commands = Artisan::all();

        $this->assertEqualsCanonicalizing(
            array_keys($commands['nightwatch:deploy']->getDefinition()->getOptions()),
            array_keys($commands['watch:deploy']->getDefinition()->getOptions()),
        );
        $this->assertSame(
            array_keys($commands['nightwatch:deploy']->getDefinition()->getArguments()),
            array_keys($commands['watch:deploy']->getDefinition()->getArguments()),
        );
    }

    public function test_the_status_alias_pings_the_agent(): void
    {
        $ingest = $this->fakeIngest();

        $this->artisan('watch:status')->assertExitCode(0);

        $ingest->assertWrittenTimes(1);
    }

    public function test_the_status_alias_reports_failure(): void
    {
        $this->fakeIngest(static fn ($ingest, $streams) => new class($ingest, $streams) extends FakeIngest
        {
            public function ping(): void
            {
                throw new RuntimeException('Whoops!');
            }
        });

        $this->artisan('watch:status')
            ->expectsOutputToContain('Whoops!')
            ->assertExitCode(1);
    }

    public function test_the_status_and_agent_aliases_are_never_sampled(): void
    {
        $this->fakeIngest();
        $this->core->sample(1.0);

        $this->artisan('watch:status')->assertExitCode(0);

        $this->assertFalse($this->core->sampling());
    }

    #[WithEnv('DOXA_WATCH_TOKEN', 'doxa-token')]
    public function test_the_deploy_alias_forwards_arguments_and_options(): void
    {
        Http::fake(['*' => Http::response('OK')]);

        $this->artisan('watch:deploy', [
            'deploy' => 'v1.2.3',
            '--ref' => 'abcd1234',
            '--name' => 'Release 1.2.3',
            '--url' => 'https://example.test/releases/1.2.3',
            '--timestamp' => '2025-12-22 15:30:45.123456',
        ])->assertExitCode(0);

        Http::assertSent(static fn (Request $request) => $request->url() === 'https://watch-ingest.doxaplc.com/api/deployments'
            && $request->header('Authorization') === ['Bearer doxa-token']
            && $request->data() === [
                'timestamp' => '2025-12-22 15:30:45.123456',
                'deploy' => 'v1.2.3',
                'ref' => 'abcd1234',
                'name' => 'Release 1.2.3',
                'url' => 'https://example.test/releases/1.2.3',
            ]);
    }

    #[WithEnv('DOXA_WATCH_TOKEN', 'doxa-token')]
    #[WithEnv('SOURCE_SHA', 'source-sha')]
    public function test_the_deploy_alias_uses_the_detected_deploy(): void
    {
        Http::fake(['*' => Http::response('OK')]);

        $this->artisan('watch:deploy')->assertExitCode(0);

        Http::assertSent(static fn (Request $request) => $request['deploy'] === 'source-sha');
    }

    #[WithEnv('DOXA_WATCH_TOKEN', 'doxa-token')]
    public function test_the_deploy_alias_names_the_doxa_variable_when_no_deploy_is_known(): void
    {
        Http::fake();

        $this->artisan('watch:deploy')
            ->expectsOutputToContain('DOXA_WATCH_DEPLOY')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_the_deploy_alias_names_the_doxa_variable_when_no_token_is_known(): void
    {
        Http::fake();

        $this->artisan('watch:deploy', ['deploy' => 'v1.2.3'])
            ->expectsOutputToContain('DOXA_WATCH_TOKEN')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_composer_registers_the_doxa_provider_before_the_upstream_provider(): void
    {
        $composer = json_decode(file_get_contents(__DIR__.'/../../composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame([
            'Doxa\\Watch\\DoxaWatchServiceProvider',
            'Laravel\\Nightwatch\\NightwatchServiceProvider',
        ], $composer['extra']['laravel']['providers']);
        $this->assertSame(['laravel/nightwatch' => 'self.version'], $composer['replace']);
        $this->assertSame('doxa/watch', $composer['name']);
    }
}
