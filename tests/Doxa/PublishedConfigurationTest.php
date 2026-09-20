<?php

namespace Tests\Doxa;

use Orchestra\Testbench\Attributes\WithEnv;

use function config;

/**
 * An application that ran `vendor:publish` has its own `config/nightwatch.php`,
 * which only knows the upstream variable names.
 */
class PublishedConfigurationTest extends TestCase
{
    /**
     * Runs after the configuration files are loaded and before any service
     * provider is registered, which is when a published file would be read.
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('nightwatch', [
            'token' => 'published-token',
            'sampling' => [
                'requests' => 0.1,
                'commands' => 0.2,
            ],
        ]);
    }

    #[WithEnv('DOXA_WATCH_REQUEST_SAMPLE_RATE', '0.9')]
    public function test_aliases_override_a_published_configuration_file(): void
    {
        $this->assertEquals(0.9, $this->core->config['sampling']['requests']);
        $this->assertEquals(0.2, $this->core->config['sampling']['commands'], 'Published sibling values must survive.');
        $this->assertSame('published-token', config('nightwatch.token'));
    }

    public function test_a_published_configuration_file_is_left_alone_without_aliases(): void
    {
        $this->assertEquals(0.1, $this->core->config['sampling']['requests']);
        $this->assertEquals(0.2, $this->core->config['sampling']['commands']);
        $this->assertSame('published-token', config('nightwatch.token'));
    }
}
