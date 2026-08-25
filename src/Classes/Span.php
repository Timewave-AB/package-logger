<?php

namespace Timewave\Logger\Classes;

class Span
{
    /**
     * Weak so a registered span can still be refcounted away mid-request.
     *
     * @var array<int, \WeakReference>
     */
    private static array $openSpans = [];

    public string $id;

    public ?array $context;

    private bool $ended = false;

    public string $name;

    private ?OtlpSender $otlpSender;

    public ?string $parentId;

    public array $payload;

    public string $serviceName;

    public string $traceId;

    public function __construct(
        string $name,
        string $serviceName = 'my-app-logger',
        ?array $context = null,
        ?string $parentId = null,
        ?OtlpSender $otlpSender = null,
        ?string $traceId = null
    ) {
        $this->name = $name;
        $this->serviceName = $serviceName;
        $this->context = $context;
        $this->parentId = $parentId;
        $this->otlpSender = $otlpSender;

        $this->id = $this->createSpanId();
        $this->traceId = $traceId ?? $this->createTraceId();

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
                        'kind' => 0,
                        'startTimeUnixNano' => (string) (int) (microtime(true) * 1000000000),
                        'endTimeUnixNano' => (string) (int) (microtime(true) * 1000000000),
                    ]]
                ]]
            ]]
        ];

        if ($this->context) {
            $attributes = [];

            foreach ($this->context as $key => $value) {
                $attributes[] = [
                    'key' => $key,
                    'value' => ['stringValue' => AttributeValue::stringify($value)],
                ];
            }

            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'] = $attributes;
        }

        if ($this->parentId) {
            $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['parentSpanId'] = $this->parentId;
        }

        if ($this->otlpSender !== null) {
            self::$openSpans[spl_object_id($this)] = \WeakReference::create($this);
        }
    }

    /** Ends the span so it still reaches the collector; the duration then runs to this moment, not to the work's real end. */
    public function __destruct()
    {
        if ($this->ended || $this->otlpSender === null) {
            return;
        }

        $stderr = fopen('php://stderr', 'w');
        if ($stderr !== false) {
            fwrite($stderr, "Span '{$this->name}' was not ended explicitly — ending it at destruction\n");
        }

        $this->end();
    }

    /** Idempotent — second call is a no-op. */
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;
        unset(self::$openSpans[spl_object_id($this)]);

        $this->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano']
            = (string) (int) (microtime(true) * 1000000000);

        if ($this->otlpSender !== null) {
            $this->otlpSender->http('/v1/traces', $this->payload);
        }
    }

    /** Must run before the final drain: end() enqueues, and nothing drains after it. */
    public static function endAllOpen(): void
    {
        foreach (array_keys(self::$openSpans) as $id) {
            if (!isset(self::$openSpans[$id])) {
                continue;
            }

            $span = self::$openSpans[$id]->get();
            if ($span === null) {
                unset(self::$openSpans[$id]);
                continue;
            }

            $span->end();
        }
    }

    public function hasEnded(): bool
    {
        return $this->ended;
    }

    /** @internal Test-only state reset. */
    public static function resetForTesting(): void
    {
        self::$openSpans = [];
    }

    /**
     * W3C `traceparent` for an outgoing request, so the callee's spans join this
     * trace with this span as their parent. The flags byte is always `01` —
     * everything we emit is sampled.
     */
    public function toTraceparent(): string
    {
        return "00-{$this->traceId}-{$this->id}-01";
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
