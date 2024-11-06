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

## Local development

Either register an auto loader, or explicitly require all PHP-files in this repo, and then just start using and developing.
