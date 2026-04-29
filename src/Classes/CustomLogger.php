<?php

namespace Timewave\Logger\Classes;

use Timewave\Logger\Contracts\CustomLoggerInterface;
use Timewave\Logger\Enums\LogFormat;
use Timewave\Logger\Enums\LogLevel;

class CustomLogger implements CustomLoggerInterface
{
    public string $serviceName;

    public string $logFormatTextDelimiter;

    public ?string $otlpHttpHost;

    private LogFormat $logFormat;

    private LogLevel $logLevel;

    private ?Span $span;

    public function __construct(
        string $serviceName = 'my-app-logger',
        string $logLevel = 'debug',
        string $logFormat = LogFormat::TEXT,
        string $logFormatTextDelimiter = "\t",
        ?string $otlpHttpHost = null,
        ?Span $span = null
    ) {
        $this->serviceName = $serviceName;
        $this->logFormatTextDelimiter = $logFormatTextDelimiter;
        $this->otlpHttpHost = $otlpHttpHost;
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

    public function createSpanLogger(string $name, ?array $context = null): CustomLogger
    {
        $span = new Span(
            $name,
            $this->serviceName,
            $context,
            $this->span !== null ? $this->span->id : null,
            $this->otlpHttpHost
        );

        return new CustomLogger(
            $this->serviceName,
            $this->logLevel->name,
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
        ?\Throwable $exception = null
    ): void {
        if ($level->value > $this->logLevel->value) {
            return;
        }

        $microNow = (int) (microtime(true) * 1000);

        if ($this->otlpHttpHost) {
            $otlpSender = new OtlpSender($this->otlpHttpHost);
            $payload = $this->toOtlpJSON($microNow, $level, $message, $context, $exception, $this->span);
            $otlpSender->http('/v1/logs', $payload);
        }

        // Add trace context to console output if span is provided
        if ($this->span !== null) {
            // $context is nullable; normalize before writing to avoid
            // auto-vivifying null into an array (deprecated on 8.1+).
            $context = $context ?? [];
            $context['traceId'] = $this->span->id;
            $context['spanId'] = $this->span->traceId;
        }

        // Format console output
        $line = array_filter([
            'level' => $level->name,
            'datetime' => date('Y-m-d H:i:s', (int)($microNow / 1000)),
            'message' => $message,
            'context' => $context,
            'exception' => $exception,
        ]);

        if ($this->logFormat->value === LogFormat::JSON) {
            $outputStr = $this->toJson($line);
        } else {
            $outputStr = $this->toText($line);
        }

        fwrite(fopen('php://stdout', 'w'), "$outputStr\n");
    }

    private function toJson(array $line): string
    {
        return json_encode($line, JSON_PARTIAL_OUTPUT_ON_ERROR, 128);
    }

    private function toOtlpJSON(
        int $unixMicroTime,
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null,
        ?Span $span = null
    ) {
        switch ($level->value) {
            case LogLevel::DEBUG:
                $severityNumber = 5;  // DEBUG
                break;
            case LogLevel::VERBOSE:
                $severityNumber = 8;  // DEBUG4
                break;
            case LogLevel::INFO:
                $severityNumber = 9;  // INFO
                break;
            case LogLevel::WARNING:
                $severityNumber = 13; // WARN
                break;
            case LogLevel::ERROR:
                $severityNumber = 17; // ERROR
                break;
            default:
                // Match the original `match` expression, which threw on
                // unhandled values. Falling through to severityNumber=0
                // ("UNSPECIFIED" in OTLP) would silently corrupt log data.
                throw new \LogicException(sprintf(
                    'Unmapped LogLevel for OTLP severity: %s (value=%d)',
                    $level->name,
                    $level->value
                ));
        }

        $attributes = [];

        if ($context !== null) {
            foreach ($context as $key => $value) {
                if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString')) || is_null($value)) {
                    $value = (string) $value;
                } else {
                    $value = 'Non-stringeable value';
                }

                $attributes[] = [
                    'key' => $key,
                    'value' => ['stringValue' => $value],
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
                        'timeUnixNano' => (string) ($unixMicroTime * 1000000),
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
            $line['context'] = http_build_query($line['context'], '', ' ');
        }

        if (array_key_exists('exception', $line)) {
            $line['exception'] = $line['exception']->__toString();
        }

        return implode($this->logFormatTextDelimiter, $line);
    }
}
