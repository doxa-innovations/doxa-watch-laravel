# doxa/watch — the Doxa Watch collector for Laravel

This repository is a **minimal-diff fork of [`laravel/nightwatch`](https://github.com/laravel/nightwatch)** (MIT). It ships the
same sensors and the same local agent, and adds a thin Doxa layer so that Laravel applications report to a self-hosted
Doxa Watch server instead of the upstream hosted service.

| | |
|---|---|
| Composer name | `doxa/watch` (declares `"replace": {"laravel/nightwatch": "self.version"}`, so the two can never be installed together) |
| Forked from | tag `v1.30.2`, commit `123f0f24e3e3f4b732f04cdac3a0c63156f8efec` |
| Branch | `doxa/main` (upstream's `1.x` is never committed to) |
| Remotes | `upstream` → `https://github.com/laravel/nightwatch.git`. There is deliberately no `origin` yet. |
| Wire protocol | [`PROTOCOL.md`](PROTOCOL.md) — what the Doxa Watch server implements |
| Licence | MIT. `LICENSE.md` keeps the original copyright notice; Doxa's line is added beneath it. |

The PHP namespace `Laravel\Nightwatch`, the `Nightwatch` facade, the `nightwatch.*` configuration keys, the `nightwatch` log channel
and the `nightwatch:*` Artisan commands are all **unchanged**. An application can swap `laravel/nightwatch` for `doxa/watch`
without touching its code, its published config file, or its supervisor programs.

---

## 1. Diff policy

> Never edit a file that exists upstream unless there is no seam. Put everything Doxa-specific in a new file.
> Every exception is listed in the table below, with the reason. If the table grows, something is wrong.

**Upstream files modified (2):**

| File | +/− | Why it could not be avoided |
|---|---|---|
| `composer.json` | +11 / −8 | Package name and description; `replace`; PSR-4 entry for `Doxa\Watch\` → `doxa/`; `Doxa\Watch\DoxaWatchServiceProvider` listed **before** the upstream provider for auto-discovery; `test:doxa` script. Upstream's `homepage`/`support` links were removed so nobody files Doxa issues upstream. `authors`, `require`, `require-dev`, upstream `autoload` and every upstream script are untouched. |
| `LICENSE.md` | +1 / −0 | Doxa copyright line beneath the original notice (required by the fork's own rules; the original line is intact). |

**Not modified:** everything under `src/`, `agent/` (including the committed `agent/build/agent.phar` and `signature.txt`),
`config/`, `tests/` (upstream's), `workbench/`, `phpunit.xml.dist`, `testbench.yaml`, `.gitattributes`, `README.md`, `.github/`.

**Files added:**

| Path | Purpose |
|---|---|
| `doxa/DoxaWatchServiceProvider.php` | Registers the layer. |
| `doxa/Configuration.php` | `DOXA_WATCH_*` → `nightwatch.*` mapping, default base URL, deploy detection. |
| `doxa/Console/{Agent,Status,Deploy}Command.php` | `watch:agent`, `watch:status`, `watch:deploy`. |
| `tests/Doxa/*`, `phpunit.doxa.xml.dist` | Tests for the layer, in their own PHPUnit config so `phpunit.xml.dist` stays identical to upstream. |
| `PROTOCOL.md`, `DOXA.md` | Documentation. |

`README.md` is still upstream's and still describes the upstream product. It is export-ignored (never installed into an
application) and was left alone to keep merges trivial; this file is the fork's README.

---

## 2. How the layer works

### 2.1 Configuration seam

Upstream reads its environment **only** through `config/nightwatch.php`, and captures the resulting array exactly once, inside
`NightwatchServiceProvider::register()` (`src/NightwatchServiceProvider.php:187-194`). Everything built there — the `Core`, the
TCP `Ingest`, the agent and deploy commands' constructor arguments, the log channel level — uses that snapshot.

So the layer only has to get its values into the config repository **before** that method runs:

1. `DoxaWatchServiceProvider` is listed first in `extra.laravel.providers`. Laravel registers discovered providers in manifest
   order, so its `register()` runs first. (`tests/Doxa/CommandAliasesTest.php` pins that order.)
2. `Configuration::apply()` performs the same shallow `array_merge(package defaults, application config)` that upstream's
   `mergeConfigFrom()` is about to perform (so a later one is a no-op), then, for each `DOXA_WATCH_*` variable that is set and
   non-empty, overwrites the single matching `nightwatch.*` key. An unset alias changes nothing, which is what makes every
   variable "fall back" to its `NIGHTWATCH_*` twin — upstream's own `env()` call already put that value there.
3. Because values are written into the repository rather than into `config/nightwatch.php`, this also works for applications
   that ran `vendor:publish` and have their own copy of the config file that only knows upstream's variable names
   (`tests/Doxa/PublishedConfigurationTest.php`).

> **If an application registers `Laravel\Nightwatch\NightwatchServiceProvider` by hand** (in `bootstrap/providers.php`) and that
> happens before package discovery, the aliases arrive too late. Do not register it manually; auto-discovery does it.

**`config:cache`:** the overrides are baked into the cached config because `config:cache` boots the providers before dumping
the repository. At runtime `.env` is not loaded, `env()` returns `null` for `.env`-only variables, the aliases are therefore
"unset", and the cached values stand. Real process environment variables (Kubernetes, supervisord) are still read at runtime
and win over the cache.

### 2.2 How the agent gets its token and base URL

`php artisan nightwatch:agent` does **not** spawn a process. `AgentCommand::handle()` assigns local variables and then
`require`s `agent/build/agent.phar` in its own scope (`src/Console/AgentCommand.php:55-75`); the PHAR's entry script uses
`$x ??= $_SERVER[...] ?? default` for every input (`agent/src/agent.php:52-75`).

| Input | Path |
|---|---|
| Token | `DOXA_WATCH_TOKEN` → `nightwatch.token` (layer) → `AgentCommand` constructor (upstream, `NightwatchServiceProvider.php:227-234`) → `$refreshToken` in `handle()` → PHAR. Same config value feeds the app-side token hash, so both ends always agree. |
| Listen address, server name | Same route, via `nightwatch.ingest.uri` / `nightwatch.server`. |
| **Base URL** | Upstream has **no config key**. `handle()` defines no `$baseUrl`, so the PHAR falls through to `$_SERVER['NIGHTWATCH_BASE_URL'] ?? 'https://nightwatch.laravel.com'`. The deploy command reads the same superglobal (`src/Console/DeployCommand.php:62`). |
| Agent log level | Also `$_SERVER` only: `NIGHTWATCH_AGENT_LOG_LEVEL`. |

The layer therefore resolves the base URL in `register()` and **writes it to `$_SERVER['NIGHTWATCH_BASE_URL']`** (first non-empty
of `DOXA_WATCH_BASE_URL`, `NIGHTWATCH_BASE_URL`, the cached `nightwatch.base_url`, then `https://watch-ingest.doxaplc.com`).
It also stores it under `nightwatch.base_url` purely so that `config:cache` preserves a value that only exists in `.env`
(upstream ignores that key). Trailing slashes are removed on the way: the agent trims them itself, but upstream's deploy command
appends `/api/deployments` verbatim and `//api/deployments` is a 404. `DOXA_WATCH_AGENT_LOG_LEVEL` is published the same way.

This is the whole trick: **no upstream file is patched and the PHAR did not have to be rebuilt.** Verified end to end with the
committed PHAR against a fake server on loopback: `DOXA_WATCH_BASE_URL` + `DOXA_WATCH_TOKEN` + `watch:agent` produce
`POST {base}/api/agent-auth` with `authorization: Bearer {DOXA_WATCH_TOKEN}`, and `watch:deploy` posts to `{base}/api/deployments`.

> **Standalone agent warning.** The upstream Docker image and a bare `php agent.phar` never load Laravel, so the layer does not
> run: they read `NIGHTWATCH_TOKEN`, `NIGHTWATCH_BASE_URL`, `NIGHTWATCH_INGEST_URI` only, and **default to the upstream hosted
> service**. Running them without `NIGHTWATCH_BASE_URL` would send a Doxa Watch token to a third party. House images run the
> agent through Artisan, which is safe. If a standalone agent image is ever needed, either always set `NIGHTWATCH_BASE_URL` or
> accept a one-line patch to `agent/src/agent.php:55` plus a PHAR rebuild (§4).

### 2.3 Command aliases

`watch:agent`, `watch:status` and `watch:deploy` are small commands that mirror the upstream signatures and delegate with
`$this->call('nightwatch:…')`. The upstream commands stay registered under their own names — existing supervisord programs that run
`php artisan nightwatch:agent` need no change.

- `watch:agent` and `watch:status` call `Nightwatch::dontSample()` first. Upstream excludes its own long-running/health commands
  from command monitoring **by name** (`src/Concerns/CapturesState.php:175-193`); without this, a 30-second health check would
  produce a `command` record every time.
- `watch:deploy` pre-checks the token and deploy value so that its error messages name `DOXA_WATCH_*` variables; like upstream it
  always exits `0`.
- Output produced by the delegated upstream command (and by the agent itself) still uses upstream's wording. That is existing
  upstream text, not text added by this fork.
- `tests/Doxa/CommandAliasesTest.php` fails when upstream adds or renames an option, which is the cue to mirror it.

### 2.4 Deploy detection

When `nightwatch.deployment` is still empty after upstream's own chain
(`NIGHTWATCH_DEPLOY` → `LARAVEL_CLOUD_DEPLOY_UUID` → `FORGE_DEPLOY_COMMIT` → `VAPOR_COMMIT_HASH`) and `DOXA_WATCH_DEPLOY`, the layer
uses the first non-empty of **`SOURCE_SHA`, `APP_VERSION`, `GIT_COMMIT`**. The value becomes the `deploy` field of every record
and the default argument of `watch:deploy`. It is truncated to 255 bytes by upstream.

### 2.5 Install-path fix

Upstream recognises its own stack frames by the hard-coded path `vendor/laravel/nightwatch` (`src/Location.php:41`). This package
installs to `vendor/doxa/watch`, so its middleware frames could be reported as the source location of queries issued purely by
framework code. `DoxaWatchServiceProvider::boot()` points the private `Location::$nightwatchPath` at the real package root by
reflection. `tests/Doxa/LocationTest.php` fails if upstream renames that property.

---

## 3. Environment variables

Every `DOXA_WATCH_*` variable is optional. When it is unset or empty, the `NIGHTWATCH_*` variable (and then upstream's default)
applies, so existing deployments and Infisical contracts keep working unchanged.

| Doxa variable | Falls back to | Default | Meaning |
|---|---|---|---|
| `DOXA_WATCH_TOKEN` | `NIGHTWATCH_TOKEN` | — | Environment token (one per application environment). |
| `DOXA_WATCH_BASE_URL` | `NIGHTWATCH_BASE_URL` | **`https://watch-ingest.doxaplc.com`** | Server base URL for `/api/agent-auth` and `/api/deployments`. |
| `DOXA_WATCH_ENABLED` | `NIGHTWATCH_ENABLED` | `true` | Master switch. |
| `DOXA_WATCH_DEPLOY` | `NIGHTWATCH_DEPLOY` → platform vars → `SOURCE_SHA` → `APP_VERSION` → `GIT_COMMIT` | `""` | Deploy identifier. |
| `DOXA_WATCH_SERVER` | `NIGHTWATCH_SERVER` | `gethostname()` | Server name. |
| `DOXA_WATCH_LOG_LEVEL` | `NIGHTWATCH_LOG_LEVEL` → `LOG_LEVEL` | `debug` | Minimum level captured from the `nightwatch` log channel. |
| `DOXA_WATCH_REQUEST_SAMPLE_RATE` | `NIGHTWATCH_REQUEST_SAMPLE_RATE` | `1.0` | |
| `DOXA_WATCH_COMMAND_SAMPLE_RATE` | `NIGHTWATCH_COMMAND_SAMPLE_RATE` | `1.0` | |
| `DOXA_WATCH_EXCEPTION_SAMPLE_RATE` | `NIGHTWATCH_EXCEPTION_SAMPLE_RATE` | `1.0` | |
| `DOXA_WATCH_SCHEDULED_TASK_SAMPLE_RATE` | `NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE` | `1.0` | |
| `DOXA_WATCH_INGEST_URI` | `NIGHTWATCH_INGEST_URI` | `127.0.0.1:2407` | Local agent `host:port` (listen address for the agent, target for the app). |
| `DOXA_WATCH_INGEST_TIMEOUT` | `NIGHTWATCH_INGEST_TIMEOUT` | `0.5` | App → agent read timeout (s). |
| `DOXA_WATCH_INGEST_CONNECTION_TIMEOUT` | `NIGHTWATCH_INGEST_CONNECTION_TIMEOUT` | `0.5` | App → agent connect timeout (s). |
| `DOXA_WATCH_INGEST_EVENT_BUFFER` | `NIGHTWATCH_INGEST_EVENT_BUFFER` | `500` | In-process record buffer. |
| `DOXA_WATCH_CAPTURE_EXCEPTION_SOURCE_CODE` | `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE` | `true` | Send ±5 source lines for application stack frames. |
| `DOXA_WATCH_CAPTURE_REQUEST_PAYLOAD` | `NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD` | `false` | Send the (redacted) request payload for HTTP 500 responses. |
| `DOXA_WATCH_REDACT_PAYLOAD_FIELDS` | `NIGHTWATCH_REDACT_PAYLOAD_FIELDS` | `_token,password,password_confirmation` | Comma separated. |
| `DOXA_WATCH_REDACT_HEADERS` | `NIGHTWATCH_REDACT_HEADERS` | `Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN` | Comma separated. |
| `DOXA_WATCH_IGNORE_CACHE_EVENTS` / `_MAIL` / `_NOTIFICATIONS` / `_OUTGOING_REQUESTS` / `_QUERIES` | `NIGHTWATCH_IGNORE_*` | `false` | Drop a whole record type client-side. |
| `DOXA_WATCH_AGENT_LOG_LEVEL` | `NIGHTWATCH_AGENT_LOG_LEVEL` | info | Agent output: `critical` (silent), `error`, `verbose`. |

`tests/Doxa/EnvironmentAliasesTest.php::test_every_upstream_configuration_key_has_an_alias` fails when upstream adds a config key,
so this table and `Doxa\Watch\Configuration::ALIASES` cannot silently fall behind.

---

## 4. The agent PHAR

**What it is.** `agent/` is a separate Composer project (ReactPHP). Its build output, `agent/build/agent.phar` plus
`agent/build/signature.txt`, is **committed** and is what applications run. `agent/composer.json`, `agent/composer.lock` are
export-ignored; `agent/src` ships too but is not used at runtime.

**How upstream builds it** (`agent/build.sh`, invoked by `cd agent && composer build`):

1. `docker build --platform=linux/amd64 -t agent-builder -f docker/Dockerfile.build .` — image `boxproject/box:4.6.8` + `pcntl`.
2. `composer install --prefer-dist --no-dev --classmap-authoritative` inside that container.
3. `box compile` using `agent/box.json.dist`: entry `src/agent.php`, **PHP-Scoper** compactor with the fixed prefix in
   `agent/scoper.inc.php` (so the agent's ReactPHP cannot collide with the host application's dependencies), fixed `timestamp`
   and no banner, so the build is meant to be reproducible.
4. `box info:signature build/agent.phar` → `build/signature.txt` (the PHAR's SHA-512).

Upstream CI (`.github/workflows/pull_request.yml`, job `build_agent`) runs `composer build` on every pull request and **commits the
result** back to the branch (`Bump agent version to <signature>`); `publish_release.yml` builds it again when tagging.

**Signature auto-restart.** A running agent re-reads `signature.txt` every 60 s; when the content differs from what it read at
start, it announces a 5-minute countdown, flushes and exits, and the process supervisor starts the new PHAR. A deploy that
changes nothing in `agent/build/` therefore never restarts agents. This fork does not touch the mechanism.

**Did the fork need a rebuild? No.** No file under `agent/` changed (§2.2), so the committed PHAR is byte-identical to upstream's
(`signature.txt` = `52AC2B1F…AEC166`, equal to the PHAR's embedded SHA-512). The build was **not exercised locally**: it needs
Docker and pulls `boxproject/box:4.6.8` from Docker Hub; Docker is installed on the development machine, but there was no reason
to run it.

**CI job to add** once the repository has a home (Phase 1 follow-up, not done here): on every tag, run `cd agent && composer build`
and fail if `git status --porcelain agent/build` is non-empty. While `agent/` is unmodified that is an integrity check that the
committed PHAR matches its source; the day `agent/src` is patched it becomes the rebuild-and-commit step, exactly as upstream does.

---

## 5. Merging upstream

```bash
git fetch upstream --tags
git checkout doxa/main
git merge v1.31.0            # the new upstream tag; never merge upstream's moving 1.x branch
```

Expected conflicts: `composer.json` only, and only when upstream edits the lines listed in §1 (name/description block, `autoload`,
`extra.laravel.providers`, the end of `scripts`). Resolve by taking upstream's change and re-applying the Doxa lines.
`agent/build/*` must always be taken from upstream verbatim.

After every merge:

1. `composer update && composer test:doxa` — the guard tests flag new config keys (§3), changed command options (§2.3), a renamed
   `Location::$nightwatchPath` (§2.5) and a changed provider order.
2. Run upstream's suite (§7).
3. `git diff <old-tag> <new-tag> -- src/Payload.php agent/src src/Sensors src/SensorManager.php src/Console/DeployCommand.php config/`
   and update `PROTOCOL.md` (it lists the files to re-audit in its §8.10). A change of `Payload::PAYLOAD_VERSION` is a breaking
   protocol change.
4. Re-check the three seams this fork relies on: `agent/src/agent.php` still reads `$_SERVER['NIGHTWATCH_BASE_URL']` and
   `AgentCommand::handle()` still defines no `$baseUrl`; `DeployCommand` still reads the same superglobal; upstream's provider still
   snapshots config in `register()`. If upstream ever moves the base URL into `config/nightwatch.php`, add it to
   `Configuration::ALIASES` and delete `applyBaseUrl()`.
5. Update the tag/commit in the tables at the top of this file and of `PROTOCOL.md`.

**Versioning.** Composer reads versions from git tags, and `replace: self.version` must satisfy constraints such as
`laravel/nightwatch:^1.21` in applications that still name the upstream package. Tag Doxa releases as the upstream version plus a
fourth segment: **`v1.30.2.1`**, `v1.30.2.2`, … then `v1.31.0.1` after the next merge. (`1.30.2-doxa.1` is not a valid Composer
version.) `version.txt` stays upstream's, so agents identify as `NightwatchAgent/1.30.2`. No tag has been created yet.

---

## 6. Installing in an application

Until the repository has a remote, a local path works for trying it out:

```jsonc
// composer.json of the application
"repositories": [
    { "type": "vcs", "url": "git@github.com:doxa-innovations/doxa-watch-laravel.git" }
    // local trial: { "type": "path", "url": "../doxa-watch-laravel", "options": { "symlink": false } }
],
```

```bash
composer remove laravel/nightwatch        # if present; `replace` would otherwise make Composer refuse
composer require doxa/watch:^1.30         # once a v1.30.2.x tag exists (§5)
# before any tag exists, or with the local path repository: composer require "doxa/watch:dev-doxa/main"
```

A private GitHub repository needs a token with read access at build time (`COMPOSER_AUTH` or `auth.json`; in house images that is
the `/build` Infisical folder). The repository URL above is a placeholder — nothing has been pushed anywhere.

Then, per environment:

```dotenv
DOXA_WATCH_TOKEN=<environment token from Doxa Watch → Settings → Environments>
# DOXA_WATCH_BASE_URL=https://watch-ingest.doxaplc.com   (the default; set it for staging)
# DOXA_WATCH_DEPLOY is detected from SOURCE_SHA / APP_VERSION / GIT_COMMIT when unset
```

- Keep (or fix) the supervisor program that runs `php artisan nightwatch:agent`; `php artisan watch:agent` is equivalent.
- Add the `nightwatch` channel to the log stack if logs should be captured (unchanged from upstream).
- Verify with `php artisan watch:status` (exit code `0` = the local agent accepted a ping). Note that a ping succeeds even with a
  wrong token; check the agent's output for `Authentication successful` (see `PROTOCOL.md` §1.4).
- In the deploy pipeline: `php artisan watch:deploy --ref="$SOURCE_SHA"`.

**Staying on `laravel/nightwatch` for now** also works, because the server speaks the stock protocol: set `NIGHTWATCH_TOKEN` and the
**process** environment variable `NIGHTWATCH_BASE_URL=https://watch-ingest.doxaplc.com` (it is read from `$_SERVER`, so with
`config:cache` it must be a real environment variable, not only a line in `.env`).

---

## 7. Tests

```bash
composer install
composer test:doxa                                   # the Doxa layer: tests/Doxa, phpunit.doxa.xml.dist
composer test                                        # upstream's suite (Unit + Feature), unmodified
cd agent && composer install && vendor/bin/phpunit   # upstream's agent suite
```

**Two upstream tests talk to the network — read before running:**

- `tests/Feature/Console/AgentCommandTest.php` starts the real agent, which authenticates against whatever base URL is in effect
  and waits for `Authentication successful`. Upstream CI supplies real credentials through secrets. Exclude it
  (`--exclude-filter AgentCommandTest`) or point it at a local fake; never run it against a production server.
- `agent/tests/Integration/*` does the same with the PHAR and requires `agent/.env.testing`. Run only
  `--testsuite Unit,Feature` unless a fake server is available. `IngestDetailsRepositoryTest::test_it_handles_valid_responses`
  additionally expects `NIGHTWATCH_BASE_URL` to start with `https://` and `NIGHTWATCH_TOKEN` to be 44 characters long; fake values
  are fine because that suite uses a fake HTTP client.
- `tests/Feature/Sensors/OutgoingRequestSensorTest::test_it_can_use_guzzle_directly` performs a real `GET https://laravel.com`
  and fails without internet access.

**Support matrix.** `composer.json` still declares upstream's range (PHP ^8.2; Laravel 10–13). **Doxa only tests PHP 8.2–8.4 ×
Laravel 11–13**, minus PHP 8.2 × Laravel 13 (Laravel 13 requires PHP 8.3). To test a specific Laravel version, do what upstream CI
does: `composer require laravel/framework:^13 --no-update && composer update`. The dev dependency `mongodb/laravel-mongodb` needs
`ext-mongodb` ≥ 1.21 for Laravel 12+; with an older extension add `--ignore-platform-req=ext-mongodb`.
