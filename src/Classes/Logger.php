<?php

namespace Timewave\Logger\Classes;

use Timewave\Logger\Contracts\LoggerInterface;
use Timewave\Logger\Enums\LogFormat;
use Timewave\Logger\Enums\LogLevel;

class Logger implements LoggerInterface
{
    /** @var array<string, true> */
    private static array $deprecationsWarned = [];

    /** @var array<string, mixed> */
    private array $context;

    private LogFormat $logFormat;

    public string $logFormatTextDelimiter;

    private LogLevel $logLevel;

    private ?OtlpSender $otlpSender;

    public string $serviceName;

    private ?Span $span;

    /** @var resource|null */
    private $stdoutHandle = null;

    /** @param array<string, mixed>|null $context standing context added to every log line */
    public function __construct(
        string $serviceName = 'my-app-logger',
        string $logLevel = 'debug',
        string $logFormat = LogFormat::TEXT,
        string $logFormatTextDelimiter = "\t",
        ?OtlpSender $otlpSender = null,
        ?Span $span = null,
        ?array $context = null
    ) {
        $this->serviceName = $serviceName;
        $this->logFormatTextDelimiter = $logFormatTextDelimiter;
        $this->otlpSender = $otlpSender;
        $this->span = $span;
        $this->context = $context ?? [];

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

    /** @param array<string, mixed> $context */
    public function addContext(array $context): void
    {
        $this->context = array_replace($this->context, $context);
    }

    /** @param array<string, mixed> $context */
    private function copyWith(?Span $span, array $context): Logger
    {
        return new Logger(
            $this->serviceName,
            $this->logLevel->name,
            $this->logFormat->value,
            $this->logFormatTextDelimiter,
            $this->otlpSender,
            $span,
            $context
        );
    }

    /**
     * Opens a child span and returns a logger for it. The child inherits a
     * snapshot of this logger's standing context; the caller ends the span.
     *
     * @param array<string, mixed>|null $context log context, merged over the inherited context
     * @param array<string, scalar|\Stringable|null>|null $spanAttributes set on the span, not on log lines
     */
    public function createChildSpan(string $name, ?array $context = null, ?array $spanAttributes = null): Logger
    {
        $span = new Span(
            $name,
            $this->serviceName,
            $spanAttributes,
            $this->span !== null ? $this->span->id : null,
            $this->otlpSender,
            $this->span !== null ? $this->span->traceId : null
        );

        return $this->copyWith($span, array_replace($this->context, $context ?? []));
    }

    /**
     * Root a span on an incoming W3C `traceparent` header so PHP spans join the
     * proxy's trace. A missing or malformed header falls back to a fresh trace
     * without throwing.
     *
     * @param array<string, mixed>|null $context log context, merged over the inherited context
     * @param array<string, scalar|\Stringable|null>|null $spanAttributes set on the span, not on log lines
     */
    public function createRootSpanFromTraceparent(
        string $name,
        ?string $traceparent = null,
        ?array $context = null,
        ?array $spanAttributes = null
    ): Logger {
        $parsed = self::parseTraceparent($traceparent);

        $span = new Span(
            $name,
            $this->serviceName,
            $spanAttributes,
            $parsed['spanId'] ?? null,
            $this->otlpSender,
            $parsed['traceId'] ?? null
        );

        return $this->copyWith($span, array_replace($this->context, $context ?? []));
    }

    /**
     * @deprecated Use createChildSpan(); the second argument is span attributes there too.
     * @param array<string, scalar|\Stringable|null>|null $context span attributes (key => stringable value)
     */
    public function createSpanLogger(string $name, ?array $context = null): Logger
    {
        self::warnDeprecated(__FUNCTION__, 'createChildSpan');

        return $this->createChildSpan($name, null, $context);
    }

    /**
     * @deprecated Use createRootSpanFromTraceparent().
     * @param array<string, scalar|\Stringable|null>|null $context span attributes (key => stringable value)
     */
    public function createSpanLoggerFromTraceparent(
        string $name,
        ?string $traceparent = null,
        ?array $context = null
    ): Logger {
        self::warnDeprecated(__FUNCTION__, 'createRootSpanFromTraceparent');

        return $this->createRootSpanFromTraceparent($name, $traceparent, null, $context);
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::debug(), $message, $context, $exception);
    }

