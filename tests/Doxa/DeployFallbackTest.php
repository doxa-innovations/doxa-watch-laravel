<?php

namespace Tests\Doxa;

use Orchestra\Testbench\Attributes\WithEnv;

use function config;

class DeployFallbackTest extends TestCase
{
    public function test_the_deploy_is_empty_when_nothing_is_configured(): void
    {
        $this->assertSame('', $this->core->executionState->deploy);
    }

    #[WithEnv('SOURCE_SHA', 'source-sha')]
    #[WithEnv('APP_VERSION', 'app-version')]
    #[WithEnv('GIT_COMMIT', 'git-commit')]
    public function test_source_sha_is_preferred(): void
    {
        $this->assertSame('source-sha', config('nightwatch.deployment'));
        $this->assertSame('source-sha', $this->core->executionState->deploy);
    }

    #[WithEnv('SOURCE_SHA', '')]
    #[WithEnv('APP_VERSION', 'app-version')]
    #[WithEnv('GIT_COMMIT', 'git-commit')]
    public function test_app_version_is_used_when_source_sha_is_empty(): void
    {
        $this->assertSame('app-version', $this->core->executionState->deploy);
    }

    #[WithEnv('GIT_COMMIT', 'git-commit')]
    public function test_git_commit_is_the_last_resort(): void
    {
        $this->assertSame('git-commit', $this->core->executionState->deploy);
    }

    #[WithEnv('DOXA_WATCH_DEPLOY', 'doxa-deploy')]
    #[WithEnv('SOURCE_SHA', 'source-sha')]
    public function test_an_explicit_doxa_deploy_wins_over_the_fallback(): void
    {
        $this->assertSame('doxa-deploy', $this->core->executionState->deploy);
    }

    #[WithEnv('NIGHTWATCH_DEPLOY', 'upstream-deploy')]
    #[WithEnv('SOURCE_SHA', 'source-sha')]
    public function test_an_explicit_upstream_deploy_wins_over_the_fallback(): void
    {
        $this->assertSame('upstream-deploy', $this->core->executionState->deploy);
    }

    #[WithEnv('FORGE_DEPLOY_COMMIT', 'forge-commit')]
    #[WithEnv('SOURCE_SHA', 'source-sha')]
    public function test_upstream_platform_detection_wins_over_the_fallback(): void
    {
        $this->assertSame('forge-commit', $this->core->executionState->deploy);
    }
}
