# Timewave\Logger

Custom logger package for PHP applications with opinionated log levels.

## Usage

There will always be output to `stdout`. If Open Telemetry is configured, it will be pushed there to.

### Basic usage:

```php
$log = new CustomLogger('my-app-name');
$log->otlpHttpHost = 'http://localhost:4318';
$log->info('Something happened', ['key' => 'value']);
```

### Usage with spans

```php
$log = new CustomLogger('auth-4');
$log->otlpHttpHost = 'http://localhost:4318';

$requestSpan = $log->createSpanLogger('request', ['requestId' => 'Legodalf']);

$requestSpan->verbose('Incoming request', ['method' => 'POST', 'path' => '/auth/password']);

$loginSpan = $requestSpan->createSpanLogger('login', ['username' => 'siv']);

$loginSpan->info('User is trying to login');
$userId = User::login('siv');
$loginSpan->verbose('User is logged in');

$loginSpan->endSpan();

$requestSpan->debug('Request is over');

$requestSpan->endSpan();
```

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

A DSN string, example: `'http://localhost:4318'`. The target must be an OTLP/HTTP endpoint. Payloads are sent JSON-encoded (`Content-Type: application/json`); most collectors (e.g. otelcol's HTTP receiver) accept this on `:4318` alongside the protobuf encoding.

Within a process, all `CustomLogger` instances pointing at the same `(host, deferred)` pair share one `OtlpSender` (one cURL handle, one shutdown hook). That keeps host resolution and TLS state warm and bounds resource use in long-running workers.

### OTLP stopwatch (per-call latency)

`OtlpSender` can emit a JSON-line stopwatch record per call so you can see how long each OTLP POST took:

```json
{"level":"DEBUG","name":"otlp_stopwatch","path":"/v1/traces","latencyMs":12}
```

It is **off by default** to avoid log flooding in production. Turn it on for diagnostics:

```php
$log = new CustomLogger('my-app-name');
$log->otlpHttpHost = 'http://localhost:4318';
// Grab the shared sender and enable instrumentation:
\Timewave\Logger\Classes\OtlpSender::shared($log->otlpHttpHost, $log->otlpDeferred)->stopwatchEnabled = true;
```

### Deferred (fire-and-forget) OTLP

By default OTLP HTTP calls are synchronous: they block the request path until the collector responds. To keep them off the critical path, enable deferred mode — calls are queued in memory and flushed at process shutdown (or manually via `OtlpSender::flush()`).

```php
$log = new CustomLogger('my-app-name');
$log->otlpHttpHost = 'http://localhost:4318';
$log->otlpDeferred = true;
```

Caveats — read before turning this on in production:

- **PHP-FPM**: pair with `fastcgi_finish_request()` to send the response to the client *before* the deferred flush runs. This function exists only in the FPM SAPI; calling it from CLI/Apache will fatal. The worker stays busy during the post-response flush, so size your worker pool to cover the extra time.
- **Long-running workers** (FPM, RoadRunner, FrankenPHP, Swoole, queue workers): one `OtlpSender` per `(host, deferred)` pair is shared for the life of the process, so deferred mode is safe to use without leaking shutdown hooks per request.
- **Queue cap**: the in-memory queue is capped at `OtlpSender::MAX_QUEUE_SIZE` (10 000) items. If the collector is down and the queue fills, new entries are dropped and a single `OTLP ERROR: deferred queue full…` line is written to stdout until the queue drains.
- **Forgotten `end()`**: in synchronous and deferred mode alike, a `Span` that is destroyed without `end()` is invisible to the collector. The destructor writes one stderr warning per dropped span (`Span 'name' destroyed without end() — span not POSTed to OTLP`) so the omission is observable.

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
