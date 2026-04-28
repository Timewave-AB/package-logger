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
}
