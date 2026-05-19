<?php

namespace Timewave\Logger\Classes;

class Span
{
    public string $id;

    public array $payload;

    public string $traceId;

    public string $name;

    public string $serviceName;

    public ?array $context;

    public ?string $parentId;

    public ?string $otlpHttpHost;

    public ?OtlpSender $otlpSender;

    public function __construct(
        string $name,
        string $serviceName = 'my-app-logger',
        ?array $context = null,
        ?string $parentId = null,
        ?string $otlpHttpHost = null,
        ?OtlpSender $otlpSender = null
    ) {
        $this->name = $name;
        $this->serviceName = $serviceName;
        $this->context = $context;
        $this->parentId = $parentId;
        $this->otlpHttpHost = $otlpHttpHost;
        $this->otlpSender = $otlpSender;

        $this->id = $this->createSpanId();
        $this->traceId = $this->createTraceId();

        $this->payload = [
            'resourceSpans' => [[
                'resource' => [
                    'attributes' => [[
                        'key' => 'service.name',
                        'value' => ['stringValue' => $this->serviceName]
                    ]]
                ],
                'scopeSpans' => [[
                    'spans' => [[
                        'traceId' => $this->traceId,
                        'spanId' => $this->id,
                        'name' => $this->name,
                        'kind' => 0, // Unspecified
                        'startTimeUnixNano' => (string) (int) (microtime(true) * 1000000000),
                        'endTimeUnixNano' => (string) (int) (microtime(true) * 1000000000), // Should be updated when the span ends!
                    ]]
                ]]
            ]]
        ];

        if ($this->context) {
            $attributes = [];

            foreach ($this->context as $key => $value) {
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

            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] = $attributes;
        }

        if ($this->parentId) {
            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['parentSpanId'] = $this->parentId;
        }

        // Span is POSTed exactly once, when end() is called. A previous version
        // also POSTed here with startTimeUnixNano == endTimeUnixNano, which
        // doubled the trace count at the collector and shipped an incomplete
        // first copy.
    }

    public function end(): void
    {
        $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano'] = (string) (int) (microtime(true) * 1000000000);

        $sender = $this->getOtlpSender();
        if ($sender !== null) {
            $sender->http('/v1/traces', $this->payload);
        }
    }

    private function getOtlpSender(): ?OtlpSender
    {
        if ($this->otlpSender !== null) {
            return $this->otlpSender;
        }
        if ($this->otlpHttpHost !== null) {
            $this->otlpSender = new OtlpSender($this->otlpHttpHost);
            return $this->otlpSender;
        }
        return null;
    }

    private function createSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    private function createTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
