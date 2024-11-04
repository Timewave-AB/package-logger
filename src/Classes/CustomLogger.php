<?php

namespace Timewave\LaravelLogger\Classes;

use Timewave\LaravelLogger\Enums\LogFormat;
use Timewave\LaravelLogger\Enums\LogLevel;

class CustomLogger
{
    // We have these private since we want to ensure their format via the constructor
    private LogFormat $logFormat;

    private LogLevel $logLevel;

    public function __construct(
        public string $serviceName = 'my-app-logger',
        string $logLevel = 'debug',
        string $logFormat = LogFormat::TEXT->value,
        public string $logFormatTextDelimiter = "\t",
        public ?string $otlpHttpHost = null, // For example http://10.130.40.33:4318
        private ?Span $span = null,
    )
    {
        $this->logLevel = match (strtoupper($logLevel)) {
            'ERROR' => LogLevel::ERROR,
            'WARNING' => LogLevel::WARNING,
            'INFO' => LogLevel::INFO,
            'VERBOSE' => LogLevel::VERBOSE,
            default => LogLevel::DEBUG,
        };

        $this->logFormat = LogFormat::tryFrom($logFormat) ?? LogFormat::TEXT;

        $this->otlpHttpHost = $otlpHttpHost;
    }

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::DEBUG, $message, $context, $exception);
    }

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::VERBOSE, $message, $context, $exception);
    }

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::INFO, $message, $context, $exception);
    }

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::WARNING, $message, $context, $exception);
    }

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void
    {
        $this->log(LogLevel::ERROR, $message, $context, $exception);
    }

    public function createSpanLogger(string $name, ?array $context = null, ?string $parentId = null): CustomLogger {
        $span = new Span(
            $name,
            $this->serviceName,
            $context,
            $this->span !== null ? $this->span->id : null,
            $this->otlpHttpHost
        );

        return new CustomLogger(
            $this->serviceName,
            $this->logLevel->value,
            $this->logFormat->value,
            $this->logFormatTextDelimiter,
            $this->otlpHttpHost,
            $span
        );
    }

    public function endSpan(): void
    {
        if ($this->span !== null) {
            $this->span->end();
        }
    }

    public function log(
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null,
    ): void {
        if ($level->value > $this->logLevel->value) {
            return;
        }

        $now = time();

        if ($this->otlpHttpHost) {
            $otlpSender = new OtlpSender($this->otlpHttpHost);
            $payload = $this->toOtlpJSON($now, $level, $message, $context, $exception, $this->span);
            $otlpSender->http('/v1/logs', $payload);
        }

        // Add trace context to console output if span is provided
        if ($this->span !== null) {
            $context['traceId'] = $this->span->id;
            $context['spanId'] = $this->span->traceId;
        }

        // Format console output
        $line = array_filter([
            'level' => $level->name,
            'datetime' => date('Y-m-d H:i:s', $now),
            'message' => $message,
            'context' => $context,
            'exception' => $exception,
        ]);

        $outputStr = match ($this->logFormat->value) {
            LogFormat::JSON->value => $this->toJson($line),
            default => $this->toText($line),
        };

        fwrite(STDOUT, "$outputStr\n");
    }

    private function toJson(array $line): string
    {
        return json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);
    }

    private function toOtlpJSON(
        int $unixTime,
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null,
        ?Span $span = null,
    )
    {
        $severityNumber = match ($level->value) {
            4 => 5,  // Debug => DEBUG
            3 => 8,  // Verbose => DEBUG4
            2 => 9,  // Info => INFO
            1 => 13, // Warning => WARN
            0 => 17, // Error => ERROR
        };

        $attributes = [];

        if ($context !== null) {
            foreach ($context as $key => $value) {
                $attributes[] = [
                    'key' => $key,
                    'value' => ['stringValue' => (string) $value],
                ];
            }
        }

        if ($exception !== null) {
            $attributes[] = [
                'key' => 'exception',
                'value' => ['stringValue' => (string) $exception->getMessage()],
            ];
        }

        $payload = [
            'resourceLogs' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => $this->serviceName]
                    ]]
                ],
                'scopeLogs' => [[
                    'scope' => [
                        'name' => 'timewave-logger'
                    ],
                    'logRecords' => [[
                        'timeUnixNano' => (string) ((int)microtime(true) * 1000000000),
                        'severityNumber' => $severityNumber,
                        'severityText' => $level->name,
                        'body' => [
                            'stringValue' => $message
                        ],
                    ]]
                ]]
            ]]
        ];

        if ($span !== null) {
            $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['traceId'] = $span->traceId;
            $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['spanId'] = $span->id;
        }

        if (count($attributes) > 0) {
            $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'] = $attributes;
        }

        return $payload;
    }

    private function toText(array $line): string
    {
        if (array_key_exists('context', $line)) {
            $line['context'] = http_build_query(data: $line['context'], arg_separator: ' ');
        }

        if (array_key_exists('exception', $line)) {
            $line['exception'] = $line['exception']->__toString();
        }

        return implode($this->logFormatTextDelimiter, $line);
    }
}
