<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

class OtlpSenderTest extends OtlpHttpServerTestCase
{
    public function testStopwatchIsSilentWhenLatencyBelowThreshold(): void
    {
        // Fast in-process collector — call completes in single-digit ms,
        // well under STOPWATCH_THRESHOLD_MS, so nothing should be written.
        $url = var_export($this->otlpHost(), true);
        $output = $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$sender->http('/v1/traces', ['a' => 1]);
        ");

        $stopwatchLines = array_values(array_filter(
            $this->nonEmptyLines($output),
            static function (string $l): bool {
                return strpos($l, 'otlp_stopwatch') !== false;
            }
        ));
        $this->assertCount(0, $stopwatchLines, "stopwatch must stay silent below threshold; got:\n{$output}");
    }

    public function testCurlHandleIsReusedAcrossHttpCalls(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $sender->http('/v1/traces', ['a' => 1]);

        $rp = new \ReflectionProperty(OtlpSender::class, 'curlHandle');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $handleAfterFirst = $rp->getValue($sender);
        $this->assertNotNull($handleAfterFirst, 'curl handle should be retained after first call');

        $sender->http('/v1/logs', ['b' => 2]);
        $handleAfterSecond = $rp->getValue($sender);

        $this->assertSame(
            $handleAfterFirst,
            $handleAfterSecond,
            'OtlpSender should reuse the same cURL handle across calls (keeps host resolution and TLS state)'
        );
    }

    public function testDeferredFlushSendsAllQueuedRequests(): void
    {
        $sender = new OtlpSender($this->otlpHost(), true);
        $sender->http('/v1/traces', ['a' => 1]);
        $sender->http('/v1/logs', ['b' => 2]);

        $this->assertCount(0, $this->readRequests(), 'queued requests must not be sent before flush');

        $sender->flush();

        $requests = $this->waitForRequests(2);
        $this->assertCount(2, $requests);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertSame('/v1/logs', $requests[1]['path']);
    }

    public function testDeferredFlushHappensOnShutdown(): void
    {
        $url = var_export($this->otlpHost(), true);
        // Subprocess registers no manual flush; the process-wide shutdown
        // hook must be what actually delivers the queued request.
        $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url, true);
            \$sender->http('/v1/traces', ['shutdown' => true]);
            // process exits; shutdown hook flushes
        ");

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests, 'shutdown hook should flush queued requests');
        $this->assertSame('/v1/traces', $requests[0]['path']);
    }

    public function testFlushIsReentrancyGuarded(): void
    {
        $sender = new OtlpSender($this->otlpHost(), true);
        $sender->http('/v1/traces', ['a' => 1]);

        // Force the in-flight flag on, simulate a nested invocation, then
        // confirm the original flush still drains its batch.
        $rp = new \ReflectionProperty(OtlpSender::class, 'isFlushing');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $rp->setValue($sender, true);
        $sender->flush();
        $this->assertCount(0, $this->readRequests(), 'nested flush should early-return without sending');

        $rp->setValue($sender, false);
        $sender->flush();
        $this->assertCount(1, $this->waitForRequests(1));
    }

    public function testDeferredQueueCapDropsNewItemsWhenFull(): void
    {
        $sender = new OtlpSender($this->otlpHost(), true);

        // Fill past the cap without flushing.
        $cap = OtlpSender::MAX_QUEUE_SIZE;
        for ($i = 0; $i < $cap + 10; $i++) {
            $sender->http('/v1/logs', ['i' => $i]);
        }

        $rp = new \ReflectionProperty(OtlpSender::class, 'queue');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $this->assertSame(
            $cap,
            count($rp->getValue($sender)),
            'queue should not grow beyond MAX_QUEUE_SIZE — overflow protects long-running workers from OOM'
        );
    }

    public function testSharedReturnsSameInstancePerHostDeferredPair(): void
    {
        $a = OtlpSender::shared($this->otlpHost(), false);
        $b = OtlpSender::shared($this->otlpHost(), false);
        $this->assertSame($a, $b, 'same (host, deferred) pair must return the cached sender');

        $c = OtlpSender::shared($this->otlpHost(), true);
        $this->assertNotSame($a, $c, 'different deferred flag must yield a different sender');

        $d = OtlpSender::shared('http://other:4318', false);
        $this->assertNotSame($a, $d, 'different host must yield a different sender');
    }
}
