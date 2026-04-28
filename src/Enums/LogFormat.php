<?php

namespace Timewave\Logger\Enums;

final class LogFormat
{
    public const TEXT = 'text';
    public const JSON = 'json';

    public string $name;

    public string $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    public static function text(): self
    {
        return new self('TEXT', self::TEXT);
    }

    public static function json(): self
    {
        return new self('JSON', self::JSON);
    }

    public static function tryFrom(string $value): ?self
    {
        switch ($value) {
            case self::TEXT:
                return self::text();
            case self::JSON:
                return self::json();
            default:
                return null;
        }
    }
}
