<?php

namespace Tests\Doxa;

use Doxa\Watch\DoxaWatchServiceProvider;
use Laravel\Nightwatch\NightwatchServiceProvider;
use ReflectionProperty;
use Tests\TestCase as UpstreamTestCase;

use function putenv;

abstract class TestCase extends UpstreamTestCase
{
    protected function setUp(): void
    {
        $this->forgetServerVariables();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->forgetServerVariables();
    }

    /**
     * The order matters and mirrors `extra.laravel.providers` in composer.json.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            DoxaWatchServiceProvider::class,
            NightwatchServiceProvider::class,
        ];
    }

    /**
     * The Doxa layer publishes values through `$_SERVER`, which outlives the
     * application instance and would otherwise leak between tests.
     */
    private function forgetServerVariables(): void
    {
        foreach (['NIGHTWATCH_BASE_URL', 'NIGHTWATCH_AGENT_LOG_LEVEL'] as $variable) {
            unset($_SERVER[$variable], $_ENV[$variable]);

            putenv($variable);
        }
    }

    protected function readPrivate(object $object, string $property): mixed
    {
        return (new ReflectionProperty($object, $property))->getValue($object);
    }
}
