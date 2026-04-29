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

    public function testFactoryMethodsReturnSingletons(): void
    {
        // Native enums guarantee `LogLevel::ERROR === LogLevel::ERROR`. The PHP
        // 7.4 polyfill caches instances so callers using identity comparisons
        // (idiomatic with native enums) keep working.
        $this->assertSame(LogLevel::debug(), LogLevel::debug());
        $this->assertSame(LogLevel::verbose(), LogLevel::verbose());
        $this->assertSame(LogLevel::info(), LogLevel::info());
        $this->assertSame(LogLevel::warning(), LogLevel::warning());
        $this->assertSame(LogLevel::error(), LogLevel::error());

        // Different cases must remain distinct instances.
        $this->assertNotSame(LogLevel::debug(), LogLevel::error());
    }

    public function testInstancesRejectMutation(): void
    {
        // Native enum cases are readonly; the polyfill must prevent writes too,
        // otherwise mutating one call site would corrupt the cached singleton
        // for every other caller.
        //
        // Use a reflection-created instance instead of the cached singleton so
        // a future regression that relaxes immutability can't poison the
        // shared instance and break unrelated tests.
        $level = (new \ReflectionClass(LogLevel::class))->newInstanceWithoutConstructor();

        $this->expectException(\LogicException::class);
        $level->name = 'mutated';
    }

    public function testIssetReturnsTrueForNameAndValue(): void
    {
        $level = LogLevel::error();
        $this->assertTrue(isset($level->name));
        $this->assertTrue(isset($level->value));
        $this->assertFalse(isset($level->nope));
    }

    public function testTryFromKnownValuesReturnsSingletonInstance(): void
    {
        // Mirrors LogFormat::tryFrom + native backed-enum behavior. Routes
        // through the named factories so identity is preserved.
        $this->assertSame(LogLevel::debug(), LogLevel::tryFrom(LogLevel::DEBUG));
        $this->assertSame(LogLevel::verbose(), LogLevel::tryFrom(LogLevel::VERBOSE));
        $this->assertSame(LogLevel::info(), LogLevel::tryFrom(LogLevel::INFO));
        $this->assertSame(LogLevel::warning(), LogLevel::tryFrom(LogLevel::WARNING));
        $this->assertSame(LogLevel::error(), LogLevel::tryFrom(LogLevel::ERROR));
    }

    public function testTryFromUnknownValueReturnsNull(): void
    {
        $this->assertNull(LogLevel::tryFrom(999));
        $this->assertNull(LogLevel::tryFrom(-1));
    }
}
