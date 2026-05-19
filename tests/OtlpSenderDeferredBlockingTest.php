<?php

namespace Timewave\Logger\Tests;

class OtlpSenderDeferredBlockingTest extends OtlpHttpServerTestCase
{
    public function testDeferredHttpDoesNotBlockOnSlowCollector(): void
    {
        // Slow collector → synchronous http() would block ~responseDelayMs.
        // Deferred mode just appends to an in-memory queue → completes in
        // single-digit ms regardless of collector latency.
        $url = var_export($this->otlpHost(), true);
        $output = $this->runLoggerScript("
            \$sender = new \\Timewave\\Logger\\Classes\\OtlpSender($url, true);
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
            'deferred http() should return before the collector would have responded'
        );
    }

    protected function responseDelayMs(): int
    {
        return 300;
    }
}
