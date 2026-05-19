<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

/**
 * Slow collector: every response is delayed past STOPWATCH_THRESHOLD_MS so
 * each send() should emit one JSON-line stopwatch record to stdout.
 */
class OtlpSenderStopwatchTest extends OtlpHttpServerTestCase
{
    public function testStopwatchEmitsJsonLineWhenLatencyExceedsThreshold(): void
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
        // Just past the 200ms threshold; keeps the test under ~600ms total
        // wall time while still exercising the threshold-exceeded path.
        return 250;
    }
}
