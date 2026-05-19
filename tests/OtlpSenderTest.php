<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

class OtlpSenderTest extends OtlpHttpServerTestCase
{
    public function testStopwatchIsSilentWhenLatencyBelowThreshold(): void
    {
        // Fast in-process collector — call completes in single-digit ms,
        // well under STOPWATCH_THRESHOLD_MS, so nothing should be written.
        // Subprocess so its shutdown hook is what flushes.
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

    public function testCurlHandleIsReusedAcrossFlushes(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $sender->http('/v1/traces', ['a' => 1]);
        $sender->flush();

        $rp = new \ReflectionProperty(OtlpSender::class, 'curlHandle');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $handleAfterFirst = $rp->getValue($sender);
        $this->assertNotNull($handleAfterFirst, 'curl handle should be retained after first flush');

        $sender->http('/v1/logs', ['b' => 2]);
        $sender->flush();
        $handleAfterSecond = $rp->getValue($sender);

        $this->assertSame(
            $handleAfterFirst,
            $handleAfterSecond,
            'OtlpSender should reuse the same cURL handle across flushes (keeps host resolution and TLS state)'
        );
    }

    public function testFlushSendsAllQueuedRequests(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $sender->http('/v1/traces', ['a' => 1]);
        $sender->http('/v1/logs', ['b' => 2]);

        $this->assertCount(0, $this->readRequests(), 'queued requests must not be sent before flush');

        $sender->flush();

        $requests = $this->waitForRequests(2);
        $this->assertCount(2, $requests);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertSame('/v1/logs', $requests[1]['path']);
    }

    public function testFlushHappensAutomaticallyOnShutdown(): void
    {
        $url = var_export($this->otlpHost(), true);
        // Subprocess registers no manual flush; the process-wide shutdown
        // hook must be what actually delivers the queued request.
        $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$sender->http('/v1/traces', ['shutdown' => true]);
            // process exits; shutdown hook flushes
        ");

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests, 'shutdown hook should flush queued requests');
        $this->assertSame('/v1/traces', $requests[0]['path']);
    }

    public function testFlushIsReentrancyGuarded(): void
    {
        $sender = new OtlpSender($this->otlpHost());
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

    public function testFlushAllDrainsEverySender(): void
    {
        // Two separately-constructed senders pointing at the same host —
        // flushAll() must find both via the static "needs flush" tracking set,
        // not via any registry lookup (the singleton design was dropped).
        $primary = new OtlpSender($this->otlpHost());
        $secondary = new OtlpSender($this->otlpHost());

        $primary->http('/v1/traces', ['a' => 1]);
        $secondary->http('/v1/logs', ['b' => 2]);
        $this->assertCount(0, $this->readRequests(), 'senders queue, do not send eagerly');

        OtlpSender::flushAll();

        $this->assertCount(2, $this->waitForRequests(2));
    }

    public function testQueueCapDropsNewItemsWhenFull(): void
    {
        $sender = new OtlpSender($this->otlpHost());

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

}
