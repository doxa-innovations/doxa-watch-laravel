# Wire protocol v1

This document describes, from the source code, everything that crosses a process boundary in this package:
the monitored application → local agent TCP frames, the agent → server HTTP calls, the deploy notification, and the
JSON shape of every record type. It exists so that a compatible server (Doxa Watch) and, later, non-PHP SDKs can be
written without reading PHP.

| | |
|---|---|
| Derived from | `laravel/nightwatch` tag **`v1.30.2`**, commit **`123f0f24e3e3f4b732f04cdac3a0c63156f8efec`** (2026-09-18) |
| Frame version | `v1` (`src/Payload.php:16`, `agent/src/Payload.php:11`) |
| Method | Read from source and the package's own tests. §1.2–§1.4, §2.2, §2.3 (framing, ACK, auth request, 10 s flush, gzip body, headers) and §3 were additionally exercised end to end with the committed PHAR against a fake server on loopback. Nothing here was verified against the hosted upstream service; no request was sent to it. |

Conventions used below:

- `file:line` references are relative to the repository root at the tag above. They will drift after upstream merges; re-verify
  the sections marked **(re-verify on merge)**.
- "App" = the monitored Laravel application (code under `src/`). "Agent" = the ReactPHP process (code under `agent/`, shipped
  as `agent/build/agent.phar`). "Server" = the HTTP service the agent talks to.
