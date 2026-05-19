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

    /**
     * Sender to publish the span to on end(). Mandatory if you want the
     * span to reach OTLP — pass it in via the constructor or assign after.
     */
    public ?OtlpSender $otlpSender;

    private bool $ended = false;

    public function __construct(
        string $name,
        string $serviceName = 'my-app-logger',
        ?array $context = null,
        ?string $parentId = null,
        ?OtlpSender $otlpSender = null
    ) {
        $this->name = $name;
        $this->serviceName = $serviceName;
        $this->context = $context;
        $this->parentId = $parentId;
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
                        'endTimeUnixNano' => (string) (int) (microtime(true) * 1000000000), // Updated when the span ends!
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

    /**
     * Idempotent: a second call is a no-op so callers (and finally blocks)
     * can defensively end() without producing duplicate spans at the
     * collector.
     */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano']
            = (string) (int) (microtime(true) * 1000000000);

        if ($this->otlpSender !== null) {
            $this->otlpSender->http('/v1/traces', $this->payload);
        }
    }

    /**
     * Surface forgotten end() calls. Previously, the constructor POSTed a
     * placeholder span so a forgotten end() left a zero-duration span at
     * the collector — ugly but visible. Now an un-ended span is invisible
     * to the collector, so we emit one stderr warning per dropped span
     * to keep the loss observable.
     */
    public function __destruct()
    {
        if ($this->ended || $this->otlpSender === null) {
            return;
        }
        $stderr = fopen('php://stderr', 'w');
        if ($stderr !== false) {
            fwrite($stderr, "Span '{$this->name}' destroyed without end() — span not POSTed to OTLP\n");
        }
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
