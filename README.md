# Timewave\LaravelLogger

Custom logger package for Laravel applications with opinionated log levels.

## Usage

Use the `Timewave\LaravelLogger\Classes\SpanLog` class to instantiate a spanned log. A "span" means the logs within the same instance can be tracked together.

There will always be output to `stdout`. If Open Telemetry is configured, it will be pushed there to.

Use like so:

```php
$log = new SpanLog('request', ['requestId' => 'Legodalf']);
$log->info('Something happened', ['local' => 'thing']);
```

!!! BELOW USAGE IS BROKEN FOR NOW

To create spans within spans, do this:

```php
$username = 'siv';

$log = new SpanLog('request', ['requestId' => 'Legodalf']);
$log->info('User is trying to login', ['username' => $username]);
$userId = User::login($username);
$subLog = new SpanLog('authedUser', ['userId' => $userId], $log);
$subLog->info('user is doing something'); // This will keep the context of being a user logged in during the request with the specific id
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

## Configuration

In Laravel, set the following config keys in config/logging.php file:

- `level`: Sets the desired log level.
- `format`:Sets the desired output format.
- `textDelimiter`: Sets the desired delimiter when `format` is set to `text` (available options: `space` or `tab` [default]).
- `otlpCollectorEndpoint`: Sets the DSN for the protobuf OTLP collector.

## Local development

You'll need a Laravel installation that can house this package while developing it locally. Follow these simple steps to get up and running:

1. Create a fresh Laravel installation to house this package
2. Create a `packages` folder in the root of the Laravel installation
3. Clone this repo into the packages folder
4. Add `"Timewave\\LaravelLogger\\": "packages/package-laravel-logger/src/"` to the `psr-4` section inside your `composer.json` (for the Laravel installation)
5. Execute `composer dump-autoload`

You should now be ready to consume the package as if you had installed it from packagist. 
