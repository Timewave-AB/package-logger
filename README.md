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

- `error`: Apocalypse! :O
- `warning`: The chaos is near
- `info`: All is well, but this message is important
- `verbose`: Extra info, likely good in a production environment
- `debug`: A lot of detailed logs to debug your application [default]

## Log formats

- `json`: Outputs a string of a JSON object
- `text`: Outputs a simple string [default]

## Open Telemetry Collector endpoint

A DSN string, example: 'http://localhost:4318'. The target must be a protobuf endpoint.

Each OTLP call writes a one-line stopwatch to stdout (`OTLP stopwatch: /v1/traces 12ms`) so per-call latency is visible alongside the rest of the log output.

### Deferred (fire-and-forget) OTLP

By default OTLP HTTP calls are synchronous: they block the request path until the collector responds. To keep them off the critical path, enable deferred mode — calls are queued in memory and flushed at process shutdown (or manually via `OtlpSender::flush()`).

```php
$log = new CustomLogger('my-app-name');
$log->otlpHttpHost = 'http://localhost:4318';
$log->otlpDeferred = true;
```

In PHP-FPM you can pair this with `fastcgi_finish_request()` to send the response to the client before the deferred OTLP flush runs.

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
docker compose run --rm phpunit-8
docker compose run --rm phpunit-latest
```

Ad-hoc PHP invocations (e.g. trying a snippet, running a single test file):

```sh
docker compose run --rm phpunit vendor/bin/phpunit --filter SpanOtlpTest
docker compose run --rm php php -r 'echo PHP_VERSION;'
```

Register an autoloader, or explicitly require the PHP files in `src/`, to consume the library from another project.
