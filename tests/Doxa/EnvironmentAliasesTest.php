<?php

namespace Tests\Doxa;

use Doxa\Watch\Configuration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Attributes\WithEnv;

use function array_is_list;
use function array_keys;
use function config;
use function hash;
use function is_array;
use function sort;
use function substr;

class EnvironmentAliasesTest extends TestCase
{
    #[WithEnv('DOXA_WATCH_TOKEN', 'doxa-token')]
    #[WithEnv('NIGHTWATCH_TOKEN', 'upstream-token')]
    public function test_the_doxa_token_wins_over_the_upstream_token(): void
    {
        $this->assertSame('doxa-token', config('nightwatch.token'));
        $this->assertSame($this->hashToken('doxa-token'), $this->readPrivate($this->core->ingest, 'tokenHash'));
    }

    #[WithEnv('NIGHTWATCH_TOKEN', 'upstream-token')]
    public function test_it_falls_back_to_the_upstream_token(): void
    {
        $this->assertSame('upstream-token', config('nightwatch.token'));
        $this->assertSame($this->hashToken('upstream-token'), $this->readPrivate($this->core->ingest, 'tokenHash'));
    }

    #[WithEnv('DOXA_WATCH_TOKEN', '')]
    #[WithEnv('NIGHTWATCH_TOKEN', 'upstream-token')]
    public function test_an_empty_alias_does_not_mask_the_upstream_value(): void
    {
        $this->assertSame('upstream-token', config('nightwatch.token'));
    }

    #[WithEnv('DOXA_WATCH_REQUEST_SAMPLE_RATE', '0.25')]
    #[WithEnv('NIGHTWATCH_REQUEST_SAMPLE_RATE', '0.5')]
    #[WithEnv('NIGHTWATCH_COMMAND_SAMPLE_RATE', '0.75')]
    #[WithEnv('DOXA_WATCH_EXCEPTION_SAMPLE_RATE', '0.1')]
    #[WithEnv('DOXA_WATCH_SCHEDULED_TASK_SAMPLE_RATE', '0')]
    public function test_sample_rates_are_aliased_without_hiding_their_siblings(): void
    {
        $sampling = $this->core->config['sampling'];

        $this->assertEquals(0.25, $sampling['requests']);
        $this->assertEquals(0.75, $sampling['commands'], 'The upstream variable of a sibling key must survive.');
        $this->assertEquals(0.1, $sampling['exceptions']);
        $this->assertEquals(0.0, $sampling['scheduled_tasks'], 'A rate of zero is a value, not "unset".');
    }

    #[WithEnv('DOXA_WATCH_ENABLED', 'false')]
    #[WithEnv('NIGHTWATCH_ENABLED', 'true')]
    public function test_it_can_be_disabled(): void
    {
        $this->assertFalse($this->core->enabled());
    }

    #[WithEnv('DOXA_WATCH_LOG_LEVEL', 'error')]
    #[WithEnv('NIGHTWATCH_LOG_LEVEL', 'info')]
    public function test_the_log_level_is_aliased(): void
    {
        $this->assertSame('error', config('nightwatch.filtering.log_level'));
        $this->assertSame('error', config('logging.channels.nightwatch.level'));
    }

    #[WithEnv('NIGHTWATCH_LOG_LEVEL', 'warning')]
    public function test_the_log_level_falls_back_to_the_upstream_variable(): void
    {
        $this->assertSame('warning', config('logging.channels.nightwatch.level'));
    }

    #[WithEnv('DOXA_WATCH_DEPLOY', 'doxa-deploy')]
    #[WithEnv('NIGHTWATCH_DEPLOY', 'upstream-deploy')]
    #[WithEnv('DOXA_WATCH_SERVER', 'doxa-server')]
    #[WithEnv('NIGHTWATCH_SERVER', 'upstream-server')]
    public function test_deploy_and_server_are_aliased(): void
    {
        $this->assertSame('doxa-deploy', $this->core->executionState->deploy);
        $this->assertSame('doxa-server', $this->core->executionState->server);
    }

    #[WithEnv('DOXA_WATCH_INGEST_URI', '127.0.0.1:9999')]
    #[WithEnv('NIGHTWATCH_INGEST_URI', '127.0.0.1:1111')]
    #[WithEnv('NIGHTWATCH_INGEST_EVENT_BUFFER', '42')]
    public function test_the_ingest_uri_is_aliased(): void
    {
        $this->assertSame('tcp://127.0.0.1:9999', $this->readPrivate($this->core->ingest, 'transmitTo'));
        $this->assertEquals(42, config('nightwatch.ingest.event_buffer'), 'The upstream variable of a sibling key must survive.');
    }

