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

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests, 'Span must POST exactly once (at end()), never from the constructor');
    }

    public function testEndPostsExactlyOnceToTracesPath(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $sender);
        $span->end();
        OtlpSender::flushAll();

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests);
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

        usleep(100_000);
        $this->assertCount(1, $this->readRequests());
    }

    public function testInjectedSenderReceivesTheSpanAtEnd(): void
    {
        $original = new OtlpSender($this->otlpHost());
        $span = new Span('op', 'svc', null, null, $original);

        $span->otlpSender = $original;
        $span->end();
        OtlpSender::flushAll();

        $this->assertCount(1, $this->waitForRequests(1));
        $this->assertSame($original, $span->otlpSender);
    }
}
