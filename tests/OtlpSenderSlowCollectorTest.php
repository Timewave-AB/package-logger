<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

/**
 * Slow collector (300 ms response delay) — every flushed call to send()
 * waits long enough to (a) prove http() itself never blocks on the
 * collector and (b) trip the stopwatch threshold.
 */
class OtlpSenderSlowCollectorTest extends OtlpHttpServerTestCase
{
    public function testHttpDoesNotBlockOnSlowCollector(): void
    {
        $url = var_export($this->otlpHost(), true);
        $output = $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$t0 = microtime(true);
            \$sender->http('/v1/traces', ['a' => 1]);
            \$elapsedMs = (int) round((microtime(true) - \$t0) * 1000);
            echo \"queued in {\$elapsedMs}ms\\n\";
        ");

        $line = '';
        foreach ($this->nonEmptyLines($output) as $l) {
            if (strpos($l, 'queued in') === 0) {
                $line = $l;
                break;
            }
        }
        $this->assertNotSame('', $line, "expected 'queued in' line, got:\n{$output}");
        preg_match('/queued in (\d+)ms/', $line, $m);
        $this->assertLessThan(
            $this->responseDelayMs(),
            (int) $m[1],
            'http() should return before the collector would have responded — fire-and-forget queues, never blocks'
        );
    }

    public function testStopwatchEmitsJsonLineWhenLatencyExceedsThreshold(): void
    {
        $url = var_export($this->otlpHost(), true);
        $output = $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url);
            \$sender->http('/v1/traces', ['a' => 1]);
            \$sender->http('/v1/logs', ['b' => 2]);
            // process exits; shutdown hook flushes, each send() trips the threshold
        ");

        $stopwatchLines = array_values(array_filter(
            $this->nonEmptyLines($output),
            static function (string $l): bool {
                $decoded = json_decode($l, true);
                return is_array($decoded) && ($decoded['name'] ?? null) === 'otlp_stopwatch';
            }
        ));

        $this->assertCount(2, $stopwatchLines, "expected one stopwatch line per slow call; got:\n{$output}");

        $first = json_decode($stopwatchLines[0], true);
        $second = json_decode($stopwatchLines[1], true);
        $this->assertSame('/v1/traces', $first['path']);
        $this->assertSame('/v1/logs', $second['path']);
        $this->assertGreaterThan(OtlpSender::STOPWATCH_THRESHOLD_MS, $first['latencyMs']);
        $this->assertSame(OtlpSender::STOPWATCH_THRESHOLD_MS, $first['thresholdMs']);
        $this->assertSame('WARNING', $first['level']);
    }

    protected function responseDelayMs(): int
    {
        // Past the 200ms stopwatch threshold; comfortably above any
        // round-trip noise on a busy CI runner.
        return 300;
    }
}
