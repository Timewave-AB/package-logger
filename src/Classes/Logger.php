<?php

namespace Timewave\Logger\Classes;

use Timewave\Logger\Contracts\LoggerInterface;
use Timewave\Logger\Enums\LogFormat;
use Timewave\Logger\Enums\LogLevel;

class Logger implements LoggerInterface
{
    public string $serviceName;

    public string $logFormatTextDelimiter;

    private ?OtlpSender $otlpSender;

    private LogFormat $logFormat;

    private LogLevel $logLevel;

    private ?Span $span;

    /** @var resource|null */
    private $stdoutHandle = null;

    public function __construct(
        string $serviceName = 'my-app-logger',
        string $logLevel = 'debug',
        string $logFormat = LogFormat::TEXT,
        string $logFormatTextDelimiter = "\t",
        ?OtlpSender $otlpSender = null,
        ?Span $span = null
    ) {
        $this->serviceName = $serviceName;
        $this->logFormatTextDelimiter = $logFormatTextDelimiter;
        $this->otlpSender = $otlpSender;
        $this->span = $span;

        switch (strtoupper($logLevel)) {
            case 'ERROR':
                $this->logLevel = LogLevel::error();
                break;
            case 'WARNING':
                $this->logLevel = LogLevel::warning();
                break;
            case 'INFO':
                $this->logLevel = LogLevel::info();
                break;
            case 'VERBOSE':
                $this->logLevel = LogLevel::verbose();
                break;
            default:
                $this->logLevel = LogLevel::debug();
                break;
        }

        $this->logFormat = LogFormat::tryFrom($logFormat) ?? LogFormat::text();
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::debug(), $message, $context, $exception);
    }

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::verbose(), $message, $context, $exception);
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::info(), $message, $context, $exception);
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::warning(), $message, $context, $exception);
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::error(), $message, $context, $exception);
    }

    public function createSpanLogger(string $name, ?array $context = null): Logger
    {
        $span = new Span(
            $name,
            $this->serviceName,
            $context,
            $this->span !== null ? $this->span->id : null,
            $this->otlpSender
        );

        return new Logger(
            $this->serviceName,
            $this->logLevel->name,
            $this->logFormat->value,
            $this->logFormatTextDelimiter,
            $this->otlpSender,
            $span
        );
    }

    public function endSpan(): void
    {
        if ($this->span !== null) {
            $this->span->end();
        }
    }

    public function getSpan(): ?Span
    {
        return $this->span;
    }

    public function log(
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null
    ): void {
        if ($level->value > $this->logLevel->value) {
            return;
        }

        $now = microtime(true);
        $timeUnixNano = (int) ($now * 1000000000);

        if ($this->otlpSender !== null) {
            $payload = OtlpLogRecord::build(
                $this->serviceName,
                $timeUnixNano,
                $level,
                $message,
                $context,
                $exception,
                $this->span
            );
            $this->otlpSender->http('/v1/logs', $payload);
        }

        if ($this->span !== null) {
            $context = $context ?? []; // null auto-vivify into array is deprecated on 8.1+
            $context['traceId'] = $this->span->traceId;
            $context['spanId'] = $this->span->id;
        }

        $line = array_filter([
            'level' => $level->name,
            'datetime' => date('Y-m-d H:i:s', (int) $now),
            'message' => $message,
            'context' => $context,
            'exception' => $exception,
        ]);

        if ($this->logFormat->value === LogFormat::JSON) {
            $outputStr = $this->toJson($line);
        } else {
            $outputStr = $this->toText($line);
        }

        $this->writeStdout($outputStr);
    }

    private function writeStdout(string $line): void
    {
        if ($this->stdoutHandle === null) {
            $this->stdoutHandle = fopen('php://stdout', 'w');
        }
        fwrite($this->stdoutHandle, $line . "\n");
    }

    private function toJson(array $line): string
    {
        return json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);
    }

    private function toText(array $line): string
    {
        if (array_key_exists('context', $line)) {
            $line['context'] = http_build_query($line['context'], '', ' ');
        }

        if (array_key_exists('exception', $line)) {
            $line['exception'] = $line['exception']->__toString();
        }

        return implode($this->logFormatTextDelimiter, $line);
    }
}
