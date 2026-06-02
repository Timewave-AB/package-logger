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
            $this->otlpSender,
            $this->span !== null ? $this->span->traceId : null
        );

        return $this->wrapSpan($span);
    }

    /**
     * Root a span on an incoming W3C `traceparent` header so PHP spans join the
     * proxy's trace. A missing or malformed header falls back to a fresh trace
     * without throwing.
     */
    public function createSpanLoggerFromTraceparent(
        string $name,
        ?string $traceparent = null,
        ?array $context = null
    ): Logger {
        $parsed = self::parseTraceparent($traceparent);

        $span = new Span(
            $name,
            $this->serviceName,
            $context,
            $parsed['spanId'] ?? null,
            $this->otlpSender,
            $parsed['traceId'] ?? null
        );

        return $this->wrapSpan($span);
    }

    private function wrapSpan(Span $span): Logger
    {
        return new Logger(
            $this->serviceName,
            $this->logLevel->name,
            $this->logFormat->value,
            $this->logFormatTextDelimiter,
            $this->otlpSender,
            $span
        );
    }

    /**
     * Parse "version-traceId-spanId-flags" into ['traceId','spanId'], or null
     * when absent/invalid: 32-hex non-zero trace-id, 16-hex non-zero span-id.
     */
    private static function parseTraceparent(?string $traceparent): ?array
    {
        if ($traceparent === null) {
            return null;
        }

        $parts = explode('-', trim($traceparent));
        if (count($parts) < 4) {
            return null;
        }

        [, $traceId, $spanId] = $parts;

        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return ['traceId' => $traceId, 'spanId' => $spanId];
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
