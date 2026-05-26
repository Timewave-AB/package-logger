<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\Logger;
use Timewave\Logger\Classes\Span;

class LoggerTest extends LoggerSubprocessTestCase
{
    public function testInfoEmitsTextLineWithLevelAndMessage(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc');
            \$log->info('hello world');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines, "expected exactly one log line, got: {$output}");

        $parts = explode("\t", $lines[0]);
        $this->assertSame('INFO', $parts[0]);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $parts[1]);
        $this->assertSame('hello world', $parts[2]);
    }

    public function testJsonFormatProducesParseableJsonPerLine(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$log->warning('uh oh', ['userId' => 42]);
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertIsArray($decoded, "line was not valid JSON: {$lines[0]}");
        $this->assertSame('WARNING', $decoded['level']);
        $this->assertSame('uh oh', $decoded['message']);
        $this->assertSame(['userId' => 42], $decoded['context']);
    }

    public function testTextFormatSerializesContextViaHttpBuildQuery(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc');
            \$log->info('msg', ['a' => 1, 'b' => 'two']);
        ");

        $line = trim($output);
        // Context column is the 4th tab-separated field; http_build_query with
        // ' ' as separator joins pairs with a space.
        $parts = explode("\t", $line);
        $this->assertSame('a=1 b=two', $parts[3]);
    }

    public function testLogLevelBelowThresholdProducesNoOutput(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'warning');
            \$log->info('should be filtered');
            \$log->debug('also filtered');
        ");

        $this->assertSame('', trim($output));
    }

    public function testLogLevelAtOrAboveThresholdIsEmitted(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'warning');
            \$log->warning('w');
            \$log->error('e');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(2, $lines);
        $this->assertStringStartsWith('WARNING', $lines[0]);
        $this->assertStringStartsWith('ERROR', $lines[1]);
    }

    public function testUnknownLogLevelStringDefaultsToDebug(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'not-a-level');
            \$log->debug('still emitted');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('DEBUG', $lines[0]);
    }

    public function testCreateSpanLoggerInheritsConfigAndAttachesSpan(): void
    {
        // The ['k' => 'v'] context passed to createSpanLogger is attached to
        // the Span itself (and emitted to OTLP). It is NOT forwarded to log
        // lines from the child logger — log() only injects traceId/spanId
        // into the context column when a span is present.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc');
            \$child = \$log->createSpanLogger('op', ['k' => 'v']);
            \$child->info('inside span');
        ");

        $line = trim($output);
        $parts = explode("\t", $line);
        $this->assertSame('INFO', $parts[0]);
        $this->assertSame('inside span', $parts[2]);

        // Context column is built via http_build_query with ' ' as separator.
        parse_str(strtr($parts[3], ' ', '&'), $context);

        $this->assertArrayHasKey('traceId', $context);
        $this->assertArrayHasKey('spanId', $context);
        // Pin the corrected key→width mapping. The 'traceId' key carries the
        // 32-hex trace id (bin2hex(random_bytes(16))) and the 'spanId' key
        // carries the 16-hex span id (bin2hex(random_bytes(8))), matching the
        // OTLP payload assembled in OtlpLogRecord so text logs and OTLP traces
        // can be correlated by id.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context['spanId']);
        $this->assertNotSame($context['traceId'], $context['spanId']);
    }

    public function testCreateSpanLoggerInheritsJsonFormat(): void
    {
        // Regression: createSpanLogger previously passed $this->logFormat->name
        // ('JSON') to the new Logger, but the constructor's LogFormat::tryFrom
        // is case-sensitive on lowercase backing values, so the child silently
        // fell back to TEXT. Now it passes ->value ('json') and the format is
        // preserved.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$child = \$log->createSpanLogger('op');
            \$child->info('inside span');
        ");

        $line = trim($output);
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "child did not emit JSON; got: {$line}");
        $this->assertSame('INFO', $decoded['level']);
        $this->assertSame('inside span', $decoded['message']);
    }

    public function testLogMethodAcceptsLogLevelInstanceDirectly(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc');
            \$log->log(Timewave\\Logger\\Enums\\LogLevel::error(), 'boom');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('ERROR', $lines[0]);
        $this->assertStringContainsString('boom', $lines[0]);
    }

    public function testRootLoggerHasNoSpan(): void
    {
        $log = new Logger('svc');
        $this->assertNull($log->getSpan());
    }

    public function testCreateSpanLoggerExposesUnderlyingSpan(): void
    {
        // The convenience+composition story: createSpanLogger returns a logger
        // wired to a span so calls just work, AND that same span is reachable
        // via getSpan() so callers can read traceId/spanId/attributes without
        // tracking it separately.
        $log = new Logger('svc');
        $child = $log->createSpanLogger('op', ['k' => 'v']);

        $span = $child->getSpan();
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('op', $span->name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->id);
    }

    public function testNestedSpanLoggerLinksToParentSpanId(): void
    {
        $log = new Logger('svc');
        $outer = $log->createSpanLogger('outer');
        $inner = $outer->createSpanLogger('inner');

        $outerSpan = $outer->getSpan();
        $innerSpan = $inner->getSpan();

        $this->assertNotNull($outerSpan);
        $this->assertNotNull($innerSpan);
        $this->assertSame($outerSpan->id, $innerSpan->parentId);
    }
}
