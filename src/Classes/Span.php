<?php

namespace Timewave\Logger\Classes;

class Span
{
    public readonly string $id;

    public array $payload;

    public readonly string $traceId;

    public function __construct(
        public string $name,
        public string $serviceName = 'my-app-logger',
        public ?array $context = null,
        public ?string $parentId = null,
        public ?string $otlpHttpHost = null, // For example http://10.130.40.33:4318
    )
    {
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
                        'startTimeUnixNano' => (string) ((int)microtime(true) * 1000000000),
                        'endTimeUnixNano' => (string) ((int)microtime(true) * 1000000000), // Should be updated when the span ends!
                    ]]
                ]]
            ]]
        ];

        if ($this->context) {
            $attributes = [];

            foreach ($this->context as $key => $value) {
                $attributes[] = [
                    'key' => $key,
                    'value' => ['stringValue' => (string) $value],
                ];
            }

            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] = $attributes;
        }

        if ($this->parentId) {
            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['parentSpanId'] = $this->parentId;
        }

        $otlpSender = new OtlpSender($this->otlpHttpHost);
        $otlpSender->http('/v1/traces', $this->payload);
    }

    public function end(): void
    {
        $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano'] = (string) ((int)microtime(true) * 1000000000);
        $otlpSender = new OtlpSender($this->otlpHttpHost);
        $otlpSender->http('/v1/traces', $this->payload);
    }

    private function createSpanId(): string {
        return bin2hex(random_bytes(8));
    }

    private function createTraceId(): string {
        return bin2hex(random_bytes(16));
    }
}
