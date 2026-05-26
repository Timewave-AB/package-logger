<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\OtlpSender;

/** Slow collector (300 ms) — trips the stopwatch threshold and exercises non-blocking http(). */
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
        $this->assertSame(1, preg_match('/queued in (\d+)ms/', $line, $m), "unexpected timing format: {$line}");
        $this->assertLessThan(
            $this->responseDelayMs(),
            (int) $m[1],
            'http() must return before the collector would have responded'
        );
    }

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
        return 300;
    }
}
