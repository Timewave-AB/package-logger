<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\Logger;
use Timewave\Logger\Classes\OtlpSender;

class LoggerOtlpTest extends OtlpHttpServerTestCase
{
    public function testEmitsTimeUnixNanoInCurrentEpochNanosecondRange(): void
    {
        // The OTLP spec defines time_unix_nano as nanoseconds since the Unix
        // epoch. We bracket "now" in nanoseconds and assert the value the
        // logger ships lies inside that window. This catches off-by-1000
        // bugs in either direction (ms-instead-of-ns OR us-multiplied-by-1e6).
        $beforeNs = (int) (microtime(true) * 1_000_000_000);

        $sender = new OtlpSender($this->otlpHost());
        $log = new Logger('svc', 'debug', 'text', "\t", $sender);
        $log->info('hello');
        OtlpSender::flushAll();

        $afterNs = (int) (microtime(true) * 1_000_000_000);

        $requests = $this->waitForRequests(1);
        $this->assertCount(1, $requests);
        $this->assertSame('/v1/logs', $requests[0]['path']);

        $decoded = json_decode($requests[0]['body'], true);
        $this->assertIsArray($decoded, 'OTLP body was not valid JSON');

        $record = $decoded['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertArrayHasKey('timeUnixNano', $record);
        $this->assertIsString($record['timeUnixNano'], 'OTLP JSON spec requires fixed64 as decimal string');

        $emitted = (int) $record['timeUnixNano'];

        // Tight window: emitted ns must fall between two real ns clocks taken
        // around the call. Anything off by 10^3 or 10^6 is far outside this.
        $this->assertGreaterThanOrEqual($beforeNs, $emitted, 'timeUnixNano predates the call');
        $this->assertLessThanOrEqual($afterNs, $emitted, 'timeUnixNano is after the call returned — likely wrong unit');
    }

    public function testStandingContextShipsAsOtlpAttributes(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $log = new Logger('svc', 'debug', 'text', "\t", $sender, null, ['env' => 'prod']);
        $child = $log->createChildSpan('op', ['tenant' => 'acme']);
        $child->info('hello', ['x' => 1]);
        $child->endSpan();
        OtlpSender::flushAll();

        $logRequests = array_values(array_filter($this->waitForRequests(2), static function (array $request): bool {
            return $request['path'] === '/v1/logs';
        }));
        $this->assertCount(1, $logRequests);

        $decoded = json_decode($logRequests[0]['body'], true);
        $this->assertIsArray($decoded, 'OTLP body was not valid JSON');

        $emitted = [];
        foreach ($decoded['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'] as $attribute) {
            $emitted[$attribute['key']] = $attribute['value']['stringValue'];
        }

        $this->assertSame(['env' => 'prod', 'tenant' => 'acme', 'x' => '1'], $emitted);
    }

    public function testUnendedSpanIsSentOnTheShutdownPath(): void
    {
        // The logger stays in scope, so no destructor runs. The shutdown entry
        // point has to close the span before draining, or the payload is queued
        // after the last drain and never leaves the process.
        $sender = new OtlpSender($this->otlpHost());
        $log = new Logger('svc', 'debug', 'text', "\t", $sender);
        $held = $log->createChildSpan('forgotten');

        OtlpSender::endSpansAndFlushAll();

        $requests = $this->waitForRequests(1);
        $this->assertSame('/v1/traces', $requests[0]['path']);

        $span = $held->getSpan();
        $this->assertNotNull($span);
        $this->assertTrue($span->hasEnded());
    }

    public function testExplicitFlushDoesNotTruncateASpanStillInFlight(): void
    {
        // Draining mid-request must not close live spans: the documented
        // pre-fastcgi_finish_request() flush would otherwise cut every request
        // span short and turn the later endSpan() into a no-op.
        $sender = new OtlpSender($this->otlpHost());
        $log = new Logger('svc', 'debug', 'text', "\t", $sender);
        $request = $log->createChildSpan('request');
        $request->info('work started');

        OtlpSender::flushAll();

        $span = $request->getSpan();
        $this->assertNotNull($span);
        $this->assertFalse($span->hasEnded());

        $request->endSpan();
        OtlpSender::flushAll();

        $paths = array_column($this->waitForRequests(2), 'path');
        sort($paths);
        $this->assertSame(['/v1/logs', '/v1/traces'], $paths);
    }

    public function testSpanEndsWhenItsLastReferenceGoesAway(): void
    {
        $sender = new OtlpSender($this->otlpHost());
        $log = new Logger('svc', 'debug', 'text', "\t", $sender);

        // Nothing may hold the Span here — a reference kept for assertions is
        // itself enough to stop the destructor this test is about.
        $child = $log->createChildSpan('scoped');
        unset($child);

        OtlpSender::flushAll();
        $requests = $this->waitForRequests(1);
        $this->assertSame('/v1/traces', $requests[0]['path']);
    }
}
