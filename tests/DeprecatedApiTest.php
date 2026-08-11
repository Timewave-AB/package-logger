<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Classes\Logger;

class DeprecatedApiTest extends TestCase
{
    use LoggerSubprocessTrait;

    protected function setUp(): void
    {
        // Logger warns once per method per process, so which test sees the notice
        // depends on ordering; LoggerTest pins the warning itself in a subprocess.
        set_error_handler(static function (): bool {
            return true;
        }, E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testCreateSpanLoggerAttachesASpanCarryingItsContextAsAttributes(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $child = $log->createSpanLogger('op', ['k' => 'v']);

        $span = $child->getSpan();
        $this->assertNotNull($span);
        $this->assertSame('op', $span->name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $span->id);
        $this->assertSame(['k' => 'v'], $span->context);

        $this->assertSame(
            ['env' => 'prod'],
            $this->standingContext($child),
            'the old $context argument must stay span attributes, not log context'
        );
    }

    public function testCreateSpanLoggerInheritsStandingContext(): void
    {
        $log = new Logger('svc', 'debug', 'text', "\t", null, null, ['env' => 'prod']);
        $log->addContext(['tenant' => 'acme']);

        $this->assertSame(
            ['env' => 'prod', 'tenant' => 'acme'],
            $this->standingContext($log->createSpanLogger('op'))
        );
    }

    public function testNestedCreateSpanLoggerLinksToParentAndSharesTraceId(): void
    {
        $log = new Logger('svc');
        $outer = $log->createSpanLogger('outer');
        $inner = $outer->createSpanLogger('inner');

        $outerSpan = $outer->getSpan();
        $innerSpan = $inner->getSpan();
        $this->assertNotNull($outerSpan);
        $this->assertNotNull($innerSpan);
        $this->assertSame($outerSpan->id, $innerSpan->parentId);
        $this->assertSame($outerSpan->traceId, $innerSpan->traceId);
    }

    public function testCreateSpanLoggerInheritsLevelAndFormat(): void
    {
        $output = $this->runLoggerScript("
            set_error_handler(function () { return true; }, E_USER_DEPRECATED);
            \$log = new Timewave\\Logger\\Classes\\Logger('svc', 'warning', 'json');
            \$child = \$log->createSpanLogger('op');
            \$child->info('filtered');
            \$child->warning('kept');
        ");

        $lines = $this->nonEmptyLines($output);
        $this->assertCount(1, $lines, "expected the info line to be filtered, got: {$output}");

        $decoded = json_decode($lines[0], true);
        $this->assertIsArray($decoded, "child did not emit JSON; got: {$lines[0]}");
        $this->assertSame('WARNING', $decoded['level']);
    }

    public function testCreateSpanLoggerFromTraceparentAdoptsTraceIdAndParentSpanId(): void
    {
        $traceId = bin2hex(random_bytes(16));
        $parentSpanId = bin2hex(random_bytes(8));

        $log = new Logger('svc');
        $span = $log
            ->createSpanLoggerFromTraceparent('request', "00-{$traceId}-{$parentSpanId}-01", ['k' => 'v'])
            ->getSpan();

        $this->assertNotNull($span);
        $this->assertSame($traceId, $span->traceId);
        $this->assertSame($parentSpanId, $span->parentId);
        $this->assertSame(['k' => 'v'], $span->context);
    }

    public function testCreateSpanLoggerFromTraceparentFallsBackOnAnUnusableHeader(): void
    {
        $headers = [
            null,
            'garbage',
            '00-' . str_repeat('0', 32) . '-' . bin2hex(random_bytes(8)) . '-01',
            'ff-' . bin2hex(random_bytes(16)) . '-' . bin2hex(random_bytes(8)) . '-01',
        ];

        $log = new Logger('svc');
        foreach ($headers as $header) {
            $label = var_export($header, true);
            $span = $log->createSpanLoggerFromTraceparent('request', $header)->getSpan();

            $this->assertNotNull($span, $label);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $span->traceId, $label);
            $this->assertNull($span->parentId, $label);
        }
    }

    /** @return array<string, mixed> resolved context without the span ids log() injects */
    private function standingContext(Logger $log): array
    {
        $context = $log->getContext();
        unset($context['traceId'], $context['spanId']);

        return $context;
    }
}
