# Timewave\Logger

Custom logger package for PHP applications with opinionated log levels.

## Usage

There will always be output to `stdout`. If Open Telemetry is configured, it will be pushed there to.

### Basic usage:

```php
use Timewave\Logger\Classes\Logger;
use Timewave\Logger\Classes\OtlpSender;

$log = new Logger(
    'my-app-name',
    'debug',
    'text',
    "\t",
    new OtlpSender('http://localhost:4318')
);
$log->info('Something happened', ['key' => 'value']);
```

If you don't pass an `OtlpSender`, the logger only writes to stdout — OTLP is opt-in by construction. Wiring is constructor-only; the sender cannot be swapped on a live logger.

### Usage with spans

```php
$log = new Logger('auth-4', 'debug', 'text', "\t", new OtlpSender('http://localhost:4318'));

$requestSpanLog = $log->createSpanLogger('request', ['requestId' => 'Legodalf']);

$requestSpanLog->verbose('Incoming request', ['method' => 'POST', 'path' => '/auth/password']);

$loginSpanLog = $requestSpanLog->createSpanLogger('login', ['username' => 'siv']);

$loginSpanLog->info('User is trying to login');
$userId = User::login('siv');
$loginSpanLog->verbose('User is logged in');

// The underlying Span is reachable for callers that need the id or trace id
// (e.g. to set on a response header):
$traceId = $loginSpanLog->getSpan()->traceId;

$loginSpanLog->endSpan();

$requestSpanLog->debug('Request is over');

$requestSpanLog->endSpan();
```

### Linking to an incoming trace (`traceparent`)

When a reverse-proxy (e.g. nginx `ngx_otel_module` with `otel_trace_context propagate`) injects a W3C `traceparent` header, root your request span on it so PHP spans join the proxy's trace instead of starting a detached one:

```php
$requestSpanLog = $log->createSpanLoggerFromTraceparent(
    'request',
    $_SERVER['HTTP_TRACEPARENT'] ?? null,
    ['requestId' => 'Legodalf']
);
```

The header (`version-traceId-spanId-flags`) is parsed and validated (32-hex trace-id, 16-hex span-id, both non-zero). On a valid header the span adopts the incoming trace-id and uses the proxy's span-id as its parent. A missing or malformed header falls back to a fresh trace without throwing. Nested `createSpanLogger` calls inherit the parent's trace-id, so the whole request shares one trace.

## Log levels

- `error`: Unrecoverable error. Something is so broken the execution of the application can not continue.
- `warning`: Something is wrong, but the application can keep running. Must be addressed.
- `info`: All is well, but this message is important.
- `verbose`: Extra info, likely good in a production environment that is misbehaving.
- `debug`: A lot of detailed logs to debug your application [default]. Do not use in production.

## Log formats

- `json`: Outputs a string of a JSON object
- `text`: Outputs a simple string [default]

## Open Telemetry Collector endpoint

