<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Enums\LogLevel;

class LogLevelTest extends TestCase
{
    public function testCaseValuesDefineSeverityOrdering(): void
    {
        // The log() method filters by `$level->value > $this->logLevel->value`,
        // so DEBUG (most verbose) must have the highest int and ERROR the lowest.
        $this->assertSame(4, LogLevel::DEBUG->value);
        $this->assertSame(3, LogLevel::VERBOSE->value);
        $this->assertSame(2, LogLevel::INFO->value);
        $this->assertSame(1, LogLevel::WARNING->value);
        $this->assertSame(0, LogLevel::ERROR->value);
    }

    public function testCaseNamesAreUppercase(): void
    {
        // Output format uses $level->name verbatim, so any rename would change logs.
        $this->assertSame('DEBUG', LogLevel::DEBUG->name);
        $this->assertSame('VERBOSE', LogLevel::VERBOSE->name);
        $this->assertSame('INFO', LogLevel::INFO->name);
        $this->assertSame('WARNING', LogLevel::WARNING->name);
        $this->assertSame('ERROR', LogLevel::ERROR->name);
    }
}
