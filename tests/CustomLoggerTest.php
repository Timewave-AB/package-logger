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
        // The span logger must keep the same service name + format, and inject
        // traceId/spanId into the context column of subsequent text logs.
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
            \$child = \$log->createSpanLogger('op', ['k' => 'v']);
            \$child->info('inside span');
        ");

        $line = trim($output);
        $parts = explode("\t", $line);
        $this->assertSame('INFO', $parts[0]);
        $this->assertSame('inside span', $parts[2]);
        // Context contains the original key plus traceId and spanId added by log().
        $this->assertStringContainsString('traceId=', $parts[3]);
        $this->assertStringContainsString('spanId=', $parts[3]);
    }

    public function testLogMethodAcceptsLogLevelEnumDirectly(): void
    {
        $output = $this->runLoggerScript("
            \$log = new Timewave\\Logger\\Classes\\CustomLogger('svc');
            \$log->log(Timewave\\Logger\\Enums\\LogLevel::ERROR, 'boom');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines);
        $this->assertStringStartsWith('ERROR', $lines[0]);
        $this->assertStringContainsString('boom', $lines[0]);
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