`OtlpSender` takes a DSN string in its constructor — e.g. `new OtlpSender('http://localhost:4318')`. The target must be an OTLP/HTTP endpoint. Payloads are sent JSON-encoded (`Content-Type: application/json`); most collectors (e.g. otelcol's HTTP receiver) accept this on `:4318` alongside the protobuf encoding.

**The library expects a low-latency OTLP collector — typically `otelcol` running on the same VM/pod as the application.** That local collector handles batching, retries, and outbound wire traffic. This assumption shapes the design choices below.

If OTLP is enabled, sender wiring is mandatory: construct one in your composition root and pass it into every `Logger` (and any directly-constructed `Span`) that needs OTLP. There is no library-level singleton or implicit lookup — sharing is the caller's responsibility, which keeps lifetimes explicit. One sender keeps one cURL handle, queue, and shutdown-hook entry, so reusing a single instance across the request keeps host resolution + TLS state warm and bounds resource use in long-running workers.

### OTLP sending is fire-and-forget

Every call to `OtlpSender::http()` (and every `Span::end()` / log line emitted via `Logger`) appends the payload to an in-memory queue rather than blocking on the collector. The queue is drained either:

- automatically at process shutdown via a single process-wide `register_shutdown_function` hook, or
- explicitly by calling `OtlpSender::flushAll()` (drain every sender that has queued items) or `$sender->flush()` (drain one).

Practical consequences:

- **No call ever blocks the request path on OTLP I/O.** Even if the collector is slow or hung, `http()` returns immediately. The actual cURL POST happens during the flush at shutdown.
- **PHP-FPM**: call `OtlpSender::flushAll()` *before* `fastcgi_finish_request()` if you want OTLP delivered before the response goes out; otherwise the response ships first and the flush runs during worker idle time. `fastcgi_finish_request()` exists only in the FPM SAPI.
- **Queue cap**: the queue is capped at `OtlpSender::MAX_QUEUE_SIZE` (10 000) items per sender. If the collector is dead and the queue fills, new entries are dropped and one `OTLP ERROR: queue full…` line is written to stdout until the queue drains.
- **Hard process kill (SIGKILL, OOM-killer)**: the shutdown hook does not run, so in-flight items are lost. With a local collector this gap is small; if it matters to you, call `OtlpSender::flushAll()` at critical points.
- **Forgotten `end()`**: a `Span` that is destroyed without `end()` is invisible to the collector. The destructor writes one stderr warning per dropped span (`Span 'name' destroyed without end() — span not POSTed to OTLP`) so the omission is observable.

### OTLP stopwatch (per-call latency)

Every `send()` measures its own latency, but only writes a record to stdout when the call took longer than `OtlpSender::STOPWATCH_THRESHOLD_MS` (200 ms). That gives you a production-safe signal for slow OTLP without flooding the log stream on every span.

When the threshold is exceeded the sender writes a JSON line:

```json
{"level":"WARNING","name":"otlp_stopwatch","path":"/v1/traces","latencyMs":287,"thresholdMs":200}
```

For a healthy local collector this should be effectively silent; sustained stopwatch lines mean the local collector pipeline is misbehaving.

## Local development

Everything runs through Docker via `docker-compose.yml`; no host-side PHP or composer needed.

Install dependencies:

```sh
docker compose run --rm composer install
```

Run the test suite (PHP 7.4):

```sh
docker compose run --rm phpunit
```

Run against PHP 8.3 and 8.5 as well (the package supports `^7.4 || ^8.0`):

```sh
docker compose run --rm phpunit-8.3
docker compose run --rm phpunit-8.5
```

Ad-hoc PHP invocations (e.g. trying a snippet, running a single test file):

```sh
docker compose run --rm phpunit vendor/bin/phpunit --filter SpanOtlpTest
docker compose run --rm php php -r 'echo PHP_VERSION;'
```

All image tags are pinned to exact patch versions in `docker-compose.yml`; bump them deliberately rather than relying on rolling tags.

`composer.json` also pins `config.platform.php = "7.4"` so dependency resolution always targets the lowest supported PHP. Without that pin, running `composer install` under PHP 8 (as the `composer:2.9.8` image does) would pull dev deps that drop PHP 7.4 support — e.g. `doctrine/instantiator` ≥ 2.x uses PHP 8.3 typed-constant syntax and silently breaks the PHPUnit run on 7.4.

Register an autoloader, or explicitly require the PHP files in `src/`, to consume the library from another project.

## Changelog

### 0.5.0

- Link spans to an incoming W3C `traceparent` header via `Logger::createSpanLoggerFromTraceparent()` so PHP spans join the reverse-proxy's trace instead of a detached one.
- `Span` accepts an optional `traceId`; nested `createSpanLogger` spans now inherit their parent's trace-id.

### 0.4.0

- Correct OTLP span timestamps to sub-second precision and add a per-call OTLP stopwatch (warns over a 200 ms threshold).
- OTLP sending is always fire-and-forget — the queue drains at shutdown or via `OtlpSender::flushAll()`.
- **Breaking:** dropped the OTLP singleton; an `OtlpSender` must be constructed and injected explicitly.
- **Breaking:** renamed `CustomLogger` to `Logger` and extracted `OtlpLogRecord`.

### 0.3.0

- PHP 7.4 compatibility (`^7.4 || ^8.0`) and a Docker-based test workflow.

### 0.2.2

- Fixed span-logger config propagation (log level/format were passed by the wrong enum member).

### 0.2.1

- Fixed missing `otlpHttpHost` on `Span`.

### 0.2.0

- Added OpenTelemetry (OTLP) logging.

### 0.1.0

- Initial release: stdout logger with opinionated log levels and `text`/`json` formats.
