<?php

namespace Timewave\Logger\Enums;

final class LogLevel
{
    public const DEBUG = 4;
    public const VERBOSE = 3;
    public const INFO = 2;
    public const WARNING = 1;
    public const ERROR = 0;

    public string $name;

    public int $value;

    private function __construct(string $name, int $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function debug(): self
    {
        return new self('DEBUG', self::DEBUG);
    }

    public static function verbose(): self
    {
        return new self('VERBOSE', self::VERBOSE);
    }

    public static function info(): self
    {
        return new self('INFO', self::INFO);
    }

    public static function warning(): self
    {
        return new self('WARNING', self::WARNING);
    }

    public static function error(): self
    {
        return new self('ERROR', self::ERROR);
    }
}
