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

    public function testCreateChildSpanInheritsConfigAndAttachesSpan(): void
    {
        // Third argument is span attributes: it reaches the Span (and OTLP
        // traces) but never the log lines. Log context is the second argument.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc');
            \$child = \$log->createChildSpan('op', null, ['k' => 'v']);
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

    public function testCreateChildSpanInheritsJsonFormat(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$child = \$log->createChildSpan('op');
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

    public function testCreateChildSpanExposesUnderlyingSpan(): void
    {
        // The convenience+composition story: createChildSpan returns a logger
        // wired to a span so calls just work, AND that same span is reachable
        // via getSpan() so callers can read traceId/spanId/attributes without
        // tracking it separately.
        $log = new Logger('svc');
        $child = $log->createChildSpan('op', null, ['k' => 'v']);

        $span = $child->getSpan();
        $this->assertInstanceOf(Span::class, $span);
        $this->assertSame('op', $span->name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->id);
    }

    public function testNestedChildSpanLinksToParentSpanId(): void
    {
        $log = new Logger('svc');
        $outer = $log->createChildSpan('outer');
        $inner = $outer->createChildSpan('inner');

        $outerSpan = $outer->getSpan();
        $innerSpan = $inner->getSpan();

        $this->assertNotNull($outerSpan);
        $this->assertNotNull($innerSpan);
        $this->assertSame($outerSpan->id, $innerSpan->parentId);
    }

    public function testNestedChildSpanInheritsParentTraceId(): void
    {
        $log = new Logger('svc');
        $outer = $log->createChildSpan('outer');
        $inner = $outer->createChildSpan('inner');

        $outerSpan = $outer->getSpan();
        $innerSpan = $inner->getSpan();
        $this->assertNotNull($outerSpan);
        $this->assertNotNull($innerSpan);
        $this->assertSame($outerSpan->traceId, $innerSpan->traceId);
    }

    public function testCreateRootSpanFromTraceparentAdoptsTraceIdAndParentSpanId(): void
    {
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $log = new Logger('svc');
        $root = $log->createRootSpanFromTraceparent('request', "00-{$traceId}-{$parentSpanId}-01");

        $span = $root->getSpan();
        $this->assertNotNull($span);
        $this->assertSame($traceId, $span->traceId);
        $this->assertSame($parentSpanId, $span->parentId);
    }

    public function testTraceparentRootedChildSpanSharesIncomingTraceId(): void
    {
        $traceId = bin2hex(random_bytes(16));

        $log = new Logger('svc');
        $root = $log->createRootSpanFromTraceparent('request', "00-{$traceId}-" . bin2hex(random_bytes(8)) . '-01');
        $child = $root->createChildSpan('login');

        $rootSpan = $root->getSpan();
        $childSpan = $child->getSpan();
        $this->assertNotNull($rootSpan);
        $this->assertNotNull($childSpan);
        $this->assertSame($traceId, $childSpan->traceId);
        $this->assertSame($rootSpan->id, $childSpan->parentId);
    }

    public function testCreateRootSpanFromTraceparentFallsBackOnMissingHeader(): void
    {
        $log = new Logger('svc');
        $root = $log->createRootSpanFromTraceparent('request', null);

        $span = $root->getSpan();
        $this->assertNotNull($span);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
        $this->assertNull($span->parentId);
    }

    public function testCreateRootSpanFromTraceparentFallsBackOnInvalidHeader(): void
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
            $span = $log->createRootSpanFromTraceparent('request', $bad)->getSpan();
            $this->assertNotNull($span, "header: {$bad}");
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId, "header: {$bad}");
            $this->assertNull($span->parentId, "header: {$bad}");
        }
    }

    public function testCreateChildSpanInheritsAndMergesParentContext(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod', 'tenant' => 'acme']);
        $child = $log->createChildSpan('op', ['tenant' => 'globex', 'job' => 'sync']);

        $this->assertSame(
            ['env' => 'prod', 'tenant' => 'globex', 'job' => 'sync'],
            $this->standingContext($child)
        );
    }

    public function testCreateChildSpanSnapshotsContextAtCreation(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $child = $log->createChildSpan('op', ['tenant' => 'acme']);

        $log->addContext(['region' => 'eu']);
        $child->addContext(['job' => 'sync']);

        $this->assertSame(['env' => 'prod', 'region' => 'eu'], $log->getContext());
        $this->assertSame(
            ['env' => 'prod', 'tenant' => 'acme', 'job' => 'sync'],
            $this->standingContext($child)
        );
    }

    public function testGrandchildSpanInheritsThroughItsParent(): void
    {
        $log = new Logger('svc');
        $grandchild = $log->createChildSpan('outer', ['a' => 1])->createChildSpan('inner');

        $this->assertSame(['a' => 1], $this->standingContext($grandchild));
    }

    public function testRemoveContextAffectsOnlyTheLoggerItIsCalledOn(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod', 'pii' => 'secret']);
        $child = $log->createChildSpan('op');

        $child->removeContext('pii');

        $this->assertSame(['env' => 'prod'], $this->standingContext($child));
        $this->assertSame(['env' => 'prod', 'pii' => 'secret'], $log->getContext());
    }

    public function testAddContextReplacesArrayValuesWholesale(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['user' => ['id' => 1, 'name' => 'siv']]);
        $log->addContext(['user' => ['id' => 2]]);

        $this->assertSame(['user' => ['id' => 2]], $log->getContext());
    }

    public function testCreateChildSpanSeparatesLogContextFromSpanAttributes(): void
    {
        $log = new Logger('svc');
        $child = $log->createChildSpan('op', ['tenant' => 'acme'], ['http.method' => 'POST']);

        $span = $child->getSpan();
        $this->assertNotNull($span);
        $this->assertSame(['http.method' => 'POST'], $span->context);
        $this->assertSame(['tenant' => 'acme'], $this->standingContext($child));
    }

    public function testGetContextIncludesActiveSpanIds(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $child = $log->createChildSpan('op');

        $span = $child->getSpan();
        $this->assertNotNull($span);
        $this->assertSame(
            ['env' => 'prod', 'traceId' => $span->traceId, 'spanId' => $span->id],
            $child->getContext()
        );
    }

    public function testWithChildSpanEndsTheSpanAndReturnsTheClosureValue(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);

        $inner = null;
        $result = $log->withChildSpan('op', ['tenant' => 'acme'], function (Logger $child) use (&$inner) {
            $inner = $child;
            return 'returned';
        });

        $this->assertSame('returned', $result);
        $this->assertNotNull($inner);

        $span = $inner->getSpan();
        $this->assertNotNull($span);
        $this->assertSame(['env' => 'prod', 'tenant' => 'acme'], $this->standingContext($inner));
        $this->assertTrue($span->hasEnded());
    }

    public function testWithChildSpanEndsTheSpanWhenTheClosureThrows(): void
    {
        $log = new Logger('svc');

        $inner = null;
        try {
            $log->withChildSpan('op', [], function (Logger $child) use (&$inner) {
                $inner = $child;
                throw new \RuntimeException('boom');
            });
            $this->fail('withChildSpan swallowed the exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        $this->assertNotNull($inner);

        $span = $inner->getSpan();
        $this->assertNotNull($span);
        $this->assertTrue($span->hasEnded());
    }

    public function testLoggerInterfaceDeclaresContextAndSpanMethods(): void
    {
        $methods = [
            'addContext',
            'createChildSpan',
            'createRootSpanFromTraceparent',
            'createSpanLogger',
            'createSpanLoggerFromTraceparent',
            'getContext',
            'removeContext',
            'withChildSpan',
        ];

        foreach ($methods as $method) {
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

    public function testCreateChildSpanInheritsLevelAndFormat(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'warning', 'json');
            \$child = \$log->createChildSpan('op', ['tenant' => 'acme']);
            \$child->info('filtered');
            \$child->warning('kept');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);

        $decoded = json_decode($lines[0], true);
        $this->assertSame('WARNING', $decoded['level']);

        $context = $this->jsonContext($lines[0]);
        unset($context['traceId'], $context['spanId']);
        $this->assertSame(['tenant' => 'acme'], $context);
    }

    public function testActiveSpanIdsOverrideContextKeysOfTheSameName(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json', \"\\t\", null, null, ['traceId' => 'mine']);
            \$log->createChildSpan('op')->info('in span', ['spanId' => 'also mine']);
        ");

        $context = $this->jsonContext(trim($output));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context['spanId']);
    }

    public function testNumericContextKeysSurviveAndPerCallStillWins(): void
    {
        // PHP casts numeric-string keys to int, and array_merge renumbers those
        // from 0 instead of overwriting — silently corrupting a caller's keys.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json', \"\\t\", null, null, ['7' => 'standing']);
            \$log->info('numeric keys', ['404' => 'not found', '7' => 'per-call']);
        ");

        $this->assertSame([7 => 'per-call', 404 => 'not found'], $this->jsonContext(trim($output)));
    }

    public function testNumericContextKeysSurviveWithoutStandingContext(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$log->info('numeric key', ['404' => 'not found']);
        ");

        $this->assertSame([404 => 'not found'], $this->jsonContext(trim($output)));
    }

    public function testStandingContextReachesTheTextFormattedLine(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'text', \"\\t\", null, null, ['env' => 'prod']);
            \$log->info('msg', ['x' => 1]);
        ");

        $parts = explode("\t", trim($output));
        $this->assertSame('env=prod x=1', $parts[3]);
    }

    public function testDeprecatedCreateSpanLoggerDelegatesAndWarnsOnce(): void
    {
        // A subprocess gives a fresh process, so the once-per-process guard is
        // observable. The error handler makes the assertion independent of the
        // ini defaults, which differ between 7.4 and 8.x.
        $output = $this->runLoggerScript("
            set_error_handler(function (\$errno, \$errstr) {
                if (\$errno === E_USER_DEPRECATED) { echo 'DEPRECATED: ' . \$errstr . \"\\n\"; }
                return true;
            });
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$first = \$log->createSpanLogger('op', ['k' => 'v']);
            \$log->createSpanLogger('op2');
            \$log->createSpanLoggerFromTraceparent('req');
            echo json_encode(\$first->getSpan()->context) . \"\\n\";
        ");

        $lines = $this->nonEmptyLines($output);
        $deprecations = array_values(array_filter($lines, static function (string $line): bool {
            return strpos($line, 'DEPRECATED: ') === 0;
        }));

        // Once per method, not once per process: two calls to one shim warn
        // once, but the other shim still gets its own warning.
        $this->assertCount(2, $deprecations, "expected one warning per method, got: {$output}");
        $this->assertStringContainsString('createSpanLogger()', $deprecations[0]);
        $this->assertStringContainsString('createChildSpan', $deprecations[0]);
        $this->assertStringContainsString('createSpanLoggerFromTraceparent()', $deprecations[1]);
        // The old second argument meant span attributes; it must not silently
        // become log context on the way through.
        $this->assertContains('{"k":"v"}', $lines);
    }

    public function testDeprecatedCreateSpanLoggerFromTraceparentDelegates(): void
    {
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $output = $this->runLoggerScript("
            set_error_handler(function (\$errno, \$errstr) {
                if (\$errno === E_USER_DEPRECATED) { echo 'DEPRECATED: ' . \$errstr . \"\\n\"; }
                return true;
            });
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'debug', 'json');
            \$root = \$log->createSpanLoggerFromTraceparent('request', '00-{$traceId}-{$parentSpanId}-01', ['k' => 'v']);
            \$span = \$root->getSpan();
            echo \$span->traceId . ' ' . \$span->parentId . ' ' . json_encode(\$span->context) . \"\\n\";
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertContains("{$traceId} {$parentSpanId} {\"k\":\"v\"}", $lines);
        $this->assertNotEmpty(array_filter($lines, static function (string $line): bool {
            return strpos($line, 'DEPRECATED: ') === 0;
        }));
    }

    /** @return array<string, mixed> resolved context without the span ids log() injects */
    private function standingContext(Logger $log): array
    {
        $context = $log->getContext();
        unset($context['traceId'], $context['spanId']);

        return $context;
    }

    /** @return array<string, mixed> the context object of a json-format log line */
    private function jsonContext(string $line): array
    {
        $decoded = json_decode($line, true);
        $this->assertIsArray($decoded, "line was not valid JSON: {$line}");

        return $decoded['context'] ?? [];
    }
}