    #[WithEnv('DOXA_WATCH_REDACT_HEADERS', 'Authorization, X-Api-Key')]
    public function test_list_values_are_aliased(): void
    {
        $this->assertSame(['Authorization', 'X-Api-Key'], config('nightwatch.redact_headers'));
        $this->assertSame(['_token', 'password', 'password_confirmation'], config('nightwatch.redact_payload_fields'));
    }

    public function test_every_upstream_configuration_key_has_an_alias(): void
    {
        $upstream = require __DIR__.'/../../config/nightwatch.php';
        $keys = [];

        foreach ($upstream as $key => $value) {
            if (is_array($value) && ! array_is_list($value)) {
                foreach ($value as $nested => $_) {
                    $keys[] = "{$key}.{$nested}";
                }
            } else {
                $keys[] = $key;
            }
        }

        $aliased = [...array_keys(Configuration::ALIASES), ...array_keys(Configuration::LIST_ALIASES)];

        sort($keys);
        sort($aliased);

        $this->assertSame($keys, $aliased, 'Upstream added or removed a configuration key: update Doxa\Watch\Configuration and DOXA.md.');
    }

    public function test_the_base_url_defaults_to_doxa_watch(): void
    {
        $this->assertSame('https://watch-ingest.doxaplc.com', Configuration::DEFAULT_BASE_URL);
        $this->assertSame(Configuration::DEFAULT_BASE_URL, $_SERVER['NIGHTWATCH_BASE_URL']);
        $this->assertSame(Configuration::DEFAULT_BASE_URL, config('nightwatch.base_url'));
    }

    #[WithEnv('DOXA_WATCH_BASE_URL', 'https://doxa.example.test')]
    #[WithEnv('NIGHTWATCH_BASE_URL', 'https://upstream.example.test')]
    public function test_the_doxa_base_url_wins_over_the_upstream_base_url(): void
    {
        $this->assertSame('https://doxa.example.test', $_SERVER['NIGHTWATCH_BASE_URL']);
    }

    #[WithEnv('NIGHTWATCH_BASE_URL', 'https://upstream.example.test')]
    public function test_it_falls_back_to_the_upstream_base_url(): void
    {
        $this->assertSame('https://upstream.example.test', $_SERVER['NIGHTWATCH_BASE_URL']);
    }

    #[WithEnv('DOXA_WATCH_BASE_URL', 'https://doxa.example.test//')]
    public function test_trailing_slashes_are_removed_from_the_base_url(): void
    {
        // The agent trims the base URL itself, but upstream's deploy command appends "/api/deployments" verbatim.
        $this->assertSame('https://doxa.example.test', $_SERVER['NIGHTWATCH_BASE_URL']);
        $this->assertSame('https://doxa.example.test', config('nightwatch.base_url'));
    }

    #[WithEnv('NIGHTWATCH_BASE_URL', 'https://upstream.example.test/')]
    public function test_trailing_slashes_are_removed_from_the_upstream_base_url(): void
    {
        $this->assertSame('https://upstream.example.test', $_SERVER['NIGHTWATCH_BASE_URL']);
    }

    #[WithEnv('DOXA_WATCH_AGENT_LOG_LEVEL', 'verbose')]
    public function test_the_agent_log_level_is_published_for_the_agent(): void
    {
        $this->assertSame('verbose', $_SERVER['NIGHTWATCH_AGENT_LOG_LEVEL']);
    }

    #[WithEnv('DOXA_WATCH_TOKEN', 'doxa-token')]
    #[WithEnv('DOXA_WATCH_DEPLOY', 'v1.2.3')]
    public function test_the_upstream_deploy_command_reports_to_doxa_watch_with_the_doxa_token(): void
    {
        Http::fake(['*' => Http::response('OK')]);

        $this->artisan('nightwatch:deploy')->assertExitCode(0);

        Http::assertSent(static fn (Request $request) => $request->url() === 'https://watch-ingest.doxaplc.com/api/deployments'
            && $request->header('Authorization') === ['Bearer doxa-token']
            && $request['deploy'] === 'v1.2.3');
    }

    private function hashToken(string $token): string
    {
        return substr(hash('xxh128', $token), 0, 7);
    }
}
