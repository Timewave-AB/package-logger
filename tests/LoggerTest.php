<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\Logger;
use Timewave\Logger\Classes\Span;
use Timewave\Logger\Contracts\LoggerInterface;

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

    public function testNestedSpanLoggerInheritsParentTraceId(): void
    {
        $log = new Logger('svc');
        $outer = $log->createSpanLogger('outer');
        $inner = $outer->createSpanLogger('inner');

        $outerSpan = $outer->getSpan();
        $innerSpan = $inner->getSpan();
        $this->assertNotNull($outerSpan);
        $this->assertNotNull($innerSpan);
        $this->assertSame($outerSpan->traceId, $innerSpan->traceId);
    }

    public function testCreateSpanLoggerFromTraceparentAdoptsTraceIdAndParentSpanId(): void
    {
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $log = new Logger('svc');
        $root = $log->createSpanLoggerFromTraceparent('request', "00-{$traceId}-{$parentSpanId}-01");

        $span = $root->getSpan();
        $this->assertNotNull($span);
        $this->assertSame($traceId, $span->traceId);
        $this->assertSame($parentSpanId, $span->parentId);
    }

    public function testTraceparentRootedChildSpanSharesIncomingTraceId(): void
    {
        $traceId = bin2hex(random_bytes(16));

        $log = new Logger('svc');
        $root = $log->createSpanLoggerFromTraceparent('request', "00-{$traceId}-" . bin2hex(random_bytes(8)) . '-01');
        $child = $root->createSpanLogger('login');

        $rootSpan = $root->getSpan();
        $childSpan = $child->getSpan();
        $this->assertNotNull($rootSpan);
        $this->assertNotNull($childSpan);
        $this->assertSame($traceId, $childSpan->traceId);
        $this->assertSame($rootSpan->id, $childSpan->parentId);
    }

    public function testCreateSpanLoggerFromTraceparentFallsBackOnMissingHeader(): void
    {
        $log = new Logger('svc');
        $root = $log->createSpanLoggerFromTraceparent('request', null);

        $span = $root->getSpan();
        $this->assertNotNull($span);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
        $this->assertNull($span->parentId);
    }

    public function testLoggerInterfaceDeclaresCreateSpanLoggerFromTraceparent(): void
    {
        $this->assertTrue(method_exists(LoggerInterface::class, 'createSpanLoggerFromTraceparent'));
    }

    public function testCreateSpanLoggerFromTraceparentFallsBackOnInvalidHeader(): void
    {
        // trace/span are well-formed; only the version or field count is wrong,
        // so these probe the W3C-conformance guards rather than the hex checks.
        $trace = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        $headers = [
            'garbage',
            '00-tooshort-abc-01',
            '00-' . str_repeat('0', 32) . "-{$spanId}-01",   // all-zero trace
            "00-{$trace}-" . str_repeat('0', 16) . '-01',     // all-zero span
            "00-{$trace}-{$spanId}-01-extra",                 // version 00 must have exactly 4 fields
            "ff-{$trace}-{$spanId}-01",                       // 'ff' is a reserved/invalid version
            "zz-{$trace}-{$spanId}-01",                       // non-hex version
        ];

        $log = new Logger('svc');
        foreach ($headers as $bad) {
            $span = $log->createSpanLoggerFromTraceparent('request', $bad)->getSpan();
            $this->assertNotNull($span, "header: {$bad}");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId, "header: {$bad}");
            $this->assertNull($span->parentId, "header: {$bad}");
        }
    }

    public function testCreateChildInheritsAndMergesParentContext(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod', 'tenant' => 'acme']);
        $child = $log->createChild(['tenant' => 'globex', 'job' => 'sync']);

        $this->assertSame(['env' => 'prod', 'tenant' => 'globex', 'job' => 'sync'], $child->getContext());
    }

    public function testCreateChildSnapshotsContextAtCreation(): void
    {
        // Copy, not a live link: neither side sees the other's later edits.
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $child = $log->createChild(['tenant' => 'acme']);

        $log->addContext(['region' => 'eu']);
        $child->addContext(['job' => 'sync']);

        $this->assertSame(['env' => 'prod', 'region' => 'eu'], $log->getContext());
        $this->assertSame(['env' => 'prod', 'tenant' => 'acme', 'job' => 'sync'], $child->getContext());
    }

    public function testGrandchildInheritsThroughItsParent(): void
    {
        $log = new Logger('svc');
        $grandchild = $log->createChild(['a' => 1])->createChild();

        $this->assertSame(['a' => 1], $grandchild->getContext());
    }

    public function testRemoveContextAffectsOnlyTheLoggerItIsCalledOn(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod', 'pii' => 'secret']);
        $child = $log->createChild();

        $child->removeContext('pii');

        $this->assertSame(['env' => 'prod'], $child->getContext());
        $this->assertSame(['env' => 'prod', 'pii' => 'secret'], $log->getContext());
    }

    public function testAddContextReplacesArrayValuesWholesale(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['user' => ['id' => 1, 'name' => 'siv']]);
        $log->addContext(['user' => ['id' => 2]]);

        $this->assertSame(['user' => ['id' => 2]], $log->getContext());
    }

    public function testCreateChildKeepsParentSpanAndStandingContext(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $spanLog = $log->createSpanLogger('op');
        $child = $spanLog->createChild(['step' => 'two']);

        $span = $spanLog->getSpan();
        $this->assertNotNull($span);
        $this->assertSame($span, $child->getSpan());
        $this->assertSame(
            ['env' => 'prod', 'step' => 'two', 'traceId' => $span->traceId, 'spanId' => $span->id],
            $child->getContext()
        );
    }

    public function testLoggerInterfaceDeclaresContextMethods(): void
    {
        foreach (['addContext', 'createChild', 'getContext', 'removeContext'] as $method) {
            $this->assertTrue(method_exists(LoggerInterface::class, $method), "missing: {$method}");
        }
    }

    public function testStandingContextIsEmittedAndLosesToPerCallKeys(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json', \"\\t\", null, null, ['env' => 'prod', 'tenant' => 'acme']);
            \$log->info('one');
            \$log->info('two', ['tenant' => 'globex', 'x' => 1]);
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(2, $lines);
        $this->assertSame(['env' => 'prod', 'tenant' => 'acme'], $this->jsonContext($lines[0]));
        $this->assertSame(['env' => 'prod', 'tenant' => 'globex', 'x' => 1], $this->jsonContext($lines[1]));
    }

    public function testCreateChildInheritsLevelAndFormat(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'warning', 'json');
            \$child = \$log->createChild(['tenant' => 'acme']);
            \$child->info('filtered');
            \$child->warning('kept');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('WARNING', $decoded['level']);
        $this->assertSame(['tenant' => 'acme'], $decoded['context']);
    }

    public function testActiveSpanIdsOverrideContextKeysOfTheSameName(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json', \"\\t\", null, null, ['traceId' => 'mine']);
            \$log->createSpanLogger('op')->info('in span', ['spanId' => 'also mine']);
        ");

        $context = $this->jsonContext(trim($output));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context['spanId']);
    }

    /** @return array<string, mixed> the context object of a json-format log line */
    private function jsonContext(string $line): array
    {
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "line was not valid JSON: {$line}");

        return $decoded['context'] ?? [];
    }
}