- **Certain** statements are backed by a line reference. Anything inferred or not determinable from this repository is
  labelled **Uncertain** or listed in [§8 Open questions](#8-open-questions).

---

## 1. App → local agent transport

### 1.1 Address and timeouts

| Setting | Config key (`config/nightwatch.php`) | Env | Default | Ref |
|---|---|---|---|---|
| Agent address (`host:port`) | `nightwatch.ingest.uri` | `NIGHTWATCH_INGEST_URI` | `127.0.0.1:2407` | `config/nightwatch.php:30` |
| Connect timeout (s, float) | `nightwatch.ingest.connection_timeout` | `NIGHTWATCH_INGEST_CONNECTION_TIMEOUT` | `0.5` | `config/nightwatch.php:32` |
| Read timeout (s, float) | `nightwatch.ingest.timeout` | `NIGHTWATCH_INGEST_TIMEOUT` | `0.5` | `config/nightwatch.php:31` |
| In-process record buffer | `nightwatch.ingest.event_buffer` | `NIGHTWATCH_INGEST_EVENT_BUFFER` | `500` records | `config/nightwatch.php:33` |

- Transport is plain TCP, `tcp://{uri}` (`src/Ingest.php:40`), opened with `stream_socket_client` and the connect timeout
  (`src/SocketStreamFactory.php:21-26`). No TLS, no authentication beyond the token hash in the frame.
- The "timeout" is applied with `stream_set_timeout`, i.e. it is a **read** timeout for the acknowledgement
  (`agent/helpers.php:129-145`). There is no separate write timeout; writes loop until all bytes are written
  (`agent/helpers.php:26-47`).
- One TCP connection carries exactly **one frame** and one acknowledgement, then is closed (`src/Ingest.php:123-136`).
- The agent listens on the same env var: `$_SERVER['NIGHTWATCH_INGEST_URI'] ?? '127.0.0.1:2407'` (`agent/src/agent.php:58`), or
  `--listen-on` / the `nightwatch.ingest.uri` config value when started through Artisan (`src/Console/AgentCommand.php:57`).
  The upstream Docker image sets `NIGHTWATCH_INGEST_URI=0.0.0.0:2407` (`agent/Dockerfile:16`).

### 1.2 Frame grammar

```
frame      = length ":" version ":" token-hash ":" payload
length     = 1*DIGIT          ; decimal ASCII
version    = "v1"
token-hash = 7 lowercase hex characters
payload    = JSON array of record objects  |  "PING"
```

- **`length` is the number of bytes of everything after the first colon**, i.e.
  `strlen(version) + 1 + strlen(token-hash) + 1 + strlen(payload)` — bytes, not characters
  (`src/Payload.php:59-61`; mirrored by the agent's completeness check, `agent/src/Payload.php:29`).
  Example: token hash `abcdefg`, payload `PING` → `15:v1:abcdefg:PING`.
- The agent splits on the first three colons only (`explode(':', $value, 4)`, `agent/src/Payload.php:38`), so the payload may
  contain colons.
- **`token-hash`** = first 7 characters of the lowercase hex `xxh128` digest of the environment token string:
  `substr(hash('xxh128', $token), 0, 7)` (`src/NightwatchServiceProvider.php:251`, `agent/src/agent.php:116`,
  `agent/nightwatch-status:87`). With no token configured both sides hash the empty string.
  The agent's parser accepts a hash of any length (`agent/tests/Unit/PayloadTest.php:126`); 7 is what the package sends.
- **`payload`** for records is a JSON **array** of record objects, encoded with
  `JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`
  (`src/Payload.php:43`). Consequences for any consumer: invalid UTF-8 is replaced by U+FFFD, floats always carry a fraction
  (`1.0`, not `1`), slashes and non-ASCII are not escaped.

### 1.3 Acknowledgement

- As soon as the declared number of bytes has arrived the agent writes the 4 bytes **`2:OK`** and ends the connection
  (`agent/src/Server.php:43-51`).
- The app reads up to 4 bytes (at most 5 `fread` attempts) and throws `RuntimeException("Unexpected response from agent [...]")`
  on anything else, including an empty read after the timeout (`src/Ingest.php:165-172`, `agent/helpers.php:53-70`).
- **`2:OK` only means "bytes received".** Version and token-hash validation happen afterwards, in the `close` handler
  (`agent/src/Server.php:53-79`):
  - fewer bytes than declared → error log `Incomplete payload received`, frame discarded;
    more bytes than declared → the frame never becomes "complete" and is discarded the same way
    (`agent/tests/Unit/PayloadTest.php:104`);
  - `version !== 'v1'` → the agent stops accepting connections, flushes its buffer to the server and **exits**
    (`agent/src/Server.php:60-66`, `agent/src/agent.php:205-213`). This is the upgrade mechanism: a newer package talking to an
    older running agent forces a restart through the process supervisor;
  - token-hash mismatch → error log `Incoming token hash mismatch!`, frame discarded (`agent/src/Server.php:68-72`). The app
    has already received `2:OK`, so a mis-configured token loses data silently on the app side;
  - payload `PING` → ignored (`agent/src/Server.php:74-76`);
  - otherwise the raw payload string is handed to the agent's HTTP buffer (§2.3). **The agent never parses the JSON.**

### 1.4 PING / health

- `PING` is a TEXT payload framed exactly like records: `15:v1:<hash>:PING` (`src/Ingest.php:74-77`, `src/Payload.php:31-34`).
- `php artisan nightwatch:status` sends one PING and expects `2:OK`. Exit code `0` and
  `The Nightwatch agent is running and accepting connections` on success; exit code `1` with the exception message on failure;
  exit code `1` with `Nightwatch is disabled` when `nightwatch.enabled` is false (`src/Console/StatusCommand.php:31-50`).
- `agent/nightwatch-status` is a framework-free copy of the same check, used as the Docker `HEALTHCHECK`
  (`agent/nightwatch-status:52-101`, `agent/Dockerfile:19`). It reads `NIGHTWATCH_TOKEN`, `NIGHTWATCH_INGEST_URI`,
  `NIGHTWATCH_INGEST_TIMEOUT`, `NIGHTWATCH_INGEST_CONNECTION_TIMEOUT` directly from `$_SERVER` and refuses to run without a token.
- Because validation happens after the ACK (§1.3), **a PING succeeds even when the token hash is wrong**; the only trace is an
  error line in the agent's output.

### 1.5 Buffering and flush triggers (app side)

- Records accumulate in `RecordsBuffer` (default 500). When the buffer is full **and** the current execution is sampled in, the
  buffer is sent immediately (`src/Ingest.php:43-54`, `src/RecordsBuffer.php:30-39`).
- When the execution is **not** sampled (yet), the buffer is a ring: once full, the oldest record is dropped for each new one
  (`src/RecordsBuffer.php:32-34`). This is how "sample the exception that happened late in an unsampled request" works: the
  last ≤ 500 records are still available if an exception flips sampling on (§5.1).
- End of execution (`Core::finishExecution`, `src/Core.php:97-110`): sampled → `digest()` (send), not sampled → `flush()` (discard).
  It is called at the end of each request, command, **each job attempt** and **each scheduled task**
  (`src/Hooks/RequestLifecycleIsLongerThanHandler.php`, `src/Hooks/CommandLifecycleIsLongerThanHandler.php`,
  `src/Hooks/WorkerLifecycleListener.php`, `src/Hooks/ScheduledTaskListener.php`).
- **Unhandled exceptions are sent immediately**, in their own single-record frame, without waiting for the end of the execution
  (`writeNow`, `src/Concerns/CapturesState.php:280-284`, `src/Ingest.php:56-67`). Fatal errors additionally discard the buffer
  first (`src/Concerns/CapturesState.php:261-266`). A server therefore sees an exception record *before* the request record of
  the same trace, possibly in a different HTTP batch.
- An empty buffer is never sent (`src/Ingest.php:92-94`).
- Public API: `Nightwatch::digest()` forces the same end-of-execution behaviour (`src/Core.php:87-90`).

### 1.6 The `IngestingEvents` hook

Immediately before any frame of records is transmitted the app dispatches
`Laravel\Nightwatch\Events\IngestingEvents` with `public readonly array $records` using `Dispatcher::until()`
(`src/Ingest.php:108-121`, `src/Events/IngestingEvents.php`). If any listener returns `false` the whole frame is discarded.
Records are read-only in the event (the listener cannot rewrite them). `eventCount()` counts every record except `t = "user"`
(`src/Events/IngestingEvents.php:18-24`) — a strong hint that **the hosted service does not bill/count `user` records as events**.
While listeners run, records they would generate themselves are ignored (`src/Ingest.php:45-47`).

### 1.7 Behaviour when the agent is down

Connection refused, timeout or a bad ACK raise a `RuntimeException` inside `transmit()`. The buffer has already been emptied
(`RecordsBuffer::pull`, `src/RecordsBuffer.php:54-65`), so **the records are lost; there is no retry and no spooling to disk.**
The exception is passed to `Nightwatch::unrecoverableExceptionOccurred()`, which is a no-op unless the application registered
`Nightwatch::handleUnrecoverableExceptionsUsing(fn (Throwable $e) => ...)` (`src/Core.php:105-107`,
`src/Facades/Nightwatch.php:61-79`). Nothing is logged by default and the host application is never interrupted. Cost to the
request: at most the connect timeout + read timeout (0.5 s + 0.5 s by default) per frame.

---

## 2. Agent → server

### 2.1 How the agent is configured

The PHAR's entry script (`agent/src/agent.php`) takes every input from a PHP variable if one is already defined, otherwise from
`$_SERVER` (`??=`, lines 52-75). When started with `php artisan nightwatch:agent` the PHAR is `require`d **inside**
`AgentCommand::handle()` (`src/Console/AgentCommand.php:75`), so the local variables of that method become the agent's inputs:

| Agent variable | From Artisan | Standalone (`php agent.phar`, Docker) | Default |
|---|---|---|---|
| `$refreshToken` (environment token) | `config('nightwatch.token')` (`src/Console/AgentCommand.php:55`) | `$_SERVER['NIGHTWATCH_TOKEN']` | `''` |
| `$baseUrl` | **not set by the command** → falls through to `$_SERVER` | `$_SERVER['NIGHTWATCH_BASE_URL']` | `https://nightwatch.laravel.com` (`agent/src/agent.php:55`) |
| `$listenOn` | `--listen-on` ?? `config('nightwatch.ingest.uri')` | `$_SERVER['NIGHTWATCH_INGEST_URI']` | `127.0.0.1:2407` |
| `$server` (name) | `--server` ?? `config('nightwatch.server')` | — | `gethostname()` |
| auth connect / total timeout | `--auth-connection-timeout` / `--auth-timeout` | — | `5` s / `10` s (`agent/src/agent.php:61-63`) |
| ingest connect / total timeout | `--ingest-connection-timeout` / `--ingest-timeout` | — | `5` s / `10` s (`agent/src/agent.php:65-67`) |
| log verbosity | `--silent`, `--quiet`, `-v` | `$_SERVER['NIGHTWATCH_AGENT_LOG_LEVEL']` = `critical` \| `error` \| `verbose` | info |

**The base URL is not part of `config/nightwatch.php`.** It is read only from `$_SERVER['NIGHTWATCH_BASE_URL']`, in two places:
the agent (`agent/src/agent.php:55`) and the deploy command (`src/Console/DeployCommand.php:62`). It is undocumented upstream.
With a cached Laravel config, `.env` is not loaded, so the variable must then be a real process environment variable.
The agent strips trailing slashes (`rtrim($baseUrl, '/')`, `agent/src/agent.php:158`); the deploy command does not.

### 2.2 Authentication exchange

**Request** (`agent/src/IngestDetailsRepository.php:101`, headers `agent/src/agent.php:148-159`):

```
POST {baseUrl}/api/agent-auth
accept: application/json
authorization: Bearer {environment token}
content-type: application/json
nightwatch-server: {server name}
user-agent: NightwatchAgent/{package version, e.g. 1.30.2}

{}
```

- Body is the literal 2 bytes `{}`. `Host` and `Content-Length` are added by the HTTP client; its default `User-Agent` is
  removed first (`agent/src/Factories/BrowserFactory.php:23-26`).
- The package version comes from `version.txt` at the package root (`agent/src/agent.php:139`).
- Connect timeout 5 s, total timeout 10 s.
- First call happens at start-up (`$ingestDetails->hydrate()`, `agent/src/agent.php:243`), before any records exist.

**Successful response** — any 2xx with a JSON object containing, with **strict types** (`agent/src/IngestDetailsRepository.php:157-180`):

| Field | JSON type | Use |
|---|---|---|
| `token` | string | Sent as `authorization: Bearer {token}` on every ingest request until replaced. |
| `expires_in` | **integer** seconds | `expiresAt = now + expires_in` on the agent's own clock. Once passed, the next flush triggers an on-demand re-auth (`agent/src/IngestDetailsRepository.php:77-83`); a batch that still holds an expired token is dropped with `Authentication token expired` (`agent/src/Ingest.php:137-139`). |
| `refresh_in` | **integer** seconds | A timer re-runs the auth call after this many seconds (`agent/src/IngestDetailsRepository.php:107`, `140-155`). |
| `ingest_url` | string | **Absolute** URL used verbatim for every ingest POST; the ingest HTTP client has no base URL (`agent/src/agent.php:173-183`, `agent/src/Ingest.php:141-142`). May be a different host from the auth host. |

A float (`3600.0`), a numeric string (`"3600"`), a missing field, a non-object or non-JSON body is
`Invalid authentication response [...]` and is handled as a failure (below). Extra fields are ignored. `stop` in a **2xx** auth
response is ignored.

**Failure handling.** The HTTP client rejects every non-2xx response (ReactPHP `Browser` default), so 4xx/5xx, timeouts, DNS/TLS
errors and invalid bodies all land in the same `catch` (`agent/src/IngestDetailsRepository.php:121-137`):

1. `consecutiveFailures++`.
2. If the response has a JSON body, three optional fields are read (`agent/src/IngestDetailsRepository.php:195-224`):
   `message` (string, logged, truncated to 1000 bytes + `[...]`), `stop` (bool), `refresh_in` (int|float seconds).
3. `stop: true` → the current token is **dropped immediately**; every batch is discarded with `No authentication details` until a
   later auth succeeds.
   Without `stop`, **the previously issued token stays in use** until its own `expires_in` passes.
4. The next attempt is scheduled after `refresh_in` from the error body if present, otherwise by the built-in back-off:

| Situation | Delay before next auth attempt (s) | Ref |
|---|---|---|
| Never authenticated since process start | `2.5, 5, 10, 15, 30, 60, 120, 240`, then `300` × 12, then `3600` forever | `agent/src/IngestDetailsRepository.php:237-242` |
| Has authenticated at least once | `300` while consecutive failures < 13, then `3600` | `agent/src/IngestDetailsRepository.php:244-249` |

A successful auth resets the failure counter (`agent/src/IngestDetailsRepository.php:117-118`).

**HTTP status codes carry no meaning of their own.** The agent never branches on 401 vs 403 vs 429 vs 5xx; the status is only
printed in the log line (`"{status} [{message}]"`). The only control signals are the JSON fields `stop` and `refresh_in`.
(The upstream tests use `401 {"message":"Invalid environment token","refresh_in":3600}` and
`403 {"stop":true,"refresh_in":33,"message":"Exceeded quota"}` as representative server replies —
`agent/tests/Response.php:58-68`, `agent/tests/Feature/IngestDetailsRepositoryTest.php:862-868`.)

**Redirects** are followed by the HTTP client (max 10). For 301/302/303 the request is re-issued as a body-less `GET`; only
307/308 preserve `POST` + body, and `Authorization` is dropped when the host changes
(bundled `react/http`, `Io/Transaction.php`). A compatible server must not redirect these endpoints.

### 2.3 Ingest request

**Buffering in the agent** (`agent/src/Ingest.php:59-80`, `agent/src/StreamBuffer.php`):

- Each accepted frame payload (`[...]`) has its first and last byte removed and is appended, comma-separated, to a string buffer.
- The buffer is flushed when (a) appending the next payload **would exceed** 6,000,000 bytes (flush first, then append),
  (b) it has **reached** 6,000,000 bytes after appending, or (c) **10 s** after the first write into an empty buffer.
  The 6 MB figure is **uncompressed bytes of concatenated record JSON**, excluding the `{"records":[` … `]}` wrapper
  (`agent/src/agent.php:190-192`, `agent/src/StreamBuffer.php:29-39`).
- It is a soft limit: a single frame larger than 6 MB is sent on its own, so **a request body can decompress to more than 6 MB**
  (an app flush is up to 500 records, and one exception `trace` alone may be up to 16 MiB — §4.13).
- On shutdown (signature change, payload version change) the buffer is flushed and in-flight requests are awaited
  (`agent/src/Ingest.php:85-94`).

**Request** (`agent/src/Ingest.php:108-147`, headers `agent/src/agent.php:173-183`):

```
POST {ingest_url}
accept: application/json
authorization: Bearer {token from /api/agent-auth}
content-encoding: gzip
content-type: application/json
nightwatch-server: {server name}
user-agent: NightwatchAgent/{package version}

<gzip( {"records":[ {…}, {…}, … ]} )>
```

- Compression: `gzencode($payload)` with PHP's default level (`-1` → zlib level 6), gzip container (RFC 1952)
  (`agent/src/Ingest.php:118-119`). The source carries a `TODO determine what level is optimal`, so treat the level as unstable.
- Body after decompression: a JSON object with the single key `records`, whose value is the concatenation of every app frame
  since the last flush, in arrival order. One request mixes records from many executions, traces and PHP processes, but from a
  single environment token: frames whose token hash differs from the agent's are discarded (§1.3).
- Connect timeout 5 s, total timeout 10 s.
- **Concurrency limit 5.** If five requests are already in flight when a flush happens, **that batch is dropped** (it was already
  pulled from the buffer) with the error `Exceeded concurrent request limit` (`agent/src/Ingest.php:110-116`).
- There is **no version header** other than `user-agent`, no idempotency key, no batch id, no record count header.

**Response handling** (`agent/src/Ingest.php:148-225`):

| Outcome | What the agent does |
|---|---|
| 2xx, body without `stop` (any body, JSON or not, e.g. `{}` or empty) | Logs `Ingest successful`. Nothing else. `refresh_in` and `message` are ignored. |
| 2xx **or** non-2xx, JSON body with `stop: true` | **Pauses ingestion**: discards the current buffer, swaps in a null buffer (incoming app frames are still ACKed `2:OK` and thrown away), and schedules an auth refresh in `refresh_in` seconds (default **900** when absent). Logs `{status} [{message}]`. (`agent/src/Ingest.php:177-184`, `agent/src/IngestDetailsRepository.php:86-91`) |
| non-2xx without `stop`, timeout, network error | Logs `Ingest failed … {status} [{message}]`. |
| **Every** failure case | **The batch is lost. Ingest requests are never retried**, regardless of status code. |

**Resuming after `stop`:** ingestion resumes only when a subsequent `/api/agent-auth` call **succeeds**
(`agent/src/IngestDetailsRepository.php:111-115` → `onUnderQuota` → `Ingest::resumeIngestion`). To keep an agent paused, the server must
answer the auth call with a non-2xx (ideally `{"stop":true,"refresh_in":N,"message":"…"}`) for as long as the pause lasts;
a 2xx auth response un-pauses it immediately.

The status code is, again, informational only. In particular the agent does **not** re-authenticate on a 401 from the ingest
endpoint; it keeps using the token until `refresh_in`/`expires_in` elapse.

### 2.4 Agent self-restart (package signature)

Not part of the wire protocol, but it determines when agents pick up a new package version:

- `agent/build/signature.txt` holds the PHAR's SHA-512 signature, written by the build (`agent/build.sh:9-11`).
- The agent reads it once at start (`agent/src/agent.php:117-137`; with Envoyer-style `releases/N` paths it prefers `current/…`), then
  re-reads it every 60 s (`agent/src/CheckSignature.php:35-52`). An unreadable/empty file is ignored.
- When the content changes: logs `Agent signature changed: shutting down in 5 minutes`, counts down, flushes, exits
  (`agent/src/CheckSignature.php:54-71`, `agent/src/agent.php:217-235`). The process supervisor must restart it.
- A frame-version change (`v1` → other) triggers the same flush-and-exit immediately (§1.3).

---

## 3. Deploy notifications

`php artisan nightwatch:deploy [deploy] [--ref=] [--name=] [--url=] [--timestamp=]` (`src/Console/DeployCommand.php`).

```
POST {baseUrl}/api/deployments
Accept: application/json
Authorization: Bearer {environment token}        ← the long-lived token, NOT the short-lived ingest token
Content-Type: application/json
User-Agent: GuzzleHttp/7                          ← Laravel HTTP client default; no nightwatch-server header

{"timestamp":"2025-12-22 15:30:45.123456","deploy":"v1.2.3","ref":"abcd1234","name":null,"url":null}
```

| Field | Type | Notes |
|---|---|---|
| `timestamp` | string | `Y-m-d H:i:s.u`, **UTC**, microsecond precision; `--timestamp` parsed by Carbon, default now (`src/Console/DeployCommand.php:46`, `:70`) |
| `deploy` | string | Argument, else `config('nightwatch.deployment')`. This is the same value records carry in `deploy`. Not truncated here (records truncate to 255 bytes). |
| `ref` | string \| null | git ref |
| `name` | string \| null | human-readable name |
| `url` | string \| null | link |

- `baseUrl` = `$_SERVER['NIGHTWATCH_BASE_URL']` if non-empty, else `https://nightwatch.laravel.com`; no trailing-slash trimming
  (`src/Console/DeployCommand.php:62`).
- Connect timeout 5 s, total 10 s (`src/Console/DeployCommand.php:65-66`). Sent once; no retry.
- Any 2xx is success; the response body is ignored. On non-2xx the command prints the JSON `message` field if present, otherwise
  `[{status}] {body}`, truncated to 1000 characters (`src/Console/DeployCommand.php:79-82`).
- **The command always exits 0**, including when the token/deploy value is missing or the request fails, so it cannot break a
  deploy pipeline (`src/Console/DeployCommand.php:50-60`, `:87`).
- Test reference: `tests/Feature/Console/DeployCommandTest.php`.

---

## 4. Records

### 4.1 Common conventions

- Every record is a flat JSON object. There is no envelope per record beyond the fields below.
- **`t`** — record type, one of the 13 strings in §4.3–§4.15.
- **`v`** — integer **schema version of that record type** (not of the frame). At this tag: `3` for `exception`, `1` for
  everything else. See §6.
- **`timestamp`** — JSON **number**, Unix epoch **seconds as a float with microsecond precision** (PHP `microtime(true)`), e.g.
  `946688523.456789`. Always UTC by construction. Which instant it denotes differs per type (start vs end) and is stated in each
  table. Because of `JSON_PRESERVE_ZERO_FRACTION` a whole second is sent as `946688523.0`.
  A double has ~15–16 significant digits, so real-world values are exact to roughly 1 µs but not better.
- **Durations** are JSON integers in **microseconds**. **Sizes** and memory are integers in **bytes**. Counters are integers.
- **Strings are truncated by bytes, not characters** (`src/Types/Str.php:17-39`): `tinyText` = 255 B, `text` = 65,535 B,
  `mediumText` = 16,777,215 B. A cut can split a multi-byte character; the JSON encoder then substitutes U+FFFD. Fields with no
  limit listed are sent untruncated.
- **No field is ever `null`.** Absent values are `""`, `0` or `false`. Field order is stable but must not be relied upon.
- **`deploy`**, **`server`** — strings, ≤ 255 B, `""` when not configured (`src/State/RequestState.php:72-73`).
  `deploy` = `NIGHTWATCH_DEPLOY` → `LARAVEL_CLOUD_DEPLOY_UUID` → `FORGE_DEPLOY_COMMIT` → `VAPOR_COMMIT_HASH`
  (`config/nightwatch.php:6`); `server` = `NIGHTWATCH_SERVER` → `gethostname()`.
- **`trace_id`** — UUIDv4 string (36 chars) tying together everything caused by one entry point. A request and the queued jobs it
  dispatched share a `trace_id` (§5.4). On Laravel Cloud the inbound `Cloud-Request-ID` header is used instead when present, so
  **do not assume UUID format** (`src/NightwatchServiceProvider.php:247-249`, `src/Concerns/CapturesState.php:746-748`).
- **`_group`** — 32 lowercase hex characters, `xxh128` of a type-specific string given in each table. Computed by the **app**
  (not the agent). Inputs are the *un-truncated* values unless stated. `log` and `user` have no `_group`.
- **Execution context** (present on child records only — see the matrix):
  - `execution_source` — `"request"` | `"command"` | `"job"` | `"schedule"` (`src/State/RequestState.php:22-25`,
    `src/State/CommandState.php:23-26`).
  - `execution_id` — string. For `request`/`command`/`schedule` it **equals `trace_id`**; for `job` it is a fresh UUID per attempt
    and **equals `job-attempt.attempt_id`** (`src/Concerns/CapturesState.php:633-634`, `:711-714`, `:746-750`).
    Fatal-error exception records send `""` (§4.13).
  - `execution_preview` — string ≤ 255 B: `"GET /users"` (method + path, no query string), the command name, or the job class
    (`src/Concerns/CapturesState.php:543-548`, `:635`, `:662`). `""` for scheduled tasks and before it is known.
  - `execution_stage` — `"bootstrap"` | `"before_middleware"` | `"action"` | `"render"` | `"after_middleware"` | `"sending"` |
    `"terminating"` | `"end"` (`src/ExecutionStage.php`). Commands/jobs/tasks only ever use `bootstrap`, `action`, `terminating`, `end`.
- **`user`** — string ≤ 255 B, the authenticated user's identifier (`getAuthIdentifier()` cast to string, or the `id` returned by
  the app's `Nightwatch::user()` resolver), `""` for guests. Resolved lazily at JSON-encoding time, so a user who logs in late in
  the request is still attributed on earlier records; a user who logs out is remembered (`src/UserProvider.php:54-100`,
  `src/Hooks/LogoutListener.php`). In queued jobs it falls back to the id propagated from the dispatching execution (§5.4).

**Which common fields appear where:**

| `t` | `deploy` `server` | `_group` | `trace_id` | `user` | `execution_*` (4 fields) |
|---|:-:|:-:|:-:|:-:|:-:|
| `request` | ✔ | ✔ | ✔ | ✔ | — |
| `command` | ✔ | ✔ | ✔ | — | — |
| `scheduled-task` | ✔ | ✔ | ✔ | — | — |
| `job-attempt` | ✔ | ✔ | ✔ | ✔ | — |
| `query`, `cache-event`, `outgoing-request`, `mail`, `notification`, `queued-job`, `exception` | ✔ | ✔ | ✔ | ✔ | ✔ |
| `log` | ✔ | — | ✔ | ✔ | ✔ |
| `user` | — | — | — | — | — |

So the four "parent" types never carry `execution_*`; children point at their parent through `execution_source` + `execution_id`
(+ `trace_id`). A `request` record has **no id of its own** — its identity is its `trace_id`.

**Shared execution counters** (on `request`, `command`, `job-attempt`, `scheduled-task`): integers counting child records
*generated* during the execution — `exceptions`, `logs`, `queries`, `jobs_queued`, `mail`, `notifications`, `outgoing_requests`,
`cache_events` — plus `lazy_loads`, `hydrated_models`, `files_read`, `files_written`, which are **declared but never incremented
anywhere in this version: always `0`** (no writer exists under `src/`). Counters are incremented when the child record is
serialised, so records rejected or filtered client-side (§5.2) are not counted. `peak_memory_usage` = `memory_get_peak_usage(true)`
in bytes, reset per request (Octane), job and scheduled task. `exception_preview` = message of the last **unhandled** exception,
≤ 255 B, else `""`. `context` = JSON **string** of Laravel's `Context::all()` (hidden context excluded) encoded as an object,
≤ 65,535 B — `"{}"` when empty, `""` on Laravel 10 (no Context), and
`{"_nightwatch_error":"Failed to serialize context"}` on failure (`src/Concerns/RecordsContext.php`).
Note the truncation is applied to the encoded string, so an oversized context is **invalid JSON** on arrival.

### 4.2 Record order inside a frame

Records are appended in the order they are produced, so children precede their parent: e.g.
`query, query, cache-event, log, user, request`. The `user` record (if any) is written just before the `request` record
(`src/Hooks/RequestLifecycleIsLongerThanHandler.php:35-45`). Unhandled exceptions travel separately and earlier (§1.5).

### 4.3 `request` (v1)

Source: `src/Sensors/RequestSensor.php:108-165`. `timestamp` = **start** of the request (`LARAVEL_START` /
`REQUEST_TIME_FLOAT`; per-request under Octane). `_group` = `xxh128(implode('|', sorted route_methods) + "," + route_domain + "," + route_path)`
— e.g. `xxh128("GET|HEAD,,/users")`; unmatched routes (404) hash `",,"`.

| Field | Type | Notes |
|---|---|---|
| `method` | string | HTTP method actually used |
| `url` | string | scheme + host + base URL + path + `?query`; **not truncated**; mutable by the app's `redactRequests` callback |
| `route_name` | string | `""` if unnamed / no route |
| `route_methods` | array of string | sorted ascending, e.g. `["GET","HEAD"]`; `[]` when no route matched |
| `route_domain` | string | `""` if none |
| `route_path` | string | route URI with leading `/` (`/users/{user}`), `""` when no route matched |
| `route_action` | string | `Class@method`, `Closure`, or for Livewire a comma-joined list of component actions |
| `ip` | string | `""` if unknown; mutable by redaction callback |
| `duration` | int µs | sum of all stage durations |
| `status_code` | int | |
| `request_size` | int B | body length |
| `response_size` | int B | content length; file size for file responses; `content-length` header; `0` when unknown (streams) |
| `bootstrap`, `before_middleware`, `action`, `render`, `after_middleware`, `sending`, `terminating` | int µs | per-stage durations; a stage that did not happen is `0` |
| shared counters, `peak_memory_usage`, `exception_preview`, `context` | | §4.1 |
| `headers` | string (JSON) | JSON object `{"name":["v1","v2"]}` of lower-cased request headers after redaction (§5.3), ≤ 65,535 B; `php-auth-*` removed; `{"_nightwatch_error":"Failed to serialize headers"}` on failure |
| `payload` | string | `""` unless the response status is **exactly 500**. Then: `""` for body-less GET/HEAD/OPTIONS/TRACE; `{"_nightwatch_error":"NOT_ENABLED"}` when `capture_request_payload` is off (**the default**); `{"_nightwatch_error":"UNSUPPORTED_CONTENT_TYPE"}`; `{"_nightwatch_error":"SERIALIZATION_FAILED"}`; otherwise a JSON object of the redacted input plus `"_nightwatch_files": {field: {"originalName","size","error"}}`, ≤ 65,535 B (`src/Sensors/RequestSensor.php:195-225`) |

```json
{"v":1,"t":"request","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('GET|HEAD,,/users')>","trace_id":"00000000-0000-0000-0000-000000000000","user":"",
 "method":"GET","url":"http://localhost/users","route_name":"","route_methods":["GET","HEAD"],"route_domain":"",
 "route_path":"/users","route_action":"Closure","ip":"127.0.0.1","duration":0,"status_code":200,"request_size":0,
 "response_size":2,"bootstrap":0,"before_middleware":0,"action":0,"render":0,"after_middleware":0,"sending":0,
 "terminating":0,"exceptions":0,"logs":0,"queries":0,"lazy_loads":0,"jobs_queued":0,"mail":0,"notifications":0,
 "outgoing_requests":0,"files_read":0,"files_written":0,"cache_events":0,"hydrated_models":0,"peak_memory_usage":1234,
 "exception_preview":"","context":"{}",
 "headers":"{\"host\":[\"localhost\"],\"user-agent\":[\"Symfony\"],\"accept\":[\"text/html\"]}","payload":""}
```
(`tests/Feature/Sensors/RequestSensorTest.php:70-126`)

### 4.4 `command` (v1)

Source: `src/Sensors/CommandSensor.php:57-92`. `timestamp` = process start. `_group` = `xxh128(name)`.
Not produced for `queue:work`, `queue:listen`, `horizon:work`, `vapor:work`, `schedule:run`, `schedule:work`, `vapor:schedule`,
`help`, `inspire`, `schedule:finish` (`src/Hooks/CommandStartingListener.php:53-58`), nor — unless
`Nightwatch::captureDefaultVendorCommands()` — for the default vendor commands listed in `src/Concerns/CapturesState.php:175-193`
(includes `nightwatch:agent`, `nightwatch:status`).

| Field | Type | Notes |
|---|---|---|
| `class` | string | command class (untruncated) |
| `name` | string | e.g. `app:build` (untruncated) |
| `command` | string | raw argv after the binary, e.g. `app:build path/to/output --force`; untruncated; mutable by `redactCommands` |
| `exit_code` | int | 0–255; out-of-range values are reported as `255` |
| `duration` | int µs | sum of stages |
| `bootstrap`, `action`, `terminating` | int µs | |
| shared counters, `peak_memory_usage`, `exception_preview`, `context` | | §4.1 |

```json
{"v":1,"t":"command","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01","_group":"<xxh128('app:build')>",
 "trace_id":"00000000-0000-0000-0000-000000000000","class":"Illuminate\\Foundation\\Console\\ClosureCommand",
 "name":"app:build","command":"app:build path/to/output --force","exit_code":3,"duration":1234567,"bootstrap":0,
 "action":1234567,"terminating":0,"exceptions":0,"logs":0,"queries":1,"lazy_loads":0,"jobs_queued":0,"mail":0,
 "notifications":0,"outgoing_requests":0,"files_read":0,"files_written":0,"cache_events":0,"hydrated_models":0,
 "peak_memory_usage":1234,"exception_preview":"","context":"{}"}
```
(`tests/Feature/Sensors/CommandSensorTest.php`)

### 4.5 `scheduled-task` (v1)

Source: `src/Sensors/ScheduledTaskSensor.php:55-99`. `timestamp` = task start. One record per task run, including skipped ones.
`_group` = `xxh128("{name},{cron},{timezone}")`, or `xxh128("{name},{cron},{timezone},{repeat_seconds}")` when `repeat_seconds > 0`.
Each task gets a **new `trace_id`** (`src/Concerns/CapturesState.php:711-714`). No `user`.

| Field | Type | Notes |
|---|---|---|
| `name` | string | command line with the PHP binary normalised to `php` and the artisan binary unquoted (`php artisan inspire`); for closures the description, else `Closure at: path/to/file.php:LINE`, else the callable's class. Untruncated. |
| `cron` | string | 5-field expression |
| `timezone` | string | tz name |
| `repeat_seconds` | int | `0` when not sub-minute |
| `without_overlapping`, `on_one_server`, `run_in_background`, `even_in_maintenance_mode` | bool | |
| `status` | string enum | `"processed"` \| `"failed"` \| `"skipped"` |
| `duration` | int µs | `0` when skipped |
| shared counters, `peak_memory_usage`, `exception_preview`, `context` | | §4.1 |

```json
{"v":1,"t":"scheduled-task","timestamp":946688523.456789,"deploy":"v1.2.3","server":"scheduler-01",
 "_group":"<xxh128('php artisan inspire,* * * * *,UTC')>","trace_id":"00000000-0000-0000-0000-000000000000",
 "name":"php artisan inspire","cron":"* * * * *","timezone":"UTC","repeat_seconds":0,"without_overlapping":false,
 "on_one_server":false,"run_in_background":false,"even_in_maintenance_mode":false,"status":"processed",
 "duration":1000000,"exceptions":0,"logs":0,"queries":0,"lazy_loads":0,"jobs_queued":0,"mail":0,"notifications":0,
 "outgoing_requests":0,"files_read":0,"files_written":0,"cache_events":0,"hydrated_models":0,"peak_memory_usage":1234,
 "exception_preview":"","context":"{}"}
```
(`tests/Feature/Sensors/ScheduledTaskSensorTest.php`)

### 4.6 `queued-job` (v1) — a job was dispatched

Source: `src/Sensors/QueuedJobSensor.php:74-93`. `timestamp` = instant the push **finished** (`JobQueued`); `duration` looks
backwards to `JobQueueing`. `_group` = `xxh128(name)`.

| Field | Type | Notes |
|---|---|---|
| `job_id` | string (UUID) | package-generated id placed in the queue payload under `nightwatch.job_id` (fallback: Laravel's payload `uuid`). **Joins `queued-job` → `job-attempt`.** (`src/Hooks/CreateQueuePayloadHandler.php`) |
| `name` | string ≤ 65,535 B | `displayName()` or class |
| `connection` | string ≤ 255 B | |
| `queue` | string ≤ 255 B | SQS queue URLs are reduced by removing the configured prefix/suffix (`src/Concerns/NormalizesQueue.php`); `""` on Laravel 10 |
| `duration` | int µs | time spent pushing; `0` on Laravel < 10.42 |

```json
{"v":1,"t":"queued-job","timestamp":946688523.461989,"deploy":"v1.2.3","server":"web-01","_group":"<xxh128('App\\Jobs\\MyJob')>",
 "trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"POST /users","execution_stage":"action",
 "user":"","job_id":"00000000-0000-0000-0000-000000000000","name":"App\\Jobs\\MyJob","connection":"database",
 "queue":"default","duration":5200}
```
(`tests/Feature/Sensors/QueuedJobSensorTest.php`; the test pins `trace_id` and `execution_id` to different fake UUIDs — in
production they are equal for a request, §4.1.)

### 4.7 `job-attempt` (v1) — a worker ran a job once

Source: `src/Sensors/JobAttemptSensor.php:48-88`. `timestamp` = attempt start. `_group` = `xxh128(name)`.
Not produced for the `sync` connection (`src/Sensors/JobAttemptSensor.php:42-44`).
`trace_id` is the **dispatcher's** trace id, restored from the queue payload (§5.4).

| Field | Type | Notes |
|---|---|---|
| `job_id` | string | same value as the originating `queued-job.job_id` |
| `attempt_id` | string (UUID) | = `execution_id` of every child record produced during this attempt |
| `attempt` | int | 1-based attempt number |
| `name`, `connection`, `queue` | string | **untruncated** here |
| `status` | string enum | `"processed"` \| `"released"` \| `"failed"` (released wins over failed) |
| `duration` | int µs | |
| shared counters, `peak_memory_usage`, `exception_preview`, `context` | | §4.1 |

```json
{"v":1,"t":"job-attempt","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('App\\Jobs\\ProcessedJob')>","trace_id":"0d3ca349-e222-4982-ac23-2343692de258","user":"",
 "job_id":"5f1c…","attempt_id":"9a7e…","attempt":1,"name":"App\\Jobs\\ProcessedJob","connection":"database",
 "queue":"default","status":"processed","duration":2500,"exceptions":0,"logs":0,"queries":4,"lazy_loads":0,
 "jobs_queued":0,"mail":0,"notifications":0,"outgoing_requests":0,"files_read":0,"files_written":0,"cache_events":0,
 "hydrated_models":0,"peak_memory_usage":1234,"exception_preview":"","context":"{}"}
```
(`tests/Feature/Sensors/JobAttemptSensorTest.php`)

### 4.8 `query` (v1)

Source: `src/Sensors/QuerySensor.php:59-96`. `timestamp` = query **start** (`now − duration`).

`_group` = `xxh128("{connection},{normalised sql}")` where, **only for drivers** `mariadb`, `mysql`, `pgsql`, `sqlite`, `sqlsrv`,
`singlestore`: `/in \([\d?\s,]+\)/` → `in (...?)`, and if the SQL contains `insert`, `/values [(?,\s)]+/` → `values ...`
(case-sensitive, so lower-case Eloquent SQL only). Other drivers hash the SQL as is. The hash uses the SQL **after** any app
redaction callback. Bindings are never sent; `sql` is the prepared statement with `?` placeholders.

| Field | Type | Notes |
|---|---|---|
| `sql` | string ≤ 16,777,215 B | mutable by `redactQueries` |
| `file` | string ≤ 255 B | first non-vendor frame, relative to the app base path; falls back to first non-framework vendor frame; `""` |
| `line` | int | `0` if unknown |
| `duration` | int µs | `round(ms × 1000)` |
| `connection` | string ≤ 255 B | connection *name* |
| `connection_type` | string enum | `"read"` \| `"write"` \| `"direct"` \| `""` (Laravel < 12.45 or unknown) (`src/QueryConnectionType.php`) |

```json
{"v":1,"t":"query","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('sqlite,select * from \"users\"')>","trace_id":"00000000-0000-0000-0000-000000000000",
 "execution_source":"request","execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"GET /users",
 "execution_stage":"action","user":"","sql":"select * from \"users\"","file":"app/Http/Controllers/UserController.php",
 "line":42,"duration":4321,"connection":"sqlite","connection_type":"write"}
```
(`tests/Feature/Sensors/QuerySensorTest.php`)

### 4.9 `cache-event` (v1)

Source: `src/Sensors/CacheEventSensor.php:101-121`. `timestamp` = operation **start**. `_group` = `xxh128("{store},{key}")`
(after redaction, before truncation).

| Field | Type | Notes |
|---|---|---|
| `store` | string ≤ 255 B | `""` on Laravel 10 |
| `key` | string ≤ 255 B | mutable by `redactCacheEvents` |
| `type` | string enum | `"hit"` \| `"miss"` \| `"write"` \| `"write-failure"` \| `"delete"` \| `"delete-failure"` |
| `duration` | int µs | `0` on Laravel < 11.11 |
| `ttl` | int s | only for `write`/`write-failure`; `0` otherwise or for "forever" |

```json
{"v":1,"t":"cache-event","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('array,users:345')>","trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"POST /users","execution_stage":"action",
 "user":"","store":"array","key":"users:345","type":"miss","duration":0,"ttl":0}
```
(`tests/Feature/Sensors/CacheEventSensorTest.php`)

### 4.10 `outgoing-request` (v1)

Source: `src/Sensors/OutgoingRequestSensor.php:47-71`. `timestamp` = request **start**. `_group` = `xxh128(host)`.
Only requests that produced a response are recorded; connection failures are not (`src/Hooks/GuzzleMiddleware.php`, `TODO` there).
Captured for Laravel's HTTP client automatically and for raw Guzzle only when the app adds `Nightwatch::guzzleMiddleware()`.

| Field | Type | Notes |
|---|---|---|
| `host` | string ≤ 255 B | |
| `method` | string ≤ 255 B | |
| `url` | string ≤ 65,535 B | user-info (`user:pass@`) stripped; query string **kept**; mutable by `redactOutgoingRequests` |
| `duration` | int µs | |
| `request_size`, `response_size` | int B | body size, else `content-length`, else `0` |
| `status_code` | int | |

```json
{"v":1,"t":"outgoing-request","timestamp":946688523.459289,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('laravel.com')>","trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"POST /users","execution_stage":"action",
 "user":"","host":"laravel.com","method":"POST","url":"https://laravel.com","duration":1234000,"request_size":2000,
 "response_size":3000,"status_code":200}
```
(`tests/Feature/Sensors/OutgoingRequestSensorTest.php:61-83`)

### 4.11 `mail` (v1)

Source: `src/Sensors/MailSensor.php:69-93`. `timestamp` = instant sending **finished**. `_group` = `xxh128(class)`.
Mail sent on behalf of a notification is **not** recorded here (only as `notification`) (`src/Sensors/MailSensor.php:37-39`).

| Field | Type | Notes |
|---|---|---|
| `mailer` | string ≤ 255 B | |
| `class` | string ≤ 255 B | Mailable class; `""` on Laravel < 11.27 or for raw mail |
| `subject` | string ≤ 255 B | mutable by `redactMail` |
| `to`, `cc`, `bcc`, `attachments` | int | **counts only — no addresses are ever sent** |
| `duration` | int µs | |
| `failed` | bool | **always `false`** in this version (`TODO` in source) |

```json
{"v":1,"t":"mail","timestamp":946688523.459289,"deploy":"v1.2.3","server":"web-01","_group":"<xxh128('App\\Mail\\Welcome')>",
 "trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"POST /users","execution_stage":"action",
 "user":"","mailer":"array","class":"App\\Mail\\Welcome","subject":"Welcome!","to":3,"cc":2,"bcc":1,"attachments":1,
 "duration":2500,"failed":false}
```
(`tests/Feature/Sensors/MailSensorTest.php`)

### 4.12 `notification` (v1)

Source: `src/Sensors/NotificationSensor.php:63-83`. One record **per channel**. `timestamp` = instant sending finished.
`_group` = `xxh128(class)`.

| Field | Type | Notes |
|---|---|---|
| `channel` | string ≤ 255 B | `mail`, `database`, `broadcast`, custom class… |
| `class` | string ≤ 255 B | anonymous classes are cut at the NUL byte (`class@anonymous`) |
| `duration` | int µs | |
| `failed` | bool | **always `false`** in this version |

```json
{"v":1,"t":"notification","timestamp":946688523.459289,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('App\\Notifications\\MyNotification')>","trace_id":"00000000-0000-0000-0000-000000000000",
 "execution_source":"request","execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"POST /users",
 "execution_stage":"action","user":"","channel":"mail","class":"App\\Notifications\\MyNotification","duration":2500,
 "failed":false}
```
(`tests/Feature/Sensors/NotificationSensorTest.php`)

### 4.13 `exception` (**v3**)

Source: `src/Sensors/ExceptionSensor.php:91-115` (normal) and `src/SensorManager.php:272-299` (fatal errors).
`timestamp` = when it was reported.

`_group` = `xxh128("{class},{code},{file},{line}")` using the **reported location** below (not necessarily where it was thrown);
when the line is unknown the string ends with a trailing comma, e.g. `xxh128("Exception,999,resources/views/x.blade.php,")`
(`tests/Feature/Sensors/ExceptionSensorTest.php:297`). Message is **not** part of the group.

| Field | Type | Notes |
|---|---|---|
| `class` | string ≤ 65,535 B | For Blade `ViewException`s (Laravel's and Ignition's) the wrapped original exception is used |
| `file` | string ≤ 65,535 B (255 for fatals) | Path **relative to the app base path**. If thrown inside `vendor/`, it is the first non-vendor frame of the trace (falls back to the throw site). Blade: the view path. (`src/Location.php:96-179`) |
| `line` | int | `0` when unknown |
| `message` | string ≤ 65,535 B | mutable by `redactExceptions` |
| `code` | **string** | `(string) $e->getCode()` — PDO codes are strings like `"42S02"` |
| `trace` | **string containing JSON**, ≤ 16,777,215 B | see below; `""` for fatal errors |
| `handled` | bool | `true` = reported via `report()`/caught & reported; `false` = reached the exception handler unhandled. Exceptions the app's handler would not report (`shouldReport() === false`) are not sent when `handled` is known. |
| `php_version` | string | `8.4.1` |
| `laravel_version` | string | `11.33.0` |

**Do exception records carry source-code context? — Yes.** `trace` is a JSON-encoded **string** (decode it a second time) holding
an array of frames (`src/Sensors/ExceptionSensor.php:133-212`):

```json
[
  {"file":"app/Http/Controllers/ExceptionTestController.php:18","source":"","code":{"13":"final class ExceptionTestController","14":"{","…":"…","18":"            Mail::to('test@test.com')->send(new MyMail(['effect' => 'This explodes']));","…":"…","23":"        }"}},
  {"file":"vendor/laravel/framework/src/Illuminate/Routing/Controller.php:54","source":"App\\Http\\ExceptionTestController->__invoke()","code":null},
  {"file":"[internal function]","source":"Closure->{closure}(Illuminate\\Http\\Request, string)","code":null}
]
```

- `file` — `"{path relative to base path}:{line}"`; absolute path when outside the app; `"[internal function]"` /
  `"[unknown file]"` when PHP gives none; the `:{line}` suffix is omitted when there is no line.
  **Frame 0 is synthetic**: the throw site (`$e->getFile():$e->getLine()`) with `source: ""`. The remaining frames are
  `$e->getTrace()` in PHP order (innermost first). Up to two leading `HandleExceptions` frames are removed for PHP errors
  converted to `ErrorException`.
- `source` — `Class` + `->`/`::` + `function` + `(` + **argument types only** + `)`. Types are `null`, `bool`, `int`, `float`,
  `array`, `string`, `resource`, a class name, or `[unknown]`; named arguments render as `name: type`.
  **Argument values are never sent.** PHP's `zend.exception_ignore_args=On` (the production default) yields `()`.
- `code` — `null`, or an **object keyed by 1-based line number (as strings)** → the source line without its line ending. Window:
  the frame's line **± 5** lines (11 lines max). Captured only when **all** hold (`src/Sensors/ExceptionSensor.php:214-251`):
  `capture_exception_source_code` is true (**default true**, env `NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE`);
  the file is an *application* file (under the base path, not under `vendor/`, not `artisan`, not `public/index.php`);
  fewer than **10** frames of this exception already carry code. Vendor frames therefore always have `code: null`.
- There is **no separate `source_lines` field**, and no "is application frame" flag: a server infers vendor frames from the
  `vendor/` path prefix.
- Only the outermost (after view unwrapping) exception is sent; **`getPrevious()` chains are not serialised.**

Fatal errors (`Symfony\…\FatalError`, e.g. out of memory) use a reduced record: `v: 3`, `execution_id: ""`, `trace: ""`,
`handled: false`, `file` ≤ 255 B, `class`/`code` untruncated, `_group` from the raw file/line, and the app buffer is discarded
first (`src/SensorManager.php:272-299`, `src/Concerns/CapturesState.php:261-266`).

```json
{"v":3,"t":"exception","timestamp":946688523.456789,"deploy":"v1.2.3","server":"web-01",
 "_group":"<xxh128('App\\Exceptions\\MyException,0,app/Http/Controllers/UserController.php,27')>",
 "trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"GET /users","execution_stage":"action",
 "user":"","class":"App\\Exceptions\\MyException","file":"app/Http/Controllers/UserController.php","line":27,
 "message":"Whoops!","code":"0",
 "trace":"[{\"file\":\"app/Http/Controllers/UserController.php:27\",\"source\":\"\",\"code\":{\"26\":\"    {\",\"27\":\"        throw new MyException('Whoops!');\",\"28\":\"    }\"}},{\"file\":\"vendor/laravel/framework/src/Illuminate/Routing/Controller.php:54\",\"source\":\"App\\\\Http\\\\Controllers\\\\UserController->index()\",\"code\":null}]",
 "handled":false,"php_version":"8.4.1","laravel_version":"11.33.0"}
```
(`tests/Feature/Sensors/ExceptionSensorTest.php:88-147`, `:850-919`. The `code` window is shortened in this example.)

### 4.14 `log` (v1)

Source: `src/Sensors/LogSensor.php:29-52`. `timestamp` = the Monolog record's datetime (UTC). **No `_group`.**
Only records written to the package's `nightwatch` log channel are captured (the app adds that channel to its stack), filtered by
minimum level `nightwatch.filtering.log_level` = `NIGHTWATCH_LOG_LEVEL` → `LOG_LEVEL` → `debug`
(`config/nightwatch.php:26`, `src/Hooks/LogHandler.php:32-35`).

| Field | Type | Notes |
|---|---|---|
| `level` | string enum | PSR-3: `"debug"` \| `"info"` \| `"notice"` \| `"warning"` \| `"error"` \| `"critical"` \| `"alert"` \| `"emergency"` |
| `message` | string ≤ 65,535 B | **after** PSR placeholder interpolation (`{user}` replaced from context), so the raw template is not available |
| `context` | string (JSON object) ≤ 65,535 B | normalised by Monolog's `NormalizerFormatter` (objects → arrays/class names, dates → `Y-m-d H:i:s.uP` UTC); `"{}"` when empty |
| `extra` | string (JSON object) ≤ 65,535 B | Monolog `extra` |

```json
{"v":1,"t":"log","timestamp":1758400000.123456,"deploy":"v1.2.3","server":"web-01",
 "trace_id":"00000000-0000-0000-0000-000000000000","execution_source":"request",
 "execution_id":"00000000-0000-0000-0000-000000000001","execution_preview":"GET /users","execution_stage":"action",
 "user":"","level":"info","message":"hello world","context":"{}","extra":"{}"}
```
(`tests/Feature/Sensors/LogSensorTest.php:43-79`)

### 4.15 `user` (v1)

Source: `src/Sensors/UserSensor.php:32-39`. The smallest record: **only** `v`, `t`, `timestamp` (now), `id`, `name`, `username` —
no `deploy`, `server`, `trace_id` or `_group`.

| Field | Type | Notes |
|---|---|---|
| `id` | string ≤ 255 B | same value as the `user` field on other records |
| `name` | string ≤ 255 B | default `$user->name ?? ""` |
| `username` | string ≤ 255 B | default **`$user->email ?? ""`** — i.e. by default this is the e-mail address |

The app can replace `name`/`username` with `Nightwatch::user(fn ($user) => ['name' => …, 'username' => …])`
(`src/Core.php:71-74`, `src/UserProvider.php:128-160`).

```json
{"v":1,"t":"user","timestamp":946688523.456789,"id":"567","name":"Tim MacDonald","username":"tim@laravel.com"}
```
(`tests/Feature/Sensors/UserSensorTest.php`)

**Relationship to the `user` field elsewhere:** other records carry only the **id string**. The `user` record is a directory
upsert — "user `567` is currently called *Tim MacDonald* / *tim@laravel.com*" — emitted **once at the end of every sampled HTTP
request that has an authenticated (or just-logged-out) user** (`src/Hooks/RequestLifecycleIsLongerThanHandler.php:35-39`).
It is **never emitted from commands, jobs or scheduled tasks** (the sensor only exists for request state), so users seen only in
jobs have an id but no directory entry. A server should upsert by (`environment`, `id`) keeping the latest `timestamp`, and join
lazily. `user` records are excluded from the client-side event count (§1.6).

---

## 5. Sampling, filtering, redaction, propagation

Everything in this section happens in the app. **The server cannot tell what was dropped**: there is no sample-rate field, no
"dropped N records" counter and no sequence number on the wire.

### 5.1 Sampling

Sampling is decided **per execution (per trace entry point), not per record**; an unsampled execution sends nothing at all.

| Setting | Env | Default | Applies to |
|---|---|---|---|
| `nightwatch.sampling.requests` | `NIGHTWATCH_REQUEST_SAMPLE_RATE` | `1.0` | HTTP requests (decided in global middleware; per-route override with the `Sample` middleware) |
| `nightwatch.sampling.commands` | `NIGHTWATCH_COMMAND_SAMPLE_RATE` | `1.0` | Artisan commands |
| `nightwatch.sampling.scheduled_tasks` | `NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE` | `1.0` | each scheduled task (per-task override available) |
| `nightwatch.sampling.exceptions` | `NIGHTWATCH_EXCEPTION_SAMPLE_RATE` | `1.0` | second chance, below |

- Rates outside `[0,1]` are treated as `0` (`src/Concerns/CapturesState.php:81-94`).
- **Exception second chance:** when an exception is reported inside an *unsampled* execution, the dice are rolled again with the
  exception rate; on success the whole execution becomes sampled, including the ≤ 500 most recent buffered child records
  (`src/Concerns/CapturesState.php:256-258`, §1.5). So a dashboard will legitimately show complete traces for failed requests at a higher
  rate than for successful ones.
- **Jobs inherit** the decision of the execution that dispatched them (hidden context key `nightwatch_should_sample`;
  default *sampled* when absent) (`src/Concerns/CapturesState.php:629-631`). A sampled request's jobs are always sent; an unsampled request's
  jobs are never sent, unless rescued by an exception.
- `Nightwatch::sample()`, `dontSample()`, `pause()`, `resume()`, `ignore(fn)` let application code change all of this at runtime.

### 5.2 Filtering (records never created)

| Setting | Env | Default |
|---|---|---|
| `nightwatch.enabled` | `NIGHTWATCH_ENABLED` | `true` — when false no hooks are registered at all |
| `filtering.ignore_cache_events` / `ignore_mail` / `ignore_notifications` / `ignore_outgoing_requests` / `ignore_queries` | `NIGHTWATCH_IGNORE_*` | `false` |
| `filtering.log_level` | `NIGHTWATCH_LOG_LEVEL` → `LOG_LEVEL` | `debug` |

Plus: default vendor cache keys are dropped (`src/Concerns/RejectsRecords.php:90-102`: `illuminate:*`, `laravel:pulse:*`,
`telescope:*`, …), default vendor commands are not sampled (§4.4), and the app may register `reject*` callbacks for queries,
cache events, mail, notifications, outgoing requests and queued jobs. Filtered/rejected children are **not** counted in the
parent's counters.

### 5.3 Redaction (values altered before sending)

- **Headers** (`nightwatch.redact_headers`, default `Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN`):
  value → `[N bytes redacted]`; for `Authorization`/`Proxy-Authorization` a known scheme word is kept (`Bearer [42 bytes redacted]`);
  for `Cookie` names are kept and each value is redacted (`a=[5 bytes redacted]; b=[…]`) (`src/Concerns/RedactsHeaders.php`).
- **Payload fields** (`nightwatch.redact_payload_fields`, default `_token,password,password_confirmation`): string values at any
  depth whose key matches → `[N bytes redacted]` (`src/Sensors/RequestSensor.php:237-246`). The payload is only ever sent for
  status-500 responses and only when `capture_request_payload` is enabled (default off).
- App callbacks may rewrite the mutable fields noted in §4 (`redactRequests`, `redactQueries`, `redactExceptions`,
  `redactCacheEvents`, `redactCommands`, `redactMail`, `redactOutgoingRequests`). `_group` for queries and cache events is computed
  **after** these callbacks, so redaction changes grouping.
- Never on the wire at all: SQL bindings, mail addresses, stack-frame argument values, response bodies, request bodies for
  non-500 responses, session data.
- **On the wire by default and worth knowing:** client IP, full request URL including query string, user e-mail (as `username`),
  all non-redacted request headers, up to 11 lines of application source per captured frame, log context.

### 5.4 Context propagation request → queued job

Uses Laravel's **hidden** `Context` (serialised into the queue payload under `illuminate:log:context` by the framework), keys
(`src/Compatibility.php:148-193`):

| Key | Value | Effect in the worker |
|---|---|---|
| `nightwatch_trace_id` | the dispatcher's `trace_id` | becomes `trace_id` of the `job-attempt` and all its children |
| `nightwatch_should_sample` | bool | the job's sampling decision (§5.1) |
| `nightwatch_user_id` | the dispatcher's resolved user id (added at dehydration if not already set) | fallback for `user` when the worker has no authenticated user (`src/Hooks/ContextDehydratingHandler.php`) |

Additionally the queue payload gets `nightwatch.job_id` (UUID) for every pushed job (`src/Hooks/CreateQueuePayloadHandler.php`).
On Laravel 10 (no Context) the three keys are stored inside the payload's `nightwatch` object instead
(`src/Hooks/PolyfillContextDehydration.php`). Being *hidden* context, none of these appear in the `context` field of records.
Nothing is propagated over outgoing HTTP requests (no trace headers are injected).

---

## 6. Versioning

- **Frame version** (`v1` in the TCP frame): a contract between package and agent only; the server never sees it. Upstream's test
  states that changing it requires a new **major** package version (`tests/Unit/PayloadVersionTest.php`), and the release workflow
  compares it between releases (`.github/workflows/publish_release.yml:51-66`).
- **Record `v`**: integer schema version **per record type**. At this tag `exception` is `3` and all others `1`. The history of
  exception v1→v2→v3 is not documented in the repository; the changelog suggests source-code capture and trace-shape changes.
  A change of `v` means the shape or meaning of that type's fields changed.
- There is **no negotiation**: the agent sends no schema/feature header, only `user-agent: NightwatchAgent/{package version}`,
  and the server has no channel to ask for an older shape.

Recommended server behaviour (design guidance, not something the source mandates):

1. Dispatch on (`t`, `v`). Unknown `t` → drop the record, count it, **still answer 2xx** (a non-2xx loses the whole batch and is
   never retried, §2.3).
2. Known `t` with a **higher** `v` than implemented → best-effort parse of known fields, count it; never reject the batch.
3. Unknown fields → ignore. Missing fields → default (`""`/`0`/`false`), as older packages (1.x < 1.30) omit newer fields such as
   `connection_type`.
4. Validate per record, not per batch: one malformed record must not discard its neighbours.
5. Keep the raw `user-agent` per batch; it is the only way to correlate a shape change with a package version.

---

## 7. Server implementer's checklist

**Endpoints (all `POST`, all JSON, no redirects, TLS optional from the agent's point of view — the scheme comes from the URL):**

1. `POST /api/agent-auth` on the base URL
   - Read `Authorization: Bearer {environment token}`; ignore the `{}` body; optionally record `nightwatch-server`, `user-agent`.
   - Success → `200` with exactly these types:
     ```json
     {"token":"<opaque string>","expires_in":3600,"refresh_in":2700,"ingest_url":"https://watch-ingest.example.com/api/ingest"}
     ```
     `expires_in` and `refresh_in` **must be JSON integers** (not `3600.0`, not `"3600"`); `ingest_url` **must be absolute**.
     Keep `refresh_in` comfortably below `expires_in`; the agent refreshes on a timer and again on demand once expired.
   - Unknown/revoked token → non-2xx (401 conventional) with
     `{"message":"Invalid environment token","refresh_in":3600}`. Add `"stop":true` to make the agent drop its current short-lived
     token immediately; without it the agent keeps sending with the old token until that token expires by itself.
   - Paused / over quota → non-2xx (403 conventional) with `{"stop":true,"refresh_in":<s>,"message":"…"}` **every time** until
     the pause ends — a 2xx here resumes ingestion.
   - If you omit `refresh_in` on errors the agent uses its own back-off (§2.2): fast at first boot, 5 min / 1 h afterwards.
2. `POST {ingest_url}`
   - Verify `Authorization: Bearer {short-lived token}`.
   - Require/handle `Content-Encoding: gzip`; body → `{"records":[…]}`.
   - Success → any 2xx; body `{}` is what upstream's tests use. The body is only inspected for `stop`.
   - To pause → `{"stop":true,"refresh_in":<s>,"message":"…"}` with **any** status (200 works). `refresh_in` defaults to 900 s.
     Then fail the auth endpoint as described above for the duration of the pause.
   - **Anything non-2xx discards the batch permanently.** Prefer accepting and buffering over 429/503. Use non-2xx only when losing
     the data is acceptable. `refresh_in` without `stop` does nothing on this endpoint. There is no `Retry-After` support.
3. `POST /api/deployments` on the base URL — environment token, body per §3, any 2xx.

**Edge cases:**

- **Clock skew** — token lifetime is relative (`expires_in` seconds on the agent's clock), so server/agent skew does not matter for
  auth. Record `timestamp`s come from the *app host's* clock with no server-time reference in the request; store them as sent and
  consider recording receive time per batch to detect skewed hosts. Records may arrive late: up to 10 s agent buffering + request
  time, and long jobs/commands only flush at the end of the execution.
- **Token refresh races** — the timer refresh and the on-demand refresh can overlap, several agents (one per host/container) share
  one environment token, and up to 5 ingest requests are in flight with whatever token was current when they started. Issuing a
  new short-lived token **must not invalidate earlier ones**; each must stay valid until its own expiry. Stateless signed tokens
  satisfy this.
- **Empty batches** — the stock package never sends an empty frame and the agent never flushes an empty buffer, but while paused it
  can produce `{"records":[]}` (`agent/src/NullBuffer.php:27`) and a foreign client could send `[]` frames, which the agent would
  concatenate into invalid JSON such as `{"records":[{…},,{…}]}` (`agent/src/StreamBuffer.php:18-27`). Accept `{"records":[]}` with
  2xx; answer malformed JSON however you like — the agent only logs it.
- **Body size** — the 6 MB figure is uncompressed and soft (§2.3). Size the decompression guard from real traffic; rejecting a batch
  loses it. A ratio-based gzip-bomb guard is safer than a small absolute cap.
- **Duplicates** — none are generated by retries (there are none), but a timed-out request (10 s) may still have been processed by
  the server while the agent logs a failure. There is no idempotency key; de-duplication, if wanted, must hash the record.
- **Ordering** — batches are concurrent (≤ 5) and unordered; children arrive before parents and unhandled exceptions arrive before
  everything else in their trace (§1.5, §4.2). Never assume the parent `request` exists when a child arrives.
- **Multiple agents** — one agent per host is the norm; `nightwatch-server` identifies the sender host, while each record's
  `server` field identifies the host that *produced* the record (normally identical).
- **Response size** — the agent buffers at most 16 MiB of response body and logs at most ~1000 bytes of it; keep responses tiny.
- **`trace_id` is not guaranteed to be a UUID** (Laravel Cloud request ids).
- **`context`, `headers`, `payload`, `trace`, log `context`/`extra` are JSON *strings*** that were truncated after encoding; parse
  defensively and keep the raw string when parsing fails.

---

## 8. Open questions

Things the source at this tag does not settle. None of them block a compatible implementation.

1. **What the hosted service actually returns.** Every server behaviour above is inferred from what the agent *accepts* and from
   upstream's test doubles (`agent/tests/Response.php`). Real status codes, real `expires_in`/`refresh_in` values, whether the real
   `token` is a JWT (the test helper is named `jwt()`), and the real `ingest_url` host are unknown. Not needed for compatibility.
2. **`/api/deployments` response and validation rules** — the client ignores the body; limits on `deploy`/`name`/`url` length and
   whether repeated `deploy` values upsert are unknown. Doxa Watch is free to define them.
3. **History of exception `v` 1 → 3** — not documented in-repo; older packages in the field may send v1/v2 exception records with a
   different `trace` shape. If `doxa_ledger` (on `^1.21`) is pointed at Doxa Watch before upgrading, capture a sample and check.
   **Uncertain:** the first version that sent `v: 3`; `CHANGELOG.md` would need to be bisected against `ExceptionSensor.php` history.
4. **Whether the server is expected to recompute or trust `_group`.** The package computes it; nothing signs it. A server that
   wants different grouping (e.g. exception grouping that ignores line numbers across deploys) has all inputs except for truncated
   fields. Note that query normalisation is deliberately minimal (only `in (...)` lists and insert `values`), so literal-heavy raw
   SQL produces high-cardinality groups.
5. **`lazy_loads`, `hydrated_models`, `files_read`, `files_written`** are always `0` at this tag. Unknown whether upstream plans to
   populate them; the dashboard should hide or de-emphasise them until they are non-zero in real data.
6. **`mail.failed` / `notification.failed`** are hard-coded `false` (framework emits no failure event); failed sends simply produce
   no record (plus, usually, an exception).
7. **HTTP connection reuse** — the bundled `react/http` has a keep-alive connection manager, so consecutive ingest requests may
   reuse a connection. **Uncertain** (not traced through): idle timeout and behaviour behind proxies that close idle connections;
   a failure there costs one batch.
8. **gzip level** is PHP's default and flagged `TODO` upstream; do not depend on it.
9. **Standalone agent + base URL.** The Docker image / bare PHAR reads only `NIGHTWATCH_*` variables; the Doxa layer's defaults do
   not apply there (see `DOXA.md`). Running the stock image without `NIGHTWATCH_BASE_URL` sends the environment token to upstream's
   hosted service.
10. **Record types beyond these 13.** None exist at this tag (`grep "'t' =>" src/`), but `AGENTS.md`/changelog show upstream adds
    fields regularly (`connection_type` arrived with Laravel 12.45 support). Re-run the field audit on every upstream merge
    **(re-verify on merge)**: `src/Sensors/*`, `src/SensorManager.php::fatalError`, `agent/src/agent.php`,
    `agent/src/Ingest.php`, `agent/src/IngestDetailsRepository.php`, `src/Console/DeployCommand.php`.
