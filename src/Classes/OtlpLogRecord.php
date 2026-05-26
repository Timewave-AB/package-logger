<?php

namespace Timewave\Logger\Classes;

use Timewave\Logger\Enums\LogLevel;

class OtlpLogRecord
{
    public static function build(
        string $serviceName,
        int $unixMicroTime,
        LogLevel $level,
        string $message,
        ?array $context = null,
        ?\Throwable $exception = null,
        ?Span $span = null
    ): array {
        switch ($level->value) {
            case LogLevel::DEBUG:
                $severityNumber = 5;
                break;
            case LogLevel::VERBOSE:
                $severityNumber = 8;
                break;
            case LogLevel::INFO:
                $severityNumber = 9;
                break;
            case LogLevel::WARNING:
                $severityNumber = 13;
                break;
            case LogLevel::ERROR:
                $severityNumber = 17;
                break;
            default:
                // Falling through to 0 would silently emit UNSPECIFIED to OTLP.
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
                        'value' => ['stringValue' => $serviceName]
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
}
