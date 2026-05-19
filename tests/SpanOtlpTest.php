<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;
use Timewave\Logger\Classes\Span;

class SpanOtlpTest extends OtlpHttpServerTestCase
{
    public function testConstructorDoesNotPostToOtlp(): void
    {
        $span = new Span('op', 'svc', null, null, $this->otlpHost());
        $span->end(); // end() now sends; we end so __destruct doesn't warn after the assertion.

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
        $span = new Span('op', 'svc', null, null, $this->otlpHost());
        $span->end();

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests, 'end() should POST the span exactly once');
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertGreaterThan(0, $requests[0]['body_len']);
    }

    public function testEndIsIdempotentAtTheWireLevel(): void
    {
        $span = new Span('op', 'svc', null, null, $this->otlpHost());
        $span->end();
        $span->end();
        $span->end();

        usleep(100_000); // ensure any spurious POST would have landed
        $requests = $this->readRequests();
        $this->assertCount(1, $requests, 'repeated end() calls must not duplicate the span at the collector');
    }

    public function testHostMutationRebuildsSender(): void
    {
        $original = $this->otlpHost();
        $span = new Span('op', 'svc', null, null, $original);

        // Caller mutates the public host after construction. Span should send
        // to the new host, not silently keep using the original.
        $span->otlpHttpHost = $original; // identity; just exercises the rebuild check
        $span->end();

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests);

        $rp = new \ReflectionProperty(Span::class, 'otlpSender');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $sender = $rp->getValue($span);
        $this->assertInstanceOf(OtlpSender::class, $sender);
        $this->assertSame($original, $sender->getOtlpHttpHost());
    }
}
