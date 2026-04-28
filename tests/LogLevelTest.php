<?php

namespace Timewave\Logger\Tests;

use PHPUnit\Framework\TestCase;
use Timewave\Logger\Enums\LogLevel;

class LogLevelTest extends TestCase
{
    public function testFactoryMethodsExposeSeverityValues(): void
    {
        // The log() method filters by `$level->value > $this->logLevel->value`,
        // so DEBUG (most verbose) must have the highest int and ERROR the lowest.
        $this->assertSame(4, LogLevel::debug()->value);
        $this->assertSame(3, LogLevel::verbose()->value);
        $this->assertSame(2, LogLevel::info()->value);
        $this->assertSame(1, LogLevel::warning()->value);
        $this->assertSame(0, LogLevel::error()->value);
    }

    public function testFactoryMethodsExposeUppercaseNames(): void
    {
        // Output format uses $level->name verbatim, so any rename would change logs.
        $this->assertSame('DEBUG', LogLevel::debug()->name);
        $this->assertSame('VERBOSE', LogLevel::verbose()->name);
        $this->assertSame('INFO', LogLevel::info()->name);
        $this->assertSame('WARNING', LogLevel::warning()->name);
        $this->assertSame('ERROR', LogLevel::error()->name);
    }

    public function testIntegerConstantsMatchInstanceValues(): void
    {
        // The class also exposes raw int constants (used by toOtlpJSON's switch).
        $this->assertSame(LogLevel::DEBUG, LogLevel::debug()->value);
        $this->assertSame(LogLevel::VERBOSE, LogLevel::verbose()->value);
        $this->assertSame(LogLevel::INFO, LogLevel::info()->value);
        $this->assertSame(LogLevel::WARNING, LogLevel::warning()->value);
        $this->assertSame(LogLevel::ERROR, LogLevel::error()->value);
    }
}
