<?php

namespace Timewave\Logger\Tests;

use Timewave\Logger\Classes\CustomLogger;
use Timewave\Logger\Enums\LogFormat;
use Timewave\Logger\Enums\LogLevel;

class CustomLoggerTest extends LoggerSubprocessTestCase
{
    public function testInfoEmitsTextLineWithLevelAndMessage(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc', 'debug', 'json');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc', 'warning');
            \$log->info('should be filtered');
            \$log->debug('also filtered');
        ");

        $this->assertSame('', trim($output));
    }

    public function testLogLevelAtOrAboveThresholdIsEmitted(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc', 'warning');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc', 'not-a-level');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
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
        // Pin existing key→width mapping. log() assigns span->id (16 hex chars,
        // from bin2hex(random_bytes(8))) into the 'traceId' key, and
        // span->traceId (32 hex chars, from bin2hex(random_bytes(16))) into the
        // 'spanId' key. This cross-assignment is the current behavior; pinning
        // it here forces any future change to be deliberate.
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $context['traceId']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $context['spanId']);
        $this->assertNotSame($context['traceId'], $context['spanId']);
    }

    public function testCreateSpanLoggerInheritsJsonFormat(): void
    {
        // Regression: createSpanLogger previously passed $this->logFormat->name
        // ('JSON') to the new CustomLogger, but the constructor's
        // LogFormat::tryFrom is case-sensitive on lowercase backing values, so
        // the child silently fell back to TEXT. Now it passes ->value ('json')
        // and the format is preserved.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc', 'debug', 'json');
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
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
            \$log->log(Timewave\\Logger\\Enums\\LogLevel::error(), 'boom');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('ERROR', $lines[0]);
        $this->assertStringContainsString('boom', $lines[0]);
    }

    public function testOtlpSeverityMappingThrowsOnUnknownLevel(): void
    {
        // Defensive: LogLevel exposes only 5 cases, so toOtlpJSON's switch
        // default is unreachable through the normal API. The throw exists so
        // that if a future case is added without updating the mapping, callers
        // fail loudly instead of silently shipping severityNumber=0
        // ("UNSPECIFIED" in OTLP) to the collector. Reaching it from a test
        // requires forging an invalid LogLevel via reflection.
        $logger = new CustomLogger('svc');

        $rc = new \ReflectionClass(LogLevel::class);
        $bogus = $rc->newInstanceWithoutConstructor();
        foreach (['name' => 'BOGUS', 'value' => 999] as $prop => $val) {
            $rp = $rc->getProperty($prop);
            $rp->setAccessible(true);
            $rp->setValue($bogus, $val);
        }

        $toOtlp = (new \ReflectionClass(CustomLogger::class))->getMethod('toOtlpJSON');
        $toOtlp->setAccessible(true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/BOGUS.*999/');

        $toOtlp->invoke($logger, 0, $bogus, 'msg');
    }

    /** @return string[] */
    private function nonEmptyLines(string $output): array
    {
        return array_values(array_filter(
            preg_split('/\R/', $output) ?: [],
            static fn(string $l): bool => $l !== ''
        ));
    }
}