    public function endSpan(): void
    {
        if ($this->span !== null) {
            $this->span->end();
        }
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::error(), $message, $context, $exception);
    }

    /** @return array<string, mixed> what every log line from this logger carries, per-call context aside */
    public function getContext(): array
    {
        return $this->withSpanIds($this->context);
    }

    public function getSpan(): ?Span
    {
        return $this->span;
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::info(), $message, $context, $exception);
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
        $context = array_replace($this->context, $context ?? []);

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

        $line = array_filter([
            'level' => $level->name,
            'datetime' => date('Y-m-d H:i:s', (int) $now),
            'message' => $message,
            'context' => $this->withSpanIds($context),
            'exception' => $exception,
        ]);

        if ($this->logFormat->value === LogFormat::JSON) {
            $outputStr = $this->toJson($line);
        } else {
            $outputStr = $this->toText($line);
        }

        $this->writeStdout($outputStr);
    }

    /**
     * Parse "version-traceId-spanId-flags" into ['traceId','spanId'], or null
     * when absent/invalid: 2-hex version (not the reserved 'ff'), 32-hex non-zero
     * trace-id, 16-hex non-zero span-id.
     *
     * @return array{traceId: string, spanId: string}|null
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

        [$version, $traceId, $spanId] = $parts;

        if (!preg_match('/^[0-9a-f]{2}$/', $version) || $version === 'ff') {
            return null;
        }
        // W3C: version 00 must have exactly 4 fields; unknown versions may carry more.
        if ($version === '00' && count($parts) !== 4) {
            return null;
        }

        if (!preg_match('/^[0-9a-f]{32}$/', $traceId) || $traceId === str_repeat('0', 32)) {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{16}$/', $spanId) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        return ['traceId' => $traceId, 'spanId' => $spanId];
    }

    public function removeContext(string ...$keys): void
    {
        foreach ($keys as $key) {
            unset($this->context[$key]);
        }
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

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::verbose(), $message, $context, $exception);
    }

    /** Once per method per process: these sit on request paths, where per-call would mean thousands of lines. */
    private static function warnDeprecated(string $method, string $replacement): void
    {
        if (isset(self::$deprecationsWarned[$method])) {
            return;
        }
        self::$deprecationsWarned[$method] = true;

        trigger_error(
            "Timewave\\Logger: {$method}() is deprecated, use {$replacement}()",
            E_USER_DEPRECATED
        );
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::warning(), $message, $context, $exception);
    }

    /**
     * Runs $body with a child-span logger and ends the span afterwards, including
     * when $body throws. Returns whatever $body returns.
     *
     * @param array<string, mixed> $context log context, merged over the inherited context
     * @param callable(Logger): mixed $body
     * @param array<string, scalar|\Stringable|null>|null $spanAttributes set on the span, not on log lines
     * @return mixed
     */
    public function withChildSpan(string $name, array $context, callable $body, ?array $spanAttributes = null)
    {
        $child = $this->createChildSpan($name, $context, $spanAttributes);

        try {
            return $body($child);
        } finally {
            $child->endSpan();
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function withSpanIds(array $context): array
    {
        if ($this->span === null) {
            return $context;
        }

        $context['traceId'] = $this->span->traceId;
        $context['spanId'] = $this->span->id;

        return $context;
    }

    private function writeStdout(string $line): void
    {
        if ($this->stdoutHandle === null) {
            $this->stdoutHandle = fopen('php://stdout', 'w');
        }
        fwrite($this->stdoutHandle, $line . "\n");
    }
}
