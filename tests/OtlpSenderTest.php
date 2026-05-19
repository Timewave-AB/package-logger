<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

class OtlpSenderTest extends OtlpHttpServerTestCase
{
    public function testHttpWritesStopwatchLineToStdoutPerCall(): void
    {
        $url = var_export($this->otlpHost(), true);
        $output = $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$sender->http('/v1/traces', ['a' => 1]);
            \$sender->http('/v1/logs', ['b' => 2]);
        ");

        $stopwatchLines = array_values(array_filter(
            $this->nonEmptyLines($output),
            static function (string $l): bool {
                return strpos($l, 'OTLP stopwatch') !== false;
            }
        ));

        $this->assertCount(2, $stopwatchLines, "expected one stopwatch line per http() call, got:\n{$output}");
        $this->assertMatchesRegularExpression('#OTLP stopwatch.*?/v1/traces.*?\d+ms#', $stopwatchLines[0]);
        $this->assertMatchesRegularExpression('#OTLP stopwatch.*?/v1/logs.*?\d+ms#', $stopwatchLines[1]);
    }

    public function testCurlHandleIsReusedAcrossHttpCalls(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $sender->http('/v1/traces', ['a' => 1]);

        $rp = new \ReflectionProperty(OtlpSender::class, 'curlHandle');
        $rp->setAccessible(true);
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

        $requests = $this->readRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('/v1/traces', $requests[0]['path']);
        $this->assertSame('/v1/logs', $requests[1]['path']);
    }

    public function testDeferredFlushHappensOnShutdown(): void
    {
        $url = var_export($this->otlpHost(), true);
        // Subprocess registers no manual flush; the OtlpSender's shutdown hook
        // must be what actually delivers the queued request to the collector.
        $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url, true);
            \$sender->http('/v1/traces', ['shutdown' => true]);
            // process exits; shutdown hook flushes
        ");

        $requests = $this->readRequests();
        $this->assertCount(1, $requests, 'shutdown hook should flush queued requests');
        $this->assertSame('/v1/traces', $requests[0]['path']);
    }
}
