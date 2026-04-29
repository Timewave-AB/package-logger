<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Classes\Span;

class SpanTest extends TestCase
{
    public function testSpanIdIs16HexCharsAndTraceIdIs32HexChars(): void
    {
        $span = new Span('op');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
    }

    public function testEachSpanGetsUniqueIds(): void
    {
        $a = new Span('op');
        $b = new Span('op');

        $this->assertNotSame($a->id, $b->id);
        $this->assertNotSame($a->traceId, $b->traceId);
    }

    public function testPayloadCarriesTraceAndSpanIds(): void
    {
        $span = new Span('op', 'svc');

        $record = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame($span->traceId, $record['traceId']);
        $this->assertSame($span->id, $record['spanId']);
        $this->assertSame('op', $record['name']);
    }

    public function testPayloadIncludesServiceNameAttribute(): void
    {
        $span = new Span('op', 'my-svc');

        $attr = $span->payload['resourceSpans'][0]['resource']['attributes'][0];
        $this->assertSame('service.name', $attr['key']);
        $this->assertSame('my-svc', $attr['value']['stringValue']);
    }

    public function testParentIdIsAttachedWhenProvided(): void
    {
        $span = new Span('op', 'svc', null, 'parent-span-id');

        $record = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertSame('parent-span-id', $record['parentSpanId']);
    }

    public function testParentIdIsAbsentWhenNotProvided(): void
    {
        $span = new Span('op');

        $record = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];
        $this->assertArrayNotHasKey('parentSpanId', $record);
    }

    public function testTimestampsCarrySubSecondPrecision(): void
    {
        // Pre-fix bug: `(int)microtime(true) * 1e9` truncated to whole seconds
        // before multiplying, so every timestamp ended in nine zeros and span
        // durations were quantized to ±1 second. The fixed form multiplies
        // first, then casts, preserving sub-second precision.
        $span = new Span('op');
        $record = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0];

        $this->assertStringEndsNotWith('000000000', $record['startTimeUnixNano']);
        $this->assertStringEndsNotWith('000000000', $record['endTimeUnixNano']);
    }

    public function testEndUpdatesEndTimestampWithSubSecondPrecision(): void
    {
        $span = new Span('op');
        $startEnd = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano'];

        // Tiny sleep to guarantee microtime advances on every reasonable system.
        usleep(1000);
        $span->end();

        $afterEnd = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['endTimeUnixNano'];

        $this->assertStringEndsNotWith('000000000', $afterEnd);
        $this->assertGreaterThan((int) $startEnd, (int) $afterEnd);
    }

    public function testContextIsSerializedAsAttributes(): void
    {
        $span = new Span('op', 'svc', ['userId' => 42, 'tenant' => 'acme']);

        $attrs = $span->payload['resourceSpans'][0]['scopeSpans'][0]['spans'][0]['attributes'];
        $this->assertCount(2, $attrs);
        $this->assertSame('userId', $attrs[0]['key']);
        $this->assertSame('42', $attrs[0]['value']['stringValue']);
        $this->assertSame('tenant', $attrs[1]['key']);
        $this->assertSame('acme', $attrs[1]['value']['stringValue']);
    }
}
