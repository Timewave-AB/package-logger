# Timewave\LaravelLogger

Custom logger package for Laravel applications with opinionated log levels.

## Usage

Use the `Timewave\LaravelLogger\Facades\Logger` facade to produce log entries. 

All log levels accept the following signature:

    error(string $message, ?array $context = null, ?\Throwable $exception = null)

Output is always pushed to `stdout`. 

## Log levels

- `error`: Apocalypse! :O
- `warning`: The chaos is near
- `info`: All is well, but this message is important
- `verbose`: Extra info, likely good in a production environment
- `debug`: A lot of detailed logs to debug your application [default]

## Log formats

- `text`: Outputs a simple string
- `json`: Outputs a string of a JSON object [default]

## Configuration

- `LOG_LEVEL`: Sets the desired log level
- `LOG_FORMAT`: Sets the desired output format
- `LOG_FORMAT_TEXT_DELIMITER`: Sets the desired delimiter when `LOG_FORMAT` is set to `text` (available options: `space` or `tab` [default])

## Local development

You'll need a Laravel installation that can house this package while developing it locally. Follow these simple steps to get up and running:

1. Create a fresh Laravel installation to house this package
2. Create a `packages` folder in the root of the Laravel installation
3. Clone this repo into the packages folder
4. Add `"Timewave\\LaravelLogger\\": "packages/package-laravel-logger/src/"` to the `psr-4` section inside your `composer.json` (for the Laravel installation)
5. Execute `composer dump-autoload`

You should now be ready to consume the package as if you had installed it from packagist. 
