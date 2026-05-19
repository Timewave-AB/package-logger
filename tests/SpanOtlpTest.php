<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\Span;

class SpanOtlpTest extends OtlpHttpServerTestCase
{
    public function testConstructorDoesNotPostToOtlp(): void
    {
        new Span('op', 'svc', null, null, $this->otlpHost());

        // Give the collector a moment in case a request is in flight.
        usleep(100_000);

        $this->assertCount(
            0,
            $this->readRequests(),
            'Span constructor must not POST a provisional span; that span is incomplete and produces a duplicate at the collector'
        );
    }

    public function testEndPostsExactlyOnceToTracesPath(): void
    {
        $span = new Span('op', 'svc', null, null, $this->otlpHost());
        $span->end();

        $requests = $this->readRequests();
        $this->assertCount(1, $requests, 'end() should POST the span exactly once');
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertGreaterThan(0, $requests[0]['body_len']);
    }
}
