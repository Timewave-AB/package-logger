<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

class OtlpSenderTest extends OtlpHttpServerTestCase
{
    public function testStopwatchIsSilentWhenLatencyBelowThreshold(): void
    {
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
        $this->assertNotNull($handleAfterFirst);

        $sender->http('/v1/logs', ['b' => 2]);
        $sender->flush();
        $handleAfterSecond = $rp->getValue($sender);

        $this->assertSame($handleAfterFirst, $handleAfterSecond);
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
        $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$sender->http('/v1/traces', ['shutdown' => true]);
        ");

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests);
        $this->assertSame('/v1/traces', $requests[0]['path']);
    }

    public function testFlushIsReentrancyGuarded(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $sender->http('/v1/traces', ['a' => 1]);

        $rp = new \ReflectionProperty(OtlpSender::class, 'isFlushing');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $rp->setValue($sender, true);
        $sender->flush();
        $this->assertCount(0, $this->readRequests(), 'nested flush should early-return');

        $rp->setValue($sender, false);
        $sender->flush();
        $this->assertCount(1, $this->waitForRequests(1));
    }

    public function testFlushAllDrainsEverySender(): void
    {
        $primary = new OtlpSender($this->otlpHost());
        $secondary = new OtlpSender($this->otlpHost());

        $primary->http('/v1/traces', ['a' => 1]);
        $secondary->http('/v1/logs', ['b' => 2]);
        $this->assertCount(0, $this->readRequests());

        OtlpSender::flushAll();

        $this->assertCount(2, $this->waitForRequests(2));
    }

    public function testQueueCapDropsNewItemsWhenFull(): void
    {
        $sender = new OtlpSender($this->otlpHost());

        $cap = OtlpSender::MAX_QUEUE_SIZE;
        for ($i = 0; $i < $cap + 10; $i++) {
            $sender->http('/v1/logs', ['i' => $i]);
        }

        $rp = new \ReflectionProperty(OtlpSender::class, 'queue');
        if (\PHP_VERSION_ID < 80100) {
            $rp->setAccessible(true);
        }
        $this->assertSame($cap, count($rp->getValue($sender)));
    }
}
