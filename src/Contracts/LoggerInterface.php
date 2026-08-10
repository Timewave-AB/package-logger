<?php

namespace Timewave\Logger\Contracts;

use Timewave\Logger\Classes\Span;
use Timewave\Logger\Enums\LogLevel;

interface LoggerInterface
{
    public function addContext(array $context): void;

    public function createChild(?array $context = null): LoggerInterface;

    public function createSpanLogger(string $name, ?array $context = null): LoggerInterface;

    public function createSpanLoggerFromTraceparent(
        string $name,
        ?string $traceparent = null,
        ?array $context = null
    ): LoggerInterface;

    public function debug(string $message, ?array $context = null, ?\Throwable $exception = null): void;

    public function endSpan(): void;

    public function error(string $message, ?array $context = null, ?\Throwable $exception = null): void;

    public function getContext(): array;

    public function getSpan(): ?Span;

    public function info(string $message, ?array $context = null, ?\Throwable $exception = null): void;

    public function log(
        LogLevel $level,
        string $message,
        ?array $context,
        ?\Throwable $exception
    ): void;

    public function removeContext(string ...$keys): void;

    public function verbose(string $message, ?array $context = null, ?\Throwable $exception = null): void;

    public function warning(string $message, ?array $context = null, ?\Throwable $exception = null): void;
}
