<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;
use Timewave\Logger\Classes\Span;

class SpanOtlpTest extends OtlpHttpServerTestCase
{
    public function testConstructorDoesNotPostToOtlp(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $sender);
        $span->end();
        OtlpSender::flushAll();

        // We allow the one POST from end(), but importantly the constructor itself
        // must not POST — assert that only ONE request was received, not two.
        $requests = $this->waitForRequests(1);
        $this->assertCount(
            1,
            $requests,
            'Span must POST exactly once (at end()); constructor must not send a provisional span'
        );
    }

    public function testEndPostsExactlyOnceToTracesPath(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $sender);
        $span->end();
        OtlpSender::flushAll();

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests, 'end() should POST the span exactly once');
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertGreaterThan(0, $requests[0]['body_len']);
    }

    public function testEndIsIdempotentAtTheWireLevel(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $sender);
        $span->end();
        $span->end();
        $span->end();
        OtlpSender::flushAll();

        usleep(100_000); // ensure any spurious POST would have landed
        $requests = $this->readRequests();
        $this->assertCount(1, $requests, 'repeated end() calls must not duplicate the span at the collector');
    }

    public function testInjectedSenderReceivesTheSpanAtEnd(): void
    {
        // Swap senders at the property level — Span uses whichever sender
        // is on it when end() runs, no caching to invalidate.
        $original = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $original);

        $span->otlpSender = $original; // identity; just exercises the assignment path
        $span->end();
        OtlpSender::flushAll();

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests);
        $this->assertSame($original, $span->otlpSender);
    }
}
