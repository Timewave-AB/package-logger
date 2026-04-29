<?php

namespace Timewave\Logger\Enums;

/**
 * @property-read string $name
 * @property-read string $value
 */
final class LogFormat
{
    public const TEXT = 'text';
    public const JSON = 'json';

    // Cached singletons so factories (and tryFrom, which routes through them)
    // return the same instance on every call, mirroring native enum identity.
    private static ?self $text = null;
    private static ?self $json = null;

    private string $name;

    private string $value;

    private function __construct(string $name, string $value)
    {
        $this->name = $name;
        $this->value = $value;
    }

    /**
     * Native enums expose `->name` and `->value` as readonly properties.
     * `readonly` isn't available in PHP 7.4, so we keep the same syntax via
     * __get + a throwing __set to make instances effectively immutable.
     */
    public function __get(string $key): string
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

    public static function text(): self
    {
        return self::$text ??= new self('TEXT', self::TEXT);
    }

    public static function json(): self
    {
        return self::$json ??= new self('JSON', self::JSON);
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
