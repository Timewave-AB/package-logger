<?php

namespace Timewave\Logger\Enums;

/**
 * @property-read string $name
 * @property-read int $value
 */
final class LogLevel
{
    public const DEBUG = 4;
    public const VERBOSE = 3;
    public const INFO = 2;
    public const WARNING = 1;
    public const ERROR = 0;

    // Cached singletons so factories return the same instance on every call,
    // mirroring native enum identity (LogLevel::ERROR === LogLevel::ERROR).
    private static ?self $debug = null;
    private static ?self $verbose = null;
    private static ?self $info = null;
    private static ?self $warning = null;
    private static ?self $error = null;

    private string $name;

    private int $value;

    private function __construct(string $name, int $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    /**
     * Native enums expose `->name` and `->value` as readonly properties.
     * `readonly` isn't available in PHP 7.4, so we keep the same syntax via
     * __get + a throwing __set to make instances effectively immutable.
     *
     * @return string|int
     */
    public function __get(string $key)
    {
        switch ($key) {
            case 'name':
                return $this->name;
            case 'value':
                return $this->value;
        }
        throw new \OutOfBoundsException(sprintf('Undefined property: %s::$%s', self::class, $key));
    }

    public function __isset(string $key): bool
    {
        return $key === 'name' || $key === 'value';
    }

    /** @param mixed $value */
    public function __set(string $key, $value): void
    {
        throw new \LogicException(sprintf('%s instances are immutable; cannot set $%s', self::class, $key));
    }

    public static function debug(): self
    {
        return self::$debug ??= new self('DEBUG', self::DEBUG);
    }

    public static function verbose(): self
    {
        return self::$verbose ??= new self('VERBOSE', self::VERBOSE);
    }

    public static function info(): self
    {
        return self::$info ??= new self('INFO', self::INFO);
    }

    public static function warning(): self
    {
        return self::$warning ??= new self('WARNING', self::WARNING);
    }

    public static function error(): self
    {
        return self::$error ??= new self('ERROR', self::ERROR);
    }

    public static function tryFrom(int $value): ?self
    {
        switch ($value) {
            case self::DEBUG:
                return self::debug();
            case self::VERBOSE:
                return self::verbose();
            case self::INFO:
                return self::info();
            case self::WARNING:
                return self::warning();
            case self::ERROR:
                return self::error();
            default:
                return null;
        }
    }
}
