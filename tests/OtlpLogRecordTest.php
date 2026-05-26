<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Classes\OtlpLogRecord;
use Timewave\Logger\Classes\Span;
use Timewave\Logger\Enums\LogLevel;

class OtlpLogRecordTest extends TestCase
{
    public function testBuildEmbedsSeverityNumberAndText(): void
    {
        $payload = OtlpLogRecord::build('svc', 1700000000000, LogLevel::warning(), 'hi');

        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertSame(13, $record['severityNumber']);
        $this->assertSame('WARNING', $record['severityText']);
        $this->assertSame('hi', $record['body']['stringValue']);
    }

    public function testBuildAttachesTraceAndSpanIdsWhenSpanProvided(): void
    {
        $span = new Span('op');
        $payload = OtlpLogRecord::build('svc', 1700000000000, LogLevel::info(), 'hi', null, null, $span);

        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertSame($span->traceId, $record['traceId']);
        $this->assertSame($span->id, $record['spanId']);
    }

    public function testBuildOmitsTraceAndSpanIdsWhenNoSpan(): void
    {
        $payload = OtlpLogRecord::build('svc', 1700000000000, LogLevel::info(), 'hi');

        $record = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0];
        $this->assertArrayNotHasKey('traceId', $record);
        $this->assertArrayNotHasKey('spanId', $record);
    }

    public function testBuildSerializesContextAndException(): void
    {
        $payload = OtlpLogRecord::build(
            'svc',
            1700000000000,
            LogLevel::error(),
            'boom',
            ['userId' => 42],
            new \RuntimeException('blew up')
        );

        $attrs = $payload['resourceLogs'][0]['scopeLogs'][0]['logRecords'][0]['attributes'];
        $this->assertSame('userId', $attrs[0]['key']);
        $this->assertSame('42', $attrs[0]['value']['stringValue']);
        $this->assertSame('exception', $attrs[1]['key']);
        $this->assertSame('blew up', $attrs[1]['value']['stringValue']);
    }

    public function testBuildThrowsOnUnknownSeverity(): void
    {
        // Defensive: LogLevel exposes only 5 cases, so the switch default is
        // unreachable through the normal API. The throw exists so that if a
        // future case is added without updating the mapping, callers fail
        // loudly instead of silently shipping severityNumber=0 ("UNSPECIFIED"
        // in OTLP). Reaching it requires forging an invalid LogLevel.
        $rc = new \ReflectionClass(LogLevel::class);
        $bogus = $rc->newInstanceWithoutConstructor();
        foreach (['name' => 'BOGUS', 'value' => 999] as $prop => $val) {
            $rp = $rc->getProperty($prop);
            if (\PHP_VERSION_ID < 80100) {
                $rp->setAccessible(true);
            }
            $rp->setValue($bogus, $val);
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/BOGUS.*999/');

        OtlpLogRecord::build('svc', 0, $bogus, 'msg');
    }
}
