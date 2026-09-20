<?php

namespace Doxa\Watch;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Env;

use function array_map;
use function array_merge;
use function explode;
use function is_array;
use function is_string;
use function trim;

/**
 * Maps the `DOXA_WATCH_*` environment onto the upstream `nightwatch.*` configuration.
 *
 * Upstream captures its configuration once, inside its own service provider's
 * `register()` method. This class therefore has to run before that happens;
 * see `DoxaWatchServiceProvider` and `DOXA.md`.
 *
 * @internal
 */
final class Configuration
{
    public const DEFAULT_BASE_URL = 'https://watch-ingest.doxaplc.com';

    /**
     * Configuration key (below `nightwatch.`) => `DOXA_WATCH_*` variable.
     *
     * Every variable falls back to the `NIGHTWATCH_*` variable upstream reads
     * for the same key, because an unset alias leaves the upstream value alone.
     */
    public const ALIASES = [
        'enabled' => 'DOXA_WATCH_ENABLED',
        'token' => 'DOXA_WATCH_TOKEN',
        'deployment' => 'DOXA_WATCH_DEPLOY',
        'server' => 'DOXA_WATCH_SERVER',
        'capture_exception_source_code' => 'DOXA_WATCH_CAPTURE_EXCEPTION_SOURCE_CODE',
        'capture_request_payload' => 'DOXA_WATCH_CAPTURE_REQUEST_PAYLOAD',
        'sampling.requests' => 'DOXA_WATCH_REQUEST_SAMPLE_RATE',
        'sampling.commands' => 'DOXA_WATCH_COMMAND_SAMPLE_RATE',
        'sampling.exceptions' => 'DOXA_WATCH_EXCEPTION_SAMPLE_RATE',
        'sampling.scheduled_tasks' => 'DOXA_WATCH_SCHEDULED_TASK_SAMPLE_RATE',
        'filtering.ignore_cache_events' => 'DOXA_WATCH_IGNORE_CACHE_EVENTS',
        'filtering.ignore_mail' => 'DOXA_WATCH_IGNORE_MAIL',
        'filtering.ignore_notifications' => 'DOXA_WATCH_IGNORE_NOTIFICATIONS',
        'filtering.ignore_outgoing_requests' => 'DOXA_WATCH_IGNORE_OUTGOING_REQUESTS',
        'filtering.ignore_queries' => 'DOXA_WATCH_IGNORE_QUERIES',
        'filtering.log_level' => 'DOXA_WATCH_LOG_LEVEL',
        'ingest.uri' => 'DOXA_WATCH_INGEST_URI',
        'ingest.timeout' => 'DOXA_WATCH_INGEST_TIMEOUT',
        'ingest.connection_timeout' => 'DOXA_WATCH_INGEST_CONNECTION_TIMEOUT',
        'ingest.event_buffer' => 'DOXA_WATCH_INGEST_EVENT_BUFFER',
    ];

    /**
     * Same as above, for the two keys upstream stores as comma separated lists.
     */
    public const LIST_ALIASES = [
        'redact_payload_fields' => 'DOXA_WATCH_REDACT_PAYLOAD_FIELDS',
        'redact_headers' => 'DOXA_WATCH_REDACT_HEADERS',
    ];

    /**
     * Variables the upstream agent and deploy command read straight from
     * `$_SERVER`, bypassing the configuration repository.
     */
    public const SERVER_ALIASES = [
        'NIGHTWATCH_AGENT_LOG_LEVEL' => 'DOXA_WATCH_AGENT_LOG_LEVEL',
    ];

    /**
     * Checked in order when no deploy value is configured any other way.
     */
    public const DEPLOY_FALLBACKS = ['SOURCE_SHA', 'APP_VERSION', 'GIT_COMMIT'];

    public function __construct(
        private Repository $config,
        private string $upstreamConfigPath,
        private bool $configurationIsCached,
    ) {
        //
    }

    public function apply(): void
    {
        $this->mergeUpstreamDefaults();
        $this->applyAliases();
        $this->applyDeployFallback();
        $this->applyBaseUrl();
        $this->applyServerAliases();
    }

    /**
     * Perform the same shallow merge upstream's `mergeConfigFrom()` is about to
     * do, so that overriding one nested key below (e.g. `sampling.requests`)
     * cannot hide its siblings from that merge.
     */
    private function mergeUpstreamDefaults(): void
    {
        if ($this->configurationIsCached) {
            return;
        }

        $current = $this->config->get('nightwatch', []);

        $this->config->set('nightwatch', array_merge(
            require $this->upstreamConfigPath,
            is_array($current) ? $current : [],
        ));
    }

    private function applyAliases(): void
    {
        foreach (self::ALIASES as $key => $variable) {
            $value = Env::get($variable);

            if (self::isSet($value)) {
                $this->config->set("nightwatch.{$key}", $value);
            }
        }

        foreach (self::LIST_ALIASES as $key => $variable) {
            $value = Env::get($variable);

            if (is_string($value) && self::isSet($value)) {
                $this->config->set("nightwatch.{$key}", array_map(trim(...), explode(',', $value)));
            }
        }
    }

    private function applyDeployFallback(): void
    {
        if (self::isSet($this->config->get('nightwatch.deployment'))) {
            return;
        }

        foreach (self::DEPLOY_FALLBACKS as $variable) {
            $value = Env::get($variable);

            if (self::isSet($value)) {
                $this->config->set('nightwatch.deployment', $value);

                return;
            }
        }
    }

    /**
     * Upstream has no configuration key for the base URL: the agent PHAR and the
     * deploy command both read `$_SERVER['NIGHTWATCH_BASE_URL']` directly. We
     * resolve the value here and publish it under that name. It is also kept in
     * the configuration repository so `config:cache` preserves a value that
     * only exists in `.env`.
     */
    private function applyBaseUrl(): void
    {
        $baseUrl = self::DEFAULT_BASE_URL;

        foreach ([
            Env::get('DOXA_WATCH_BASE_URL'),
            Env::get('NIGHTWATCH_BASE_URL'),
            $this->config->get('nightwatch.base_url'),
        ] as $candidate) {
            if (is_string($candidate) && self::isSet($candidate)) {
                $baseUrl = $candidate;

                break;
            }
        }

        $this->config->set('nightwatch.base_url', $baseUrl);

        $_SERVER['NIGHTWATCH_BASE_URL'] = $baseUrl;
    }

    private function applyServerAliases(): void
    {
        foreach (self::SERVER_ALIASES as $upstream => $variable) {
            $value = Env::get($variable);

            if (is_string($value) && self::isSet($value)) {
                $_SERVER[$upstream] = $value;
            }
        }
    }

    private static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
