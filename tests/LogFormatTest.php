<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Enums\LogFormat;

class LogFormatTest extends TestCase
{
    public function testTryFromTextReturnsTextCase(): void
    {
        $format = LogFormat::tryFrom('text');

        $this->assertNotNull($format);
        $this->assertSame('text', $format->value);
        $this->assertSame('TEXT', $format->name);
    }

    public function testTryFromJsonReturnsJsonCase(): void
    {
        $format = LogFormat::tryFrom('json');

        $this->assertNotNull($format);
        $this->assertSame('json', $format->value);
        $this->assertSame('JSON', $format->name);
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        $this->assertNull(LogFormat::tryFrom('xml'));
        $this->assertNull(LogFormat::tryFrom(''));
        $this->assertNull(LogFormat::tryFrom('TEXT'));
    }

    public function testFactoriesAndTryFromReturnSingletons(): void
    {
        // Native enums guarantee identity; the polyfill must too. tryFrom
        // routes through the factories so it inherits singleton behavior.
        $this->assertSame(LogFormat::text(), LogFormat::text());
        $this->assertSame(LogFormat::json(), LogFormat::json());
        $this->assertSame(LogFormat::text(), LogFormat::tryFrom('text'));
        $this->assertSame(LogFormat::json(), LogFormat::tryFrom('json'));
        $this->assertNotSame(LogFormat::text(), LogFormat::json());
    }

    public function testInstancesRejectMutation(): void
    {
        // Singletons must not be mutable, otherwise a write through one
        // reference would corrupt every cached caller.
        $format = LogFormat::text();

        $this->expectException(\LogicException::class);
        $format->value = 'mutated';
    }
}
