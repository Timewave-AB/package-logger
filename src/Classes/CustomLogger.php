<?php

namespace Timewave\LaravelLogger\Classes;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\ConsoleOutput;
use Timewave\LaravelLogger\Enums\LogFormat;
use Timewave\LaravelLogger\Enums\LogFormatTextDelimiter;
use Timewave\LaravelLogger\Enums\LogLevel;

class CustomLogger
{
    private ConsoleOutput $output;

    private LogLevel $logLevel;

    private LogFormat $logFormat;

    private LogFormatTextDelimiter $logFormatTextDelimiter;

    public function __construct()
    {
        $this->output = new ConsoleOutput;

        $this->logLevel = match (strtoupper(Config::get('LOG_LEVEL', 'debug'))) {
            'ERROR' => LogLevel::ERROR,
            'WARNING' => LogLevel::WARNING,
            'INFO' => LogLevel::INFO,
            'VERBOSE' => LogLevel::VERBOSE,
            default => LogLevel::DEBUG,
        };

        $this->logFormat = LogFormat::tryFrom(Config::get('LOG_FORMAT', LogFormat::JSON->value)) ?? LogFormat::JSON;

        $this->logFormatTextDelimiter = LogFormatTextDelimiter::tryFrom(Config::get('LOG_FORMAT_TEXT_DELIMITER', LogFormatTextDelimiter::TAB->value)) ?? LogFormatTextDelimiter::TAB;
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->writeLogLine(LogLevel::DEBUG, $message, $context, $exception);
    }

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->writeLogLine(LogLevel::VERBOSE, $message, $context, $exception);
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->writeLogLine(LogLevel::INFO, $message, $context, $exception);
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->writeLogLine(LogLevel::WARNING, $message, $context, $exception);
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->writeLogLine(LogLevel::ERROR, $message, $context, $exception);
    }

    private function writeLogLine(LogLevel $level, string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        if ($level->value > $this->logLevel->value) {
            return;
        }

        $line = array_filter([
            'level' => $level->name,
            'datetime' => Carbon::now()->format('Y-m-d H:i:s'),
            'message' => $message,
            'context' => $context,
            'exception' => $exception,
        ]);

        $this->output->writeln(match ($this->logFormat->value) {
            LogFormat::JSON->value => $this->toJson($line),
            default => $this->toText($line),
        });
    }

    private function toJson(array $line): string
    {
        return json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);
    }

    private function toText(array $line): string
    {
        $delimiter = match ($this->logFormatTextDelimiter->value) {
            LogFormatTextDelimiter::SPACE->value => ' ',
            default => "\t",
        };

        if (array_key_exists('context', $line)) {
            $line['context'] = http_build_query(data: $line['context'], arg_separator: ' ');
        }

        if (array_key_exists('exception', $line)) {
            $line['exception'] = $line['exception']->__toString();
        }

        return implode($delimiter, $line);
    }
}
